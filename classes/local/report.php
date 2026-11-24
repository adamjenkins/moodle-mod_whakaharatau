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

namespace mod_whakaharatau\local;

use cm_info;
use context_module;
use stdClass;

/**
 * The attempts of the teacher report and deleting them, limited to the groups the viewer may see. The other views are
 * in activity_tables.
 *
 * Callers check mod/whakaharatau:viewreports (and mod/whakaharatau:deleteattempts before delete_attempts()).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var string report view: one row per attempt or session */
    const VIEW_ATTEMPTS = 'attempts';
    /** @var string report view: one row per question */
    const VIEW_SLIDES = 'slides';
    /** @var string report view: one row per learner */
    const VIEW_LEARNERS = 'learners';
    /** @var string report view: one row per answer */
    const VIEW_ANSWERS = 'answers';
    /** @var string report view: versions of changed questions */
    const VIEW_VERSIONS = 'versions';

    /** @var stdClass the whakaharatau record */
    protected $instance;
    /** @var cm_info the course module */
    protected $cm;
    /** @var context_module the module context */
    protected $context;
    /** @var int instance_settings::MODE_* of the attempts shown */
    protected $attemptmode;
    /** @var int group to show, 0 = all participants */
    protected $groupid;
    /** @var bool false when the viewer may see no one (separate groups, no group, no accessallgroups) */
    protected $canseeany;

    /**
     * Constructor.
     *
     * @param stdClass $instance whakaharatau record
     * @param cm_info $cm course module
     * @param context_module $context module context
     * @param int $attemptmode instance_settings::MODE_PRACTICE or MODE_TEST: which attempts to show
     * @param int|false $groupid groups_get_activity_group() result: false = no groups, 0 = all, else a group id
     */
    public function __construct(stdClass $instance, cm_info $cm, context_module $context, int $attemptmode, $groupid) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->context = $context;
        $this->attemptmode = $attemptmode === instance_settings::MODE_TEST ? instance_settings::MODE_TEST :
            instance_settings::MODE_PRACTICE;
        $this->groupid = (int) $groupid;
        $this->canseeany = true;
        if (
            $groupid !== false && !$this->groupid
                && groups_get_activity_groupmode($cm) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context)
        ) {
            $this->canseeany = false;
        }
    }

    /**
     * Which attempts are shown.
     *
     * @return int instance_settings::MODE_*
     */
    public function get_attemptmode(): int {
        return $this->attemptmode;
    }

    /**
     * Groups the report is limited to.
     *
     * @return int[]|null null = everyone, empty = nobody
     */
    public function get_groupids(): ?array {
        if (!$this->canseeany) {
            return [];
        }
        return $this->groupid ? [$this->groupid] : null;
    }

    /**
     * Whether the viewer may see any learner.
     *
     * @return bool
     */
    public function can_see_any(): bool {
        return $this->canseeany;
    }

    /**
     * SQL that limits attempts (alias a) to the selected group.
     *
     * @return array [join SQL, params]
     */
    protected function group_join(): array {
        if (!$this->groupid) {
            return ['', []];
        }
        return ['JOIN {groups_members} gm ON gm.userid = a.userid AND gm.groupid = :groupid',
            ['groupid' => $this->groupid]];
    }

    /**
     * Whether a user is inside the report's scope (group and visibility).
     *
     * @param int $userid user id
     * @return bool
     */
    public function user_in_scope(int $userid): bool {
        if (!$this->canseeany) {
            return false;
        }
        return !$this->groupid || groups_is_member($this->groupid, $userid);
    }

    /**
     * Attempts (Test) or sessions (Practice) with their learners and response statistics.
     *
     * @return stdClass[] keyed by attempt id, ordered by learner name then attempt number; each has id, userid,
     *      mode, attempt, state, round, score, maxscore, beststreak, timestart, timefinish, timemodified, the
     *      user's name fields, rounds (rounds with an answer), slidesanswered (slide appearances answered),
     *      firsttry (appearances right first time), choices (all choices made), grade (Test: the user's
     *      aggregated grade out of the maximum grade, or null)
     */
    public function get_attempts(): array {
        global $DB;
        if (!$this->canseeany) {
            return [];
        }
        $userfields = \core_user\fields::for_name()->get_sql('u', true);
        [$groupjoin, $groupparams] = $this->group_join();
        $sql = "SELECT a.id, a.userid, a.mode, a.attempt, a.state, a.round, a.score, a.maxscore, a.beststreak,
                       a.timestart, a.timefinish, a.timemodified {$userfields->selects}
                  FROM {whakaharatau_attempt} a
                  JOIN {user} u ON u.id = a.userid
                       {$userfields->joins}
                       $groupjoin
                 WHERE a.whakaharatauid = :wid AND a.mode = :mode AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC, a.userid ASC, a.attempt ASC";
        $params = ['wid' => $this->instance->id, 'mode' => $this->attemptmode] + $groupparams + $userfields->params;
        $attempts = $DB->get_records_sql($sql, $params);
        if (!$attempts) {
            return [];
        }

        // Response statistics per attempt: one inner row per slide appearance (attempt, round, slide).
        [$groupjoin, $groupparams] = $this->group_join();
        $sql = "SELECT x.attemptid, COUNT(1) AS slidesanswered, SUM(x.firsttry) AS firsttry, SUM(x.choices) AS choices
                  FROM (SELECT r.attemptid, r.round, r.slideid,
                               MAX(CASE WHEN r.tries = 1 AND r.correct = 1 THEN 1 ELSE 0 END) AS firsttry,
                               COUNT(1) AS choices
                          FROM {whakaharatau_response} r
                          JOIN {whakaharatau_attempt} a ON a.id = r.attemptid
                               $groupjoin
                         WHERE a.whakaharatauid = :wid AND a.mode = :mode
                      GROUP BY r.attemptid, r.round, r.slideid) x
              GROUP BY x.attemptid";
        $stats = $DB->get_records_sql($sql, ['wid' => $this->instance->id, 'mode' => $this->attemptmode] + $groupparams);
        $sql = "SELECT r.attemptid, COUNT(DISTINCT r.round) AS rounds
                  FROM {whakaharatau_response} r
                  JOIN {whakaharatau_attempt} a ON a.id = r.attemptid
                       $groupjoin
                 WHERE a.whakaharatauid = :wid AND a.mode = :mode
              GROUP BY r.attemptid";
        $rounds = $DB->get_records_sql_menu($sql, ['wid' => $this->instance->id, 'mode' => $this->attemptmode] +
            $groupparams);

        $grades = [];
        if ($this->attemptmode === instance_settings::MODE_TEST) {
            $grades = attempt::get_user_grades($this->instance);
        }
        foreach ($attempts as $id => $row) {
            $stat = $stats[$id] ?? null;
            $row->rounds = (int) ($rounds[$id] ?? 0);
            $row->slidesanswered = $stat ? (int) $stat->slidesanswered : 0;
            $row->firsttry = $stat ? (int) $stat->firsttry : 0;
            $row->choices = $stat ? (int) $stat->choices : 0;
            $row->grade = isset($grades[(int) $row->userid]) ? (float) $grades[(int) $row->userid]->rawgrade : null;
        }
        return $attempts;
    }

    /**
     * Column keys and headings of the attempts view (also the download columns).
     *
     * @return string[] key => plain-text heading
     */
    public function get_attempt_columns(): array {
        $str = fn(string $key) => get_string($key, 'mod_whakaharatau');
        if ($this->attemptmode === instance_settings::MODE_TEST) {
            return [
                'fullname' => $str('report_learner'),
                'attempt' => $str('report_attempt'),
                'state' => $str('report_state'),
                'timestart' => $str('report_started'),
                'timefinish' => $str('report_timefinish'),
                'score' => $str('report_score'),
                'percent' => $str('report_percent'),
                'grade' => $str('report_grade'),
            ];
        }
        return [
            'fullname' => $str('report_learner'),
            'attempt' => $str('report_session'),
            'state' => $str('report_state'),
            'timestart' => $str('report_started'),
            'timemodified' => $str('report_lastactivity'),
            'rounds' => $str('report_rounds'),
            'slidesanswered' => $str('report_slidesanswered'),
            'firsttry' => $str('report_firsttry'),
            'firsttryrate' => $str('report_firsttryrate'),
            'beststreak' => $str('report_beststreak'),
        ];
    }

    /**
     * One attempt as plain-text cell values keyed like get_attempt_columns() (not escaped: escape at the sink).
     *
     * @param stdClass $row a get_attempts() row
     * @return string[]
     */
    public function format_attempt_row(stdClass $row): array {
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        $finished = $row->state === attempt::STATE_FINISHED;
        $cells = [
            'fullname' => fullname($row, has_capability('moodle/site:viewfullnames', $this->context)),
            'attempt' => (string) (int) $row->attempt,
            'state' => get_string($finished ? 'statefinished' : 'stateinprogress', 'mod_whakaharatau'),
            'timestart' => $row->timestart ? userdate($row->timestart, $dateformat) : '',
        ];
        if ($this->attemptmode === instance_settings::MODE_TEST) {
            $hasscore = $finished && $row->maxscore !== null && (float) $row->maxscore > 0;
            $maxgrade = (int) $this->instance->grade;
            $cells['timefinish'] = $row->timefinish ? userdate($row->timefinish, $dateformat) : '';
            $cells['score'] = $hasscore ? get_string('report_scoreof', 'mod_whakaharatau', (object) [
                'score' => format_float((float) $row->score, 2, true, true),
                'max' => format_float((float) $row->maxscore, 2, true, true),
            ]) : '';
            $cells['percent'] = $hasscore ?
                format_float((float) $row->score / (float) $row->maxscore * 100, 1, true, true) . '%' : '';
            $cells['grade'] = ($row->grade !== null && $maxgrade > 0) ?
                get_string('report_scoreof', 'mod_whakaharatau', (object) [
                    'score' => format_float((float) $row->grade, 2, true, true),
                    'max' => $maxgrade,
                ]) : '';
            return $cells;
        }
        $cells['timemodified'] = $row->timemodified ? userdate($row->timemodified, $dateformat) : '';
        $cells['rounds'] = (string) (int) $row->rounds;
        $cells['slidesanswered'] = (string) (int) $row->slidesanswered;
        $cells['firsttry'] = (string) (int) $row->firsttry;
        $cells['firsttryrate'] = $row->slidesanswered ?
            format_float($row->firsttry / $row->slidesanswered * 100, 1, true, true) . '%' : '';
        $cells['beststreak'] = (string) (int) $row->beststreak;
        return $cells;
    }

    /**
     * Delete attempts of this activity whose learners are in scope. Updates the gradebook and completion of
     * the learners concerned and logs an attempt_deleted event for each attempt.
     *
     * @param int[] $attemptids attempt ids (others' and out-of-scope ids are ignored)
     * @return int number of attempts deleted
     */
    public function delete_attempts(array $attemptids): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $attemptids = array_unique(array_filter(array_map('intval', $attemptids)));
        if (!$attemptids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
        $params['wid'] = $this->instance->id;
        $records = $DB->get_records_select('whakaharatau_attempt', "id $insql AND whakaharatauid = :wid", $params);

        $users = [];
        $deleted = 0;
        foreach ($records as $record) {
            if (!$this->user_in_scope((int) $record->userid)) {
                continue;
            }
            attempt::delete_attempt((int) $record->id);
            $event = \mod_whakaharatau\event\attempt_deleted::create([
                'objectid' => (int) $record->id,
                'context' => $this->context,
                'relateduserid' => (int) $record->userid,
                'other' => ['whakaharatauid' => (int) $this->instance->id, 'mode' => (int) $record->mode],
            ]);
            $event->add_record_snapshot('whakaharatau_attempt', $record);
            $event->trigger();
            $users[(int) $record->userid] = true;
            $deleted++;
        }

        if ($users) {
            $instance = clone($this->instance);
            $instance->cmidnumber = $this->cm->idnumber;
            $course = get_course($this->cm->course);
            $completion = new \completion_info($course);
            $trackcompletion = $completion->is_enabled($this->cm) == COMPLETION_TRACKING_AUTOMATIC;
            foreach (array_keys($users) as $userid) {
                if (instance_settings::is_test($instance)) {
                    whakaharatau_update_grades($instance, $userid);
                }
                if ($trackcompletion) {
                    $completion->update_state($this->cm, COMPLETION_UNKNOWN, $userid);
                }
            }
        }
        return $deleted;
    }
}
