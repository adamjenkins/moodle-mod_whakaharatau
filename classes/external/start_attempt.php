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

namespace mod_whakaharatau\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_whakaharatau\local\attempt;

/**
 * Start a new attempt or session, or resume the unfinished one.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_attempt extends player_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'forcenew' => new external_value(
                PARAM_BOOL,
                'Finish any unfinished attempt and start a new one',
                VALUE_DEFAULT,
                false
            ),
            'missedfrom' => new external_value(
                PARAM_INT,
                'Practice: start with the slides missed in this finished session',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Start or resume.
     *
     * @param int $cmid course module id
     * @param bool $forcenew start a new attempt even if one is unfinished
     * @param int $missedfrom id of a finished Practice session of the user, or 0
     * @return array state and slides (the current slide and the next one)
     */
    public static function execute(int $cmid, bool $forcenew = false, int $missedfrom = 0): array {
        global $CFG, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'forcenew' => $forcenew, 'missedfrom' => $missedfrom]
        );
        [$cm, $instance, $context] = self::setup_cm($params['cmid']);
        self::require_can_attempt($context);

        // The player also runs inline on the course page, where view.php is never loaded: playing counts as viewing.
        require_once($CFG->libdir . '/completionlib.php');
        $course = get_course($cm->course);
        $event = \mod_whakaharatau\event\course_module_viewed::create([
            'objectid' => $instance->id,
            'context' => $context,
        ]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('whakaharatau', $instance);
        $event->trigger();
        (new \completion_info($course))->set_module_viewed($cm);

        // Two starts at once (two tabs) would both take MAX(attempt) + 1 and break the unique attempt index.
        $lock = \core\lock\lock_config::get_lock_factory('mod_whakaharatau')
            ->get_lock('start' . (int) $instance->id . '-' . (int) $USER->id, 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $attempt = attempt::start(
                $instance,
                $cm,
                (int) $USER->id,
                (bool) $params['forcenew'],
                (int) $params['missedfrom']
            );
        } finally {
            $lock->release();
        }
        return [
            'state' => $attempt->get_state(),
            'slides' => self::slides_payload($attempt),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => self::state_structure(),
            'slides' => self::slides_structure(),
        ]);
    }
}
