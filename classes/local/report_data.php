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

use stdClass;

/**
 * The attempts and answers behind every report, for one activity or several, inside the viewer's groups and filters.
 *
 * One result per learner and question: from the learner's first finished Test attempt in that activity, or in Practice
 * from the first time they answered the slide. The dates are applied after that choice, so a second attempt never counts
 * as a first one because the first fell outside the dates.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_data {
    /** @var stdClass[] whakaharatau records by id, each with groupids (int[]|null = everyone) */
    protected $activities;
    /** @var int instance_settings::MODE_* */
    protected $mode;
    /** @var report_filter filters */
    protected $filter;
    /** @var stdClass[]|null attempts, see get_attempts() */
    protected $attempts = null;

    /**
     * Constructor.
     *
     * @param stdClass[] $activities whakaharatau records by id; groupids null = everyone, else only these groups
     * @param int $mode instance_settings::MODE_*
     * @param report_filter $filter filters
     */
    public function __construct(array $activities, int $mode, report_filter $filter) {
        $this->activities = $activities;
        $this->mode = $mode;
        $this->filter = $filter;
    }

    /**
     * Every attempt of the mode by the learners in scope, whatever its date.
     *
     * @return stdClass[] by attempt id, in order of activity, learner and attempt, with the learner's name fields
     */
    public function get_attempts(): array {
        global $DB;
        if ($this->attempts !== null) {
            return $this->attempts;
        }
        $this->attempts = [];
        [$where, $params] = $this->get_scope_sql();
        if ($where === '') {
            return $this->attempts;
        }
        $fields = \core_user\fields::for_name()->get_sql('u', true);
        [$usersql, $userparams] = $this->filter->get_user_sql('u');
        $sql = "SELECT a.id, a.whakaharatauid, a.userid, a.attempt, a.state, a.score, a.maxscore, a.beststreak,
                       a.timestart, a.timefinish, a.timemodified {$fields->selects}
                  FROM {whakaharatau_attempt} a
                  JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                       {$fields->joins}
                 WHERE a.mode = :mode AND $where $usersql
              ORDER BY a.whakaharatauid, a.userid, a.attempt";
        $recordset = $DB->get_recordset_sql($sql, ['mode' => $this->mode] + $params + $userparams + $fields->params);
        foreach ($recordset as $record) {
            $this->attempts[(int) $record->id] = $record;
        }
        $recordset->close();
        return $this->attempts;
    }

    /**
     * SQL that limits attempts (alias a) to the activities and the groups the viewer may see in each.
     *
     * @return array [SQL, params]; the SQL is empty when there is nothing to see
     */
    protected function get_scope_sql(): array {
        global $DB;
        $open = [];
        $parts = [];
        $params = [];
        $n = 0;
        foreach ($this->activities as $id => $activity) {
            if ($activity->groupids === null) {
                $open[] = (int) $id;
            } else if ($activity->groupids) {
                $n++;
                [$insql, $inparams] = $DB->get_in_or_equal($activity->groupids, SQL_PARAMS_NAMED, "g{$n}x");
                $parts[] = "(a.whakaharatauid = :gw$n AND EXISTS (SELECT 1
                                                                   FROM {groups_members} gm$n
                                                                  WHERE gm$n.userid = a.userid
                                                                        AND gm$n.groupid $insql))";
                $params += ["gw$n" => (int) $id] + $inparams;
            }
        }
        if ($open) {
            [$insql, $inparams] = $DB->get_in_or_equal($open, SQL_PARAMS_NAMED, 'w');
            $parts[] = "a.whakaharatauid $insql";
            $params += $inparams;
        }
        return [$parts ? '(' . implode(' OR ', $parts) . ')' : '', $params];
    }

    /**
     * When an attempt counts for the date filter: when it was finished, or its last activity while in progress.
     *
     * @param stdClass $attempt attempt
     * @return int timestamp
     */
    public static function when(stdClass $attempt): int {
        return (int) $attempt->timefinish ?: (int) $attempt->timemodified;
    }

    /**
     * Score of a finished attempt.
     *
     * @param stdClass $attempt attempt
     * @return float|null 0 to 100, null when there is none
     */
    public static function percent_of(stdClass $attempt): ?float {
        if ($attempt->state !== attempt::STATE_FINISHED || $attempt->maxscore === null || (float) $attempt->maxscore <= 0) {
            return null;
        }
        return (float) $attempt->score / (float) $attempt->maxscore * 100;
    }

    /**
     * The attempts inside the dates.
     *
     * @return stdClass[] by attempt id
     */
    public function get_attempts_in_range(): array {
        return array_filter($this->get_attempts(), fn($attempt) => $this->filter->in_range(self::when($attempt)));
    }

    /**
     * Each learner's first finished attempt in each activity, if it is inside the dates.
     *
     * @return stdClass[] by attempt id
     */
    public function get_first_attempts(): array {
        $seen = [];
        $first = [];
        foreach ($this->get_attempts() as $id => $attempt) {
            $key = $attempt->whakaharatauid . '-' . $attempt->userid;
            if ($attempt->state !== attempt::STATE_FINISHED || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->filter->in_range(self::when($attempt))) {
                $first[$id] = $attempt;
            }
        }
        return $first;
    }

    /**
     * Answers of attempts, one entry per time a slide was answered.
     *
     * @param stdClass[] $attempts attempts by id
     * @return stdClass[] list of attemptid, whakaharatauid, userid, round, slideid, version, choices (option ids in
     *      order), correct (bool), firsttry (bool), time (last answer), in order of the attempts and of answering
     */
    public function get_answers(array $attempts): array {
        global $DB;
        $answers = array_fill_keys(array_keys($attempts), []);
        foreach (array_chunk(array_keys($attempts), 1000) as $ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $recordset = $DB->get_recordset_select('whakaharatau_response', "attemptid $insql", $params, 'id');
            foreach ($recordset as $row) {
                $key = $row->round . '-' . $row->slideid;
                $attempt = $attempts[(int) $row->attemptid];
                $answer = $answers[(int) $row->attemptid][$key] ?? null;
                if (!$answer) {
                    $answer = (object) [
                        'attemptid' => (int) $row->attemptid,
                        'whakaharatauid' => (int) $attempt->whakaharatauid,
                        'userid' => (int) $attempt->userid,
                        'round' => (int) $row->round,
                        'slideid' => (int) $row->slideid,
                        'version' => (int) $row->version,
                        'choices' => [],
                        'correct' => false,
                        'firsttry' => false,
                        'time' => 0,
                    ];
                    $answers[(int) $row->attemptid][$key] = $answer;
                }
                $answer->choices[] = (int) $row->optionid;
                $answer->time = max($answer->time, (int) $row->timeanswered);
                if (!empty($row->correct)) {
                    $answer->correct = true;
                    $answer->firsttry = $answer->firsttry || (int) $row->tries === 1;
                }
            }
            $recordset->close();
        }
        return array_merge([], ...array_map('array_values', array_values($answers)));
    }

    /**
     * One result per learner and question (see the class comment).
     *
     * @return stdClass[] list of get_answers() entries
     */
    public function get_results(): array {
        if ($this->mode === instance_settings::MODE_TEST) {
            return $this->get_answers($this->get_first_attempts());
        }
        $attempts = $this->get_attempts();
        $seen = [];
        $results = [];
        foreach ($this->get_answers($attempts) as $answer) {
            $key = $answer->whakaharatauid . '-' . $answer->userid . '-' . $answer->slideid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->filter->in_range(self::when($attempts[$answer->attemptid]))) {
                $results[] = $answer;
            }
        }
        return $results;
    }

    /**
     * Totals of results by question version.
     *
     * @param stdClass[] $results get_results()
     * @return stdClass[] by "slideid-version": learners, correct, firsttry, choices (option id => learners)
     */
    public static function get_totals(array $results): array {
        $totals = [];
        foreach ($results as $result) {
            $key = $result->slideid . '-' . $result->version;
            $total = $totals[$key] ?? ($totals[$key] = (object) ['slideid' => $result->slideid,
                'version' => $result->version, 'learners' => 0, 'correct' => 0, 'firsttry' => 0, 'choices' => []]);
            $total->learners++;
            $total->correct += $result->correct ? 1 : 0;
            $total->firsttry += $result->firsttry ? 1 : 0;
            foreach (array_unique($result->choices) as $optionid) {
                $total->choices[$optionid] = ($total->choices[$optionid] ?? 0) + 1;
            }
        }
        return $totals;
    }

    /**
     * Number of learners as published in an anonymised report: a band as wide as the minimum, so that totals cannot be
     * subtracted from each other.
     *
     * @param int $learners number of learners, at least the minimum
     * @return array report_table cell, sorted by the band
     */
    public static function anonymous_learners(int $learners): array {
        $min = instance_settings::get_anonymous_minimum();
        $from = intdiv($learners, $min) * $min;
        return report_table::cell(get_string('report_band', 'mod_whakaharatau', (object) ['from' => $from,
            'to' => $from + $min - 1]), $from);
    }

    /**
     * A percentage as published in an anonymised report: rounded to 5, with the ends merged. The smaller the result, the
     * wider the ends, so that "all" and "all but one" cannot be told apart: "all were wrong" would tell about everyone.
     *
     * @param float|null $percent 0 to 100, null for none
     * @param int $learners number of learners it comes from
     * @return array report_table cell, sorted by the published value
     */
    public static function anonymous_percent(?float $percent, int $learners): array {
        if ($percent === null) {
            return report_table::cell('', '');
        }
        $end = $learners < 10 ? 20 : ($learners < 20 ? 10 : 5);
        $rounded = (int) (round($percent / 5) * 5);
        // Decided on the exact value, so that nothing between the last step and the end stands out.
        if ($percent >= 100 - $end || $rounded >= 100 - $end) {
            return report_table::cell(get_string('report_ormore', 'mod_whakaharatau', 100 - $end), 100 - $end);
        }
        if ($percent <= $end || $rounded <= $end) {
            return report_table::cell(get_string('report_orless', 'mod_whakaharatau', $end), $end);
        }
        return report_table::cell(get_string('percents', 'core', $rounded), $rounded);
    }

    /**
     * Fewest learners an anonymised result may come from. Breakdowns that cut across the others (cohorts, months) overlap
     * them by a few learners, so they need enough for the rounding to cover a learner.
     *
     * @param bool $cutsacross whether it is such a breakdown
     * @return int
     */
    public static function get_minimum(bool $cutsacross): int {
        return instance_settings::get_anonymous_minimum() * ($cutsacross ? 4 : 1);
    }

    /**
     * Whether an anonymised result must be hidden: too few learners, or too few left out by the filter.
     *
     * @param int $learners learners in the result
     * @param int $all learners in the same result without the filter
     * @param bool $cutsacross whether it is a breakdown that cuts across the others
     * @return bool
     */
    public static function is_hidden(int $learners, int $all, bool $cutsacross = false): bool {
        $min = self::get_minimum($cutsacross);
        $left = $all - $learners;
        return $learners < $min || ($left > 0 && $left < $min);
    }

    /**
     * Totals of results by question, with the versions put together where one of them has too few learners: their separate
     * results could be subtracted from the question's.
     *
     * @param stdClass[] $results get_results()
     * @param stdClass[] $reference results that decide what is put together, e.g. without the filter
     * @param int $min fewest learners
     * @return stdClass[] by "slideid-version", or "slideid-0" for versions put together; see get_totals()
     */
    public static function get_anonymous_totals(array $results, array $reference, int $min): array {
        $merge = [];
        foreach (self::get_totals($reference) as $total) {
            if ($total->learners < $min) {
                $merge[$total->slideid] = true;
            }
        }
        foreach (self::get_totals($results) as $total) {
            if ($total->learners < $min) {
                $merge[$total->slideid] = true;
            }
        }
        $merged = [];
        foreach ($results as $result) {
            $copy = clone $result;
            $copy->version = isset($merge[$result->slideid]) ? 0 : $result->version;
            $merged[] = $copy;
        }
        return self::get_totals($merged);
    }
}
