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
use context_module;
use moodle_url;
use stdClass;

/**
 * Reports across the activities of a course, a category or the site: totals by activity, course, programme level, cohort
 * or time, each learner's progress, and a comparison of two activities (before and after a lesson).
 *
 * Test results only, and each learner's first finished attempt in each activity.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cross_report {
    /** @var string view: totals */
    const VIEW_SUMMARY = 'summary';
    /** @var string view: one row per learner */
    const VIEW_PROGRESS = 'progress';
    /** @var string view: two activities compared */
    const VIEW_PREPOST = 'prepost';
    /** @var string view: totals without small results */
    const VIEW_ANONYMISED = 'anonymised';
    /** @var string[] what the totals can be grouped by => string key */
    const GROUPINGS = [
        'activity' => 'modulename',
        'course' => 'crossreport_course',
        'category' => 'crossreport_category',
        'cohort' => 'report_cohort',
        'month' => 'crossreport_month',
        'quarter' => 'crossreport_quarter',
    ];

    /** @var context course, category or system context */
    protected $context;
    /** @var report_filter filters */
    protected $filter;
    /** @var stdClass[] activities, see load_activities() */
    protected $activities;

    /**
     * Constructor.
     *
     * @param context $context course, category or system context
     * @param report_filter $filter filters
     * @param bool $anonymised include the activities whose anonymised results the viewer may see, instead of those
     *      whose reports they may see
     */
    public function __construct(context $context, report_filter $filter, bool $anonymised = false) {
        $this->context = $context;
        $this->filter = $filter;
        $this->activities = self::load_activities(
            $context,
            $anonymised ? 'mod/whakaharatau:exportanonymised' : 'mod/whakaharatau:viewreports'
        );
    }

    /**
     * Whether the viewer may open the report in a context.
     *
     * @param context $context course, category or system context
     * @return bool
     */
    public static function can_view(context $context): bool {
        return has_any_capability(['mod/whakaharatau:viewcrossreports', 'mod/whakaharatau:exportanonymised'], $context);
    }

    /**
     * The activities in a context that the viewer has a capability in.
     *
     * @param context $context course, category or system context
     * @param string $capability capability needed in each activity
     * @return stdClass[] whakaharatau records by id, with groupids, activityname, coursename, categoryid, categoryname
     *      and cmid
     */
    protected static function load_activities(context $context, string $capability): array {
        global $DB;
        $like = $DB->sql_like('ctx.path', ':path');
        $sql = "SELECT w.*, cm.id AS cmid, cm.visible AS cmvisible, cm.groupmode, cm.groupingid, c.fullname AS coursename,
                       c.category AS categoryid, c.groupmode AS coursegroupmode, c.groupmodeforce, cat.name AS categoryname
                  FROM {whakaharatau} w
                  JOIN {course_modules} cm ON cm.instance = w.id AND cm.deletioninprogress = 0
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'whakaharatau'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :level
                  JOIN {course} c ON c.id = w.course
             LEFT JOIN {course_categories} cat ON cat.id = c.category
                 WHERE $like
              ORDER BY c.sortorder, w.name, w.id";
        $activities = [];
        $records = $DB->get_records_sql($sql, ['level' => CONTEXT_MODULE,
            'path' => $DB->sql_like_escape($context->path) . '/%']);
        foreach ($records as $record) {
            $modcontext = context_module::instance($record->cmid);
            if (
                !has_capability($capability, $modcontext)
                    || (!$record->cmvisible && !has_capability('moodle/course:viewhiddenactivities', $modcontext))
            ) {
                continue;
            }
            $groupmode = (int) ($record->groupmodeforce ? $record->coursegroupmode : $record->groupmode);
            $record->groupids = null;
            if ($groupmode === SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $modcontext)) {
                $cm = (object) ['id' => $record->cmid, 'course' => $record->course, 'groupmode' => $record->groupmode,
                    'groupingid' => $record->groupingid];
                $record->groupids = array_map('intval', array_keys(groups_get_activity_allowed_groups($cm) ?: []));
            }
            $options = ['context' => $modcontext, 'escape' => false];
            $record->activityname = format_string($record->name, true, $options);
            $record->coursename = format_string($record->coursename, true, $options);
            $record->categoryname = format_string((string) $record->categoryname, true, $options);
            $activities[(int) $record->id] = $record;
        }
        return $activities;
    }

    /**
     * The activities in the report.
     *
     * @return stdClass[] see load_activities()
     */
    public function get_activities(): array {
        return $this->activities;
    }

    /**
     * A string of this plugin.
     *
     * @param string $key key
     * @param mixed $a placeholder
     * @return string
     */
    protected static function str(string $key, $a = null): string {
        return get_string($key, 'mod_whakaharatau', $a);
    }

    /**
     * The Test data with some filters.
     *
     * @param report_filter|null $filter filters, default this report's
     * @param int[]|null $ids only these activities
     * @return report_data
     */
    protected function get_data(?report_filter $filter = null, ?array $ids = null): report_data {
        $activities = $ids === null ? $this->activities : array_intersect_key($this->activities, array_flip($ids));
        return new report_data($activities, instance_settings::MODE_TEST, $filter ?? $this->filter);
    }

    /**
     * Whether full names are shown.
     *
     * @return bool
     */
    protected function fullnames(): bool {
        return has_capability('moodle/site:viewfullnames', $this->context);
    }

    // Totals.

    /**
     * Totals of the first finished attempts, by group.
     *
     * @param string $grouping GROUPINGS key
     * @param report_filter $filter filters
     * @param int[]|null $ids only these activities
     * @return stdClass[] by group key: label, sort, learners, activities, attempts, percent, firsttry, answered
     */
    protected function get_groups(string $grouping, report_filter $filter, ?array $ids = null): array {
        $data = $this->get_data($filter, $ids);
        $attempts = $data->get_first_attempts();
        $firsttry = [];
        foreach ($data->get_answers($attempts) as $answer) {
            $entry = $firsttry[$answer->attemptid] ?? [0, 0];
            $firsttry[$answer->attemptid] = [$entry[0] + ($answer->firsttry ? 1 : 0), $entry[1] + 1];
        }
        $cohorts = $grouping === 'cohort' ? $this->get_user_cohorts() : [];
        $groups = [];
        foreach ($attempts as $id => $attempt) {
            foreach ($this->get_group_keys($grouping, $attempt, $cohorts) as $key => [$label, $sort]) {
                $group = $groups[$key] ?? ($groups[$key] = (object) ['label' => $label, 'sort' => $sort, 'users' => [],
                    'activities' => [], 'attempts' => 0, 'sum' => 0.0, 'scored' => 0, 'firsttry' => 0, 'answered' => 0]);
                $group->users[(int) $attempt->userid] = true;
                $group->activities[(int) $attempt->whakaharatauid] = true;
                $group->attempts++;
                $percent = report_data::percent_of($attempt);
                if ($percent !== null) {
                    $group->sum += $percent;
                    $group->scored++;
                }
                $group->firsttry += $firsttry[$id][0] ?? 0;
                $group->answered += $firsttry[$id][1] ?? 0;
            }
        }
        return $groups;
    }

    /**
     * The groups an attempt belongs to.
     *
     * @param string $grouping GROUPINGS key
     * @param stdClass $attempt attempt
     * @param array $cohorts user id => [cohort id => name]
     * @return array key => [label, sort value]
     */
    protected function get_group_keys(string $grouping, stdClass $attempt, array $cohorts): array {
        $activity = $this->activities[(int) $attempt->whakaharatauid];
        $time = (int) $attempt->timefinish;
        switch ($grouping) {
            case 'course':
                return ['c' . $activity->course => [$activity->coursename, null]];
            case 'category':
                return ['g' . $activity->categoryid => [$activity->categoryname, null]];
            case 'cohort':
                $keys = [];
                foreach ($cohorts[(int) $attempt->userid] ?? [] as $id => $name) {
                    $keys['h' . $id] = [$name, null];
                }
                return $keys ?: ['h0' => [self::str('crossreport_nocohort'), null]];
            case 'month':
                $sort = (int) userdate($time, '%Y%m', 99, false);
                return ['m' . $sort => [userdate($time, get_string('strftimemonthyear', 'langconfig')), $sort]];
            case 'quarter':
                $year = (int) userdate($time, '%Y', 99, false);
                $quarter = (int) ceil((int) userdate($time, '%m', 99, false) / 3);
                return ["q$year$quarter" => [self::str('crossreport_quarterof', (object) ['quarter' => $quarter,
                    'year' => $year]), $year * 10 + $quarter]];
            default:
                return ['a' . $activity->id => [$activity->activityname, null]];
        }
    }

    /**
     * The cohorts, of those the viewer may see, that each user is in now.
     *
     * @return array user id => [cohort id => name]
     */
    protected function get_user_cohorts(): array {
        global $DB;
        $names = report_filter::get_cohorts($this->context);
        if (!$names) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($names));
        $users = [];
        $recordset = $DB->get_recordset_select('cohort_members', "cohortid $insql", $params, '', 'id, cohortid, userid');
        foreach ($recordset as $record) {
            $users[(int) $record->userid][(int) $record->cohortid] = $names[(int) $record->cohortid];
        }
        $recordset->close();
        return $users;
    }

    /**
     * Totals table.
     *
     * @param string $grouping GROUPINGS key
     * @param bool $anonymised hide results from too few learners and publish only bands and rounded percentages
     * @return report_table
     */
    public function summary(string $grouping, bool $anonymised = false): report_table {
        $table = new report_table(self::str($anonymised ? 'report_anonymised' : 'crossreport_viewsummary'));
        $table->column('label', self::str(self::GROUPINGS[$grouping]));
        if ($grouping === 'activity') {
            $table->column('course', self::str('crossreport_course'))
                ->column('category', self::str('crossreport_category'));
        }
        $table->column('learners', self::str('report_learners'), true);
        if (!$anonymised) {
            $table->column('activities', self::str('modulenameplural'), true)
                ->column('attempts', self::str('crossreport_firstattempts'), true);
        }
        $table->column('percent', self::str('crossreport_average'), true)
            ->column('firsttry', self::str('report_firsttryrate'), true);
        // An activity with too few learners is in no anonymised total, so it cannot be subtracted from any.
        $ids = $anonymised ? $this->get_large_activities() : null;
        $groups = $this->get_groups($grouping, $this->filter, $ids);
        $all = $anonymised && $this->filter->is_active() ? $this->get_groups($grouping, new report_filter(), $ids) : $groups;
        $cutsacross = $this->filter->is_active() || in_array($grouping, ['cohort', 'month', 'quarter'], true);
        if ($anonymised && $grouping === 'activity') {
            foreach ($this->get_groups($grouping, new report_filter()) as $key => $group) {
                if (!isset($all[$key])) {
                    $all[$key] = $group;
                }
            }
        }
        foreach ($all as $key => $complete) {
            $group = $groups[$key] ?? null;
            if (!$group && !$anonymised) {
                continue;
            }
            $cells = ['label' => report_table::cell($complete->label, $complete->sort)];
            if ($grouping === 'activity') {
                $activity = $this->activities[(int) array_key_first($complete->activities)];
                $cells['course'] = report_table::cell($activity->coursename);
                $cells['category'] = report_table::cell($activity->categoryname);
            }
            $learners = $group ? count($group->users) : 0;
            if ($anonymised && report_data::is_hidden($learners, count($complete->users), $cutsacross)) {
                $cells['learners'] = report_table::cell(
                    self::str('report_toofew', report_data::get_minimum($cutsacross)),
                    ''
                );
            } else if ($anonymised) {
                $cells += [
                    'learners' => report_data::anonymous_learners($learners),
                    'percent' => report_data::anonymous_percent(
                        $group->scored ? $group->sum / $group->scored : null,
                        $learners
                    ),
                    'firsttry' => report_data::anonymous_percent(
                        $group->answered ? $group->firsttry / $group->answered * 100 : null,
                        $learners
                    ),
                ];
            } else {
                $cells += [
                    'learners' => report_table::cell($learners),
                    'activities' => report_table::cell(count($group->activities)),
                    'attempts' => report_table::cell($group->attempts),
                    'percent' => report_table::percent($group->scored ? $group->sum / $group->scored : null, true),
                    'firsttry' => report_table::percent($group->answered ?
                        $group->firsttry / $group->answered * 100 : null, true),
                ];
            }
            $table->add_row($cells);
        }
        return $table->sort_by('label', false);
    }

    /**
     * The activities that have enough learners for anonymised results.
     *
     * @return int[] activity ids
     */
    protected function get_large_activities(): array {
        $users = [];
        foreach ($this->get_data(new report_filter())->get_first_attempts() as $attempt) {
            $users[(int) $attempt->whakaharatauid][(int) $attempt->userid] = true;
        }
        $min = report_data::get_minimum(false);
        return array_keys(array_filter($users, fn($list) => count($list) >= $min));
    }

    // Progress.

    /**
     * Each learner's earliest and latest activity.
     *
     * @return report_table
     */
    public function progress(): report_table {
        $table = new report_table(self::str('crossreport_viewprogress'));
        $table->column('fullname', self::str('report_learner'))
            ->column('userid', self::str('report_userid'), true)
            ->column('activities', self::str('modulenameplural'), true)
            ->column('average', self::str('crossreport_average'), true)
            ->column('firstactivity', self::str('crossreport_earliest'))
            ->column('firsttime', self::str('crossreport_earliestdate'))
            ->column('firstpercent', self::str('crossreport_earliestscore'), true)
            ->column('lastactivity', self::str('crossreport_latest'))
            ->column('lasttime', self::str('crossreport_latestdate'))
            ->column('lastpercent', self::str('crossreport_latestscore'), true)
            ->column('change', self::str('crossreport_change'), true);
        $byuser = [];
        foreach ($this->get_data()->get_first_attempts() as $attempt) {
            if (report_data::percent_of($attempt) !== null) {
                $byuser[(int) $attempt->userid][] = $attempt;
            }
        }
        foreach ($byuser as $userid => $attempts) {
            usort($attempts, fn($a, $b) => [(int) $a->timefinish, (int) $a->id] <=> [(int) $b->timefinish, (int) $b->id]);
            $first = $attempts[0];
            $last = $attempts[count($attempts) - 1];
            $percents = array_map(fn($attempt) => report_data::percent_of($attempt), $attempts);
            $several = count($attempts) > 1;
            $table->add_row([
                'fullname' => report_table::cell(fullname($first, $this->fullnames())),
                'userid' => report_table::cell($userid),
                'activities' => report_table::cell(count($attempts)),
                'average' => report_table::percent(array_sum($percents) / count($percents), true),
                'firstactivity' => report_table::cell($this->activities[(int) $first->whakaharatauid]->activityname),
                'firsttime' => report_table::date((int) $first->timefinish),
                'firstpercent' => report_table::percent($percents[0]),
                'lastactivity' => report_table::cell($several ?
                    $this->activities[(int) $last->whakaharatauid]->activityname : ''),
                'lasttime' => report_table::date($several ? (int) $last->timefinish : 0),
                'lastpercent' => report_table::percent($several ? end($percents) : null),
                'change' => report_table::percent($several ? end($percents) - $percents[0] : null, false, true),
            ]);
        }
        return $table->sort_by('fullname', false);
    }

    // Before and after.

    /**
     * Learners who finished one activity and, later, another.
     *
     * @param int $pre activity done before
     * @param int $post activity done after
     * @return array [report_table, summary: learners, pre, post, change (floats), better, same, worse; null when nobody]
     */
    public function prepost(int $pre, int $post): array {
        $table = new report_table(self::str('crossreport_viewprepost'));
        $table->column('fullname', self::str('report_learner'))
            ->column('userid', self::str('report_userid'), true)
            ->column('pretime', self::str('crossreport_predate'))
            ->column('prepercent', self::str('crossreport_prescore'), true)
            ->column('posttime', self::str('crossreport_postdate'))
            ->column('postpercent', self::str('crossreport_postscore'), true)
            ->column('change', self::str('crossreport_change'), true);
        if (!isset($this->activities[$pre], $this->activities[$post]) || $pre === $post) {
            return [$table, null];
        }
        $byuser = [];
        foreach ($this->get_data(null, [$pre, $post])->get_first_attempts() as $attempt) {
            if (report_data::percent_of($attempt) !== null) {
                $byuser[(int) $attempt->userid][(int) $attempt->whakaharatauid] = $attempt;
            }
        }
        $sums = (object) ['learners' => 0, 'pre' => 0.0, 'post' => 0.0, 'better' => 0, 'same' => 0, 'worse' => 0];
        foreach ($byuser as $userid => $attempts) {
            if (
                !isset($attempts[$pre], $attempts[$post])
                    || (int) $attempts[$post]->timefinish <= (int) $attempts[$pre]->timefinish
            ) {
                continue;
            }
            $before = report_data::percent_of($attempts[$pre]);
            $after = report_data::percent_of($attempts[$post]);
            $change = round($after - $before, 5);
            $sums->learners++;
            $sums->pre += $before;
            $sums->post += $after;
            $sums->{$change > 0 ? 'better' : ($change < 0 ? 'worse' : 'same')}++;
            $table->add_row([
                'fullname' => report_table::cell(fullname($attempts[$pre], $this->fullnames())),
                'userid' => report_table::cell($userid),
                'pretime' => report_table::date((int) $attempts[$pre]->timefinish),
                'prepercent' => report_table::percent($before, true),
                'posttime' => report_table::date((int) $attempts[$post]->timefinish),
                'postpercent' => report_table::percent($after, true),
                'change' => report_table::percent($change, false, true),
            ]);
        }
        if (!$sums->learners) {
            return [$table, null];
        }
        $sums->pre /= $sums->learners;
        $sums->post /= $sums->learners;
        $sums->change = $sums->post - $sums->pre;
        return [$table->sort_by('fullname', false), $sums];
    }

    // Scope.

    /**
     * The wider contexts the viewer may open the report in.
     *
     * @param context $context context now shown
     * @return array[] list of url, label and active, from the widest to this one; empty when there is only this one
     */
    public static function get_scopes(context $context): array {
        $scopes = [];
        foreach (array_reverse($context->get_parent_contexts(true)) as $one) {
            if (!self::can_view($one)) {
                continue;
            }
            $scopes[] = [
                'url' => self::get_url($one)->out(false),
                'label' => $one->contextlevel == CONTEXT_SYSTEM ? self::str('crossreport_site') :
                    $one->get_context_name(false, false, false),
                'active' => $one->id === $context->id,
            ];
        }
        return count($scopes) > 1 ? $scopes : [];
    }

    /**
     * URL of the report in a context.
     *
     * @param context $context course, category or system context
     * @return moodle_url
     */
    public static function get_url(context $context): moodle_url {
        $params = [];
        if ($context->contextlevel == CONTEXT_COURSE) {
            $params['courseid'] = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_COURSECAT) {
            $params['categoryid'] = $context->instanceid;
        }
        return new moodle_url('/mod/whakaharatau/crossreport.php', $params);
    }
}
