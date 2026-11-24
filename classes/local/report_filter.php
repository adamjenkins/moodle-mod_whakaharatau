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

use context;
use moodle_url;

/**
 * The report filters: learner, cohort and dates. Read from the URL, so a filtered report can be bookmarked.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_filter {
    /** @var string a user id, or part of a name */
    public $learner = '';
    /** @var int cohort id, 0 = any */
    public $cohortid = 0;
    /** @var int first day, as a timestamp, 0 = no limit */
    public $from = 0;
    /** @var int last day (included), as a timestamp, 0 = no limit */
    public $to = 0;

    /**
     * Read the filters of this request.
     *
     * @param context $context context of the report, for the cohorts the viewer may see
     * @param bool $withlearner false leaves the learner filter out (anonymised reports)
     * @return self
     */
    public static function from_request(context $context, bool $withlearner = true): self {
        $filter = new self();
        $filter->learner = $withlearner ? trim(optional_param('learner', '', PARAM_TEXT)) : '';
        $cohortid = optional_param('cohort', 0, PARAM_INT);
        $filter->cohortid = isset(self::get_cohorts($context)[$cohortid]) ? $cohortid : 0;
        $filter->from = max(0, optional_param('from', 0, PARAM_INT));
        $filter->to = max(0, optional_param('to', 0, PARAM_INT));
        return $filter;
    }

    /**
     * Cohorts the viewer may filter by: every cohort of this context and its parents that they can see.
     *
     * @param context $context context of the report
     * @return string[] id => plain-text name
     */
    public static function get_cohorts(context $context): array {
        global $DB;
        $contextids = array_merge([$context->id], $context->get_parent_context_ids());
        $cohorts = [];
        foreach ($contextids as $contextid) {
            $cohortcontext = context::instance_by_id($contextid);
            $params = ['contextid' => $contextid];
            if (!has_capability('moodle/cohort:view', $cohortcontext)) {
                $params['visible'] = 1;
            }
            foreach ($DB->get_records('cohort', $params, 'name', 'id, name') as $cohort) {
                $cohorts[(int) $cohort->id] = format_string($cohort->name, true, ['context' => $cohortcontext,
                    'escape' => false]);
            }
        }
        \core_collator::asort($cohorts);
        return $cohorts;
    }

    /**
     * URL parameters.
     *
     * @return array
     */
    public function get_params(): array {
        return array_filter(['learner' => $this->learner, 'cohort' => $this->cohortid, 'from' => $this->from,
            'to' => $this->to], fn($value) => $value !== '' && $value !== 0);
    }

    /**
     * Whether any filter is set.
     *
     * @return bool
     */
    public function is_active(): bool {
        return (bool) $this->get_params();
    }

    /**
     * Whether a time is inside the dates. Days are the viewer's own.
     *
     * @param int $time timestamp
     * @return bool
     */
    public function in_range(int $time): bool {
        if ($this->from && $time < $this->from) {
            return false;
        }
        if ($this->to) {
            // The last day is included; a day may be 23 or 25 hours long.
            $end = (new \DateTimeImmutable('@' . $this->to))->setTimezone(\core_date::get_user_timezone_object())
                ->modify('+1 day')->getTimestamp();
            return $time < $end;
        }
        return true;
    }

    /**
     * SQL conditions on a user.
     *
     * @param string $alias alias of the user table
     * @return array [SQL starting with AND (or empty), params]
     */
    public function get_user_sql(string $alias): array {
        global $DB;
        $sql = '';
        $params = [];
        if ($this->learner !== '') {
            if (ctype_digit($this->learner)) {
                $sql .= " AND $alias.id = :filteruserid";
                $params['filteruserid'] = (int) $this->learner;
            } else {
                $fullname = $DB->sql_fullname("$alias.firstname", "$alias.lastname");
                $sql .= ' AND ' . $DB->sql_like($fullname, ':filtername', false, false);
                $params['filtername'] = '%' . $DB->sql_like_escape($this->learner) . '%';
            }
        }
        if ($this->cohortid) {
            $sql .= " AND EXISTS (SELECT 1
                                    FROM {cohort_members} fcm
                                   WHERE fcm.userid = $alias.id AND fcm.cohortid = :filtercohort)";
            $params['filtercohort'] = $this->cohortid;
        }
        return [$sql, $params];
    }
}
