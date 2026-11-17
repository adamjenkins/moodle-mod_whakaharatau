<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_whakaharatau\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;

/**
 * Privacy provider: Test attempts, Practice sessions and their responses are personal data.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('whakaharatau_attempt', [
            'userid' => 'privacy:metadata:attempt:userid',
            'mode' => 'privacy:metadata:attempt:mode',
            'attempt' => 'privacy:metadata:attempt:attempt',
            'state' => 'privacy:metadata:attempt:state',
            'round' => 'privacy:metadata:attempt:round',
            'slideorder' => 'privacy:metadata:attempt:slideorder',
            'optionorder' => 'privacy:metadata:attempt:optionorder',
            'currentslide' => 'privacy:metadata:attempt:currentslide',
            'score' => 'privacy:metadata:attempt:score',
            'maxscore' => 'privacy:metadata:attempt:maxscore',
            'currentstreak' => 'privacy:metadata:attempt:currentstreak',
            'beststreak' => 'privacy:metadata:attempt:beststreak',
            'timestart' => 'privacy:metadata:attempt:timestart',
            'timefinish' => 'privacy:metadata:attempt:timefinish',
            'timemodified' => 'privacy:metadata:attempt:timemodified',
        ], 'privacy:metadata:attempt');

        $collection->add_database_table('whakaharatau_response', [
            'attemptid' => 'privacy:metadata:response:attemptid',
            'round' => 'privacy:metadata:response:round',
            'slideid' => 'privacy:metadata:response:slideid',
            'optionid' => 'privacy:metadata:response:optionid',
            'correct' => 'privacy:metadata:response:correct',
            'tries' => 'privacy:metadata:response:tries',
            'timeanswered' => 'privacy:metadata:response:timeanswered',
        ], 'privacy:metadata:response');

        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');

        return $collection;
    }

    /**
     * Contexts in which the user has attempts.
     *
     * @param int $userid user id
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {whakaharatau_attempt} a ON a.whakaharatauid = cm.instance
                 WHERE a.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'whakaharatau',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users who have attempts in a context.
     *
     * @param userlist $userlist the userlist to fill
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {whakaharatau_attempt} a ON a.whakaharatauid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'whakaharatau', 'cmid' => $context->instanceid]);
    }

    /**
     * Export the user's attempts and responses.
     *
     * @param approved_contextlist $contextlist approved contexts
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $instanceid = self::get_instanceid($context);
            if (!$instanceid) {
                continue;
            }
            $slidenumbers = self::get_slide_numbers($instanceid);
            $optionnumbers = self::get_option_numbers($instanceid);

            $exported = [];
            $attempts = $DB->get_records(
                'whakaharatau_attempt',
                ['whakaharatauid' => $instanceid, 'userid' => $userid],
                'attempt ASC'
            );
            foreach ($attempts as $record) {
                $responses = [];
                $rows = $DB->get_records('whakaharatau_response', ['attemptid' => $record->id], 'id ASC');
                foreach ($rows as $row) {
                    $responses[] = (object) [
                        'round' => (int) $row->round,
                        'slide' => $slidenumbers[(int) $row->slideid] ?? null,
                        'option' => $optionnumbers[(int) $row->optionid] ?? null,
                        'correct' => transform::yesno($row->correct),
                        'try' => (int) $row->tries,
                        'timeanswered' => transform::datetime($row->timeanswered),
                    ];
                }
                $exported[] = (object) [
                    'attempt' => (int) $record->attempt,
                    'mode' => (int) $record->mode === instance_settings::MODE_TEST ?
                        get_string('modetest', 'mod_whakaharatau') : get_string('modepractice', 'mod_whakaharatau'),
                    'state' => $record->state === attempt::STATE_FINISHED ?
                        get_string('statefinished', 'mod_whakaharatau') :
                        get_string('stateinprogress', 'mod_whakaharatau'),
                    'round' => (int) $record->round,
                    'slideorder' => array_values(array_filter(array_map(
                        fn($id) => $slidenumbers[(int) $id] ?? null,
                        explode(',', (string) $record->slideorder)
                    ))),
                    'score' => $record->score === null ? null : (float) $record->score,
                    'maxscore' => $record->maxscore === null ? null : (float) $record->maxscore,
                    'beststreak' => (int) $record->beststreak,
                    'timestart' => transform::datetime($record->timestart),
                    'timefinish' => $record->timefinish ? transform::datetime($record->timefinish) : null,
                    'responses' => $responses,
                ];
            }
            if (!$exported) {
                continue;
            }
            $data = helper::get_context_data($context, $contextlist->get_user());
            helper::export_context_files($context, $contextlist->get_user());
            writer::with_context($context)->export_data([], $data);
            writer::with_context($context)->export_data(
                [get_string('privacy:attempts', 'mod_whakaharatau')],
                (object) ['attempts' => $exported]
            );
        }
    }

    /**
     * Delete every user's attempts in a context.
     *
     * @param \context $context the context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        $instanceid = self::get_instanceid($context);
        if ($instanceid) {
            attempt::delete_all_attempts($instanceid);
        }
    }

    /**
     * Delete one user's attempts in the approved contexts.
     *
     * @param approved_contextlist $contextlist approved contexts
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $instanceid = self::get_instanceid($context);
            if ($instanceid) {
                attempt::delete_all_attempts($instanceid, $userid);
            }
        }
    }

    /**
     * Delete the attempts of several users in one context.
     *
     * @param approved_userlist $userlist approved users
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $instanceid = self::get_instanceid($userlist->get_context());
        if (!$instanceid) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            attempt::delete_all_attempts($instanceid, (int) $userid);
        }
    }

    /**
     * The activity instance id of a module context of this module, or 0.
     *
     * @param \context $context the context
     * @return int
     */
    protected static function get_instanceid(\context $context): int {
        if (!$context instanceof \context_module) {
            return 0;
        }
        $cm = get_coursemodule_from_id('whakaharatau', $context->instanceid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : 0;
    }

    /**
     * Slide numbers (1-based, teacher order) by slide id, so the export names slides as the teacher sees them.
     *
     * @param int $instanceid instance id
     * @return int[] slideid => number
     */
    protected static function get_slide_numbers(int $instanceid): array {
        global $DB;
        $ids = $DB->get_fieldset_sql(
            'SELECT id FROM {whakaharatau_slide} WHERE whakaharatauid = ? ORDER BY sortorder, id',
            [$instanceid]
        );
        $numbers = [];
        foreach (array_values($ids) as $i => $id) {
            $numbers[(int) $id] = $i + 1;
        }
        return $numbers;
    }

    /**
     * Option numbers (1-based, teacher order within each slide) by option id.
     *
     * @param int $instanceid instance id
     * @return int[] optionid => number
     */
    protected static function get_option_numbers(int $instanceid): array {
        global $DB;
        $sql = "SELECT o.id, o.slideid
                  FROM {whakaharatau_option} o
                  JOIN {whakaharatau_slide} s ON s.id = o.slideid
                 WHERE s.whakaharatauid = ?
              ORDER BY o.slideid, o.sortorder, o.id";
        $numbers = [];
        $counter = [];
        foreach ($DB->get_records_sql($sql, [$instanceid]) as $row) {
            $counter[(int) $row->slideid] = ($counter[(int) $row->slideid] ?? 0) + 1;
            $numbers[(int) $row->id] = $counter[(int) $row->slideid];
        }
        return $numbers;
    }
}
