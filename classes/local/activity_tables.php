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
use moodle_url;
use stdClass;

/**
 * The report tables of one activity: attempts, learners, individual answers, questions, versions and the anonymised
 * question report.
 *
 * Callers check the capabilities.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_tables {
    /** @var stdClass whakaharatau record */
    protected $instance;
    /** @var cm_info course module */
    protected $cm;
    /** @var context_module module context */
    protected $context;
    /** @var int instance_settings::MODE_* */
    protected $mode;
    /** @var int[]|null groups to show, null = everyone */
    protected $groupids;
    /** @var report_filter filters */
    protected $filter;
    /** @var report_data the attempts and answers */
    protected $data;
    /** @var bool whether full names are shown */
    protected $fullnames;

    /**
     * Constructor.
     *
     * @param stdClass $instance whakaharatau record
     * @param cm_info $cm course module
     * @param context_module $context module context
     * @param report $report the report, for its mode and group scope
     * @param report_filter $filter filters
     */
    public function __construct(
        stdClass $instance,
        cm_info $cm,
        context_module $context,
        report $report,
        report_filter $filter
    ) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->context = $context;
        $this->mode = $report->get_attemptmode();
        $this->groupids = $report->get_groupids();
        $this->filter = $filter;
        $this->data = $this->make_data($filter);
        $this->fullnames = has_capability('moodle/site:viewfullnames', $context);
    }

    /**
     * The data of this activity with some filters.
     *
     * @param report_filter $filter filters
     * @return report_data
     */
    protected function make_data(report_filter $filter): report_data {
        $activity = clone $this->instance;
        $activity->groupids = $this->groupids;
        return new report_data([(int) $activity->id => $activity], $this->mode, $filter);
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
     * Whether the report shows Test attempts.
     *
     * @return bool
     */
    protected function is_test(): bool {
        return $this->mode === instance_settings::MODE_TEST;
    }

    // Attempts.

    /**
     * Every attempt or session; rows can be selected for deleting.
     *
     * @param report $report the report, for the cells
     * @param int $courseid course id, for profile links
     * @return report_table
     */
    public function attempts(report $report, int $courseid): report_table {
        $table = new report_table(self::str($this->is_test() ? 'report_modetest' : 'report_modepractice'));
        $columns = $report->get_attempt_columns();
        foreach ($columns as $key => $label) {
            $table->column($key, $label, !in_array($key, ['fullname', 'state', 'timestart', 'timefinish', 'timemodified']));
            if ($key === 'fullname') {
                $table->column('userid', self::str('report_userid'), true);
            }
        }
        $inrange = $this->data->get_attempts_in_range();
        foreach ($report->get_attempts() as $row) {
            if (!isset($inrange[(int) $row->id])) {
                continue;
            }
            $text = $report->format_attempt_row($row);
            $cells = ['userid' => report_table::cell((int) $row->userid)];
            foreach (array_keys($columns) as $key) {
                $cells[$key] = report_table::cell($text[$key]);
            }
            $cells['fullname']['url'] = (new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $courseid]))
                ->out(false);
            foreach (['timestart', 'timefinish', 'timemodified'] as $key) {
                if (isset($cells[$key])) {
                    $cells[$key]['sort'] = (int) $row->$key ?: '';
                }
            }
            if ($this->is_test()) {
                $percent = report_data::percent_of($row);
                $cells['score']['sort'] = $percent === null ? '' : (float) $row->score;
                $cells['percent'] = report_table::percent($percent);
                $cells['grade']['sort'] = $row->grade === null ? '' : (float) $row->grade;
            } else {
                $cells['firsttryrate'] = report_table::percent($row->slidesanswered ?
                    $row->firsttry / $row->slidesanswered * 100 : null);
            }
            $table->add_row($cells, (int) $row->id);
        }
        return $table;
    }

    // Learners.

    /**
     * One row per learner.
     *
     * @param moodle_url $answersurl URL of the answers view
     * @return report_table
     */
    public function learners(moodle_url $answersurl): report_table {
        $test = $this->is_test();
        $table = new report_table(self::str('report_viewlearners'));
        $table->column('fullname', self::str('report_learner'))
            ->column('userid', self::str('report_userid'), true)
            ->column('attempts', self::str($test ? 'report_attempts' : 'report_sessions'), true);
        if ($test) {
            $table->column('score', self::str('report_firstscore'), true)
                ->column('firstpercent', self::str('report_firstpercent'), true)
                ->column('overall', self::str('report_overall'), true);
        }
        $table->column('answered', self::str('report_questionsanswered'), true)
            ->column('correct', self::str('report_correct'), true)
            ->column('incorrect', self::str('report_incorrect'), true)
            ->column('firsttry', self::str('report_firsttryrate'), true);
        if ($test) {
            $table->column('finished', self::str('report_firstfinished'));
        }
        $table->column('completed', self::str('report_completed'))
            ->column('lastactivity', self::str('report_lastactivity'));

        $byuser = [];
        foreach ($this->data->get_attempts_in_range() as $attempt) {
            $byuser[(int) $attempt->userid][] = $attempt;
        }
        $first = [];
        foreach ($test ? $this->data->get_first_attempts() : [] as $attempt) {
            $first[(int) $attempt->userid] = $attempt;
        }
        $totals = [];
        foreach ($this->data->get_results() as $result) {
            $total = $totals[$result->userid] ?? ($totals[$result->userid] = (object) ['answered' => 0, 'correct' => 0,
                'firsttry' => 0]);
            $total->answered++;
            $total->correct += $result->correct ? 1 : 0;
            $total->firsttry += $result->firsttry ? 1 : 0;
        }
        $completed = $this->get_completion_dates();
        foreach ($byuser as $userid => $attempts) {
            $total = $totals[$userid] ?? null;
            $one = $first[$userid] ?? null;
            $cells = [
                'fullname' => report_table::cell(fullname($attempts[0], $this->fullnames), null, [
                    'url' => (new moodle_url($answersurl, ['learner' => $userid]))->out(false)]),
                'userid' => report_table::cell($userid),
                'attempts' => report_table::cell(count($attempts)),
                'answered' => report_table::cell($total->answered ?? 0),
                'correct' => report_table::cell($total->correct ?? 0),
                'incorrect' => report_table::cell($total ? $total->answered - $total->correct : 0),
                'firsttry' => report_table::percent($total ? $total->firsttry / $total->answered * 100 : null, true),
                'completed' => report_table::date($completed[$userid] ?? 0),
                'lastactivity' => report_table::date(max(array_map(fn($a) => (int) $a->timemodified, $attempts))),
            ];
            if ($test) {
                $scored = array_values(array_filter($attempts, fn($a) => report_data::percent_of($a) !== null));
                $haspercent = $one && report_data::percent_of($one) !== null;
                $cells['score'] = report_table::cell($haspercent ? self::str('report_scoreof', (object) [
                    'score' => format_float((float) $one->score, 2, true, true),
                    'max' => format_float((float) $one->maxscore, 2, true, true),
                ]) : '', $haspercent ? (float) $one->score : '');
                $cells['firstpercent'] = report_table::percent($haspercent ? report_data::percent_of($one) : null);
                $cells['overall'] = report_table::percent($scored ?
                    attempt::aggregate($scored, (int) $this->instance->grademethod) * 100 : null);
                $cells['finished'] = report_table::date($one ? (int) $one->timefinish : 0);
            }
            $table->add_row($cells);
        }
        return $table->sort_by('fullname', false);
    }

    /**
     * When each learner completed the activity, by Moodle's activity completion.
     *
     * @return int[] user id => timestamp
     */
    protected function get_completion_dates(): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        [$insql, $params] = $DB->get_in_or_equal([COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_records_select_menu(
            'course_modules_completion',
            "coursemoduleid = :cmid AND completionstate $insql",
            ['cmid' => $this->cm->id] + $params,
            '',
            'userid, timemodified'
        ));
    }

    // Individual answers.

    /**
     * One row per learner, attempt and question.
     *
     * @return report_table
     */
    public function answers(): report_table {
        $table = new report_table(self::str('report_viewanswers'));
        $table->column('fullname', self::str('report_learner'))
            ->column('userid', self::str('report_userid'), true)
            ->column('attempt', self::str($this->is_test() ? 'report_attempt' : 'report_session'), true);
        if (!$this->is_test()) {
            $table->column('round', self::str('report_round'), true);
        }
        $table->column('number', self::str('report_slide'), true)
            ->column('slideid', self::str('report_questionid'), true)
            ->column('version', self::str('report_version'), true)
            ->column('description', self::str('report_alttext'))
            ->column('choices', self::str('report_chosen'))
            ->column('correctclip', self::str('report_correctclip'))
            ->column('result', self::str('report_result'))
            ->column('tries', self::str('report_tries'), true)
            ->column('time', self::str('report_answered'));
        $questions = $this->get_questions();
        $attempts = $this->data->get_attempts_in_range();
        foreach ($this->data->get_answers($attempts) as $answer) {
            $attempt = $attempts[$answer->attemptid];
            $question = $this->get_question($questions, $answer->slideid, $answer->version);
            if ($answer->firsttry) {
                $result = [self::str('report_resultfirst'), 2];
            } else {
                $result = $answer->correct ? [self::str('report_resultright'), 1] : [self::str('report_resultwrong'), 0];
            }
            $cells = [
                'fullname' => report_table::cell(fullname($attempt, $this->fullnames)),
                'userid' => report_table::cell($answer->userid),
                'attempt' => report_table::cell((int) $attempt->attempt),
                'round' => report_table::cell($answer->round),
                'number' => report_table::cell($question->numbertext, $question->numbersort),
                'slideid' => report_table::cell(abs($answer->slideid)),
                'version' => report_table::cell($answer->version),
                'description' => report_table::cell($question->description),
                'choices' => report_table::cell(implode('; ', array_map(
                    fn($optionid) => $this->get_clip_label($question, $optionid),
                    $answer->choices
                ))),
                'correctclip' => report_table::cell($this->get_correct_label($question)),
                'result' => report_table::cell($result[0], $result[1]),
                'tries' => report_table::cell(count($answer->choices)),
                'time' => report_table::date($answer->time),
            ];
            $table->add_row($cells);
        }
        return $table;
    }

    // Questions.

    /**
     * Every question version there is or was: the current ones, older ones, and those of deleted slides.
     *
     * @return stdClass[][] by slide id and version: slideid, version, current (bool), deleted (bool), number, numbertext,
     *      numbersort, description, prompt, time, imageurl, promptaudiourl, options (list of id, transcript, correct,
     *      audiourl)
     */
    protected function get_questions(): array {
        $questions = [];
        $number = 0;
        $alloptions = slides::get_options_for_instance((int) $this->instance->id);
        $rows = versions::get_all((int) $this->instance->id);
        foreach (slides::get_slides((int) $this->instance->id) as $slide) {
            $number++;
            $id = (int) $slide->id;
            $options = [];
            foreach ($alloptions[$id] ?? [] as $option) {
                $options[] = (object) ['id' => (int) $option->id, 'transcript' => (string) $option->transcript,
                    'correct' => !empty($option->iscorrect),
                    'audiourl' => slides::get_file_url($this->context, slides::AREA_AUDIO, (int) $option->id)];
            }
            $row = $rows[$id][(int) $slide->version] ?? null;
            $questions[$id][(int) $slide->version] = $this->make_question($slide, $options, $number, [
                'current' => true,
                'time' => $row ? (int) $row->timecreated : (int) $slide->timecreated,
                'imageurl' => slides::get_file_url($this->context, slides::AREA_IMAGE, $id),
                'promptaudiourl' => slides::get_file_url($this->context, slides::AREA_PROMPT, $id),
            ]);
        }
        foreach ($rows as $slideid => $versions) {
            $live = isset($questions[$slideid]) ? reset($questions[$slideid]) : null;
            foreach ($versions as $version => $row) {
                if (isset($questions[$slideid][$version])) {
                    continue;
                }
                $options = [];
                foreach ($row->options as $i => $option) {
                    $option->audiourl = versions::get_file_url($this->context, (int) $row->id, 'audio-' . ($i + 1));
                    $options[] = $option;
                }
                $questions[$slideid][$version] = $this->make_question($row, $options, $live ? $live->number : 0, [
                    'current' => false,
                    'time' => (int) $row->timecreated,
                    'imageurl' => versions::get_file_url($this->context, (int) $row->id, 'image'),
                    'promptaudiourl' => versions::get_file_url($this->context, (int) $row->id, 'prompt'),
                ]);
            }
            ksort($questions[$slideid]);
        }
        return $questions;
    }

    /**
     * One question version.
     *
     * @param stdClass $row slide or version record
     * @param stdClass[] $options its options
     * @param int $number slide number, 0 for a deleted slide
     * @param array $extra current, time, imageurl and promptaudiourl
     * @return stdClass
     */
    protected function make_question(stdClass $row, array $options, int $number, array $extra): stdClass {
        return (object) ($extra + [
            'slideid' => (int) ($row->slideid ?? $row->id),
            'version' => (int) $row->version,
            'deleted' => $number === 0,
            'number' => $number,
            'numbertext' => $number ? (string) $number : self::str('report_deletedslide'),
            'numbersort' => $number ?: PHP_INT_MAX,
            'description' => empty($row->decorative) ? (string) $row->alttext : self::str('report_decorative'),
            'prompt' => (string) $row->prompt,
            'options' => $options,
        ]);
    }

    /**
     * The question an answer was given to; a blank one when nothing is known about it.
     *
     * @param stdClass[][] $questions get_questions()
     * @param int $slideid slide id
     * @param int $version version
     * @return stdClass
     */
    protected function get_question(array $questions, int $slideid, int $version): stdClass {
        return $questions[$slideid][$version] ?? $this->make_question(
            (object) ['slideid' => $slideid, 'version' => $version, 'decorative' => 0, 'alttext' => '', 'prompt' => ''],
            [],
            isset($questions[$slideid]) ? reset($questions[$slideid])->number : 0,
            ['current' => false, 'time' => 0, 'imageurl' => null, 'promptaudiourl' => null]
        );
    }

    /**
     * "Clip 2 (She is drinking water.)".
     *
     * @param stdClass $question question version
     * @param int $optionid option id
     * @return string
     */
    protected function get_clip_label(stdClass $question, int $optionid): string {
        foreach ($question->options as $i => $option) {
            if ($option->id === $optionid) {
                $label = self::str('report_clip', $i + 1);
                return $option->transcript === '' ? $label : self::str('report_cliptext', (object) ['clip' => $label,
                    'text' => $option->transcript]);
            }
        }
        return self::str('report_removedclip');
    }

    /**
     * Label of the correct clip.
     *
     * @param stdClass $question question version
     * @return string
     */
    protected function get_correct_label(stdClass $question): string {
        foreach ($question->options as $option) {
            if ($option->correct) {
                return $this->get_clip_label($question, $option->id);
            }
        }
        return '';
    }

    /**
     * Add the columns of the question tables.
     *
     * @param report_table $table table
     * @return void
     */
    protected function add_question_columns(report_table $table): void {
        $table->column('number', self::str('report_slide'), true)
            ->column('slideid', self::str('report_questionid'), true)
            ->column('version', self::str('report_version'), true)
            ->column('description', self::str('report_alttext'))
            ->column('prompt', self::str('prompt'))
            ->column('learners', self::str('report_learners'), true)
            ->column('correct', self::str('report_correct'), true)
            ->column('correctpercent', self::str('report_correctpercent'), true)
            ->column('incorrect', self::str('report_incorrect'), true)
            ->column('incorrectpercent', self::str('report_incorrectpercent'), true)
            ->column('firsttry', self::str('report_firsttry'), true)
            ->column('firsttrypercent', self::str('report_firsttryrate'), true)
            ->column('clips', self::str('report_clipschosen'), false, 'screen')
            ->column('correctclip', self::str('report_correctclip'), false, 'download');
        for ($i = 1; $i <= slides::MAX_OPTIONS; $i++) {
            $table->column("clip$i", self::str('report_clipchosen', $i), true, 'download')
                ->column("cliptext$i", self::str('report_cliptranscript', $i), false, 'download');
        }
        $table->column('removed', self::str('report_removedclips'), true, 'download');
    }

    /**
     * Cells of a question version.
     *
     * @param stdClass $question question version
     * @param stdClass|null $total report_data::get_totals() entry, null when nobody answered
     * @param bool $hidden hide the figures (anonymised, too few learners)
     * @return array[]
     */
    protected function get_question_cells(stdClass $question, ?stdClass $total, bool $hidden = false): array {
        $n = $total->learners ?? 0;
        $cells = [
            'number' => report_table::cell($question->numbertext, $question->numbersort),
            'slideid' => report_table::cell(abs($question->slideid)),
            'version' => report_table::cell($question->version),
            'description' => report_table::cell($question->description, null, [
                'imageurl' => $question->imageurl ? $question->imageurl->out(false) : '']),
            'prompt' => report_table::cell($question->prompt),
            'correctclip' => report_table::cell($this->get_correct_label($question)),
        ];
        foreach ($question->options as $i => $option) {
            $cells['cliptext' . ($i + 1)] = report_table::cell($option->transcript);
        }
        if ($hidden) {
            $few = self::str('report_toofew', instance_settings::get_anonymous_minimum());
            foreach (
                ['learners', 'correct', 'correctpercent', 'incorrect', 'incorrectpercent', 'firsttry', 'firsttrypercent',
                    'clips'] as $key
            ) {
                $cells[$key] = report_table::cell($key === 'learners' ? $few : '', '');
            }
            return $cells;
        }
        $clips = [];
        $known = 0;
        foreach ($question->options as $i => $option) {
            $count = $total->choices[$option->id] ?? 0;
            $known += $count;
            $cells['clip' . ($i + 1)] = report_table::cell($count);
            $clips[] = self::str($option->correct ? 'report_clipcountcorrect' : 'report_clipcount', (object) [
                'clip' => $this->get_clip_label($question, $option->id), 'count' => $count]);
        }
        $removed = array_sum($total->choices ?? []) - $known;
        if ($removed) {
            $clips[] = self::str('report_clipcount', (object) ['clip' => self::str('report_removedclip'),
                'count' => $removed]);
        }
        $correct = $total->correct ?? 0;
        $percent = fn(int $count) => $n ? $count / $n * 100 : null;
        return $cells + [
            'learners' => report_table::cell($n),
            'correct' => report_table::cell($correct),
            'correctpercent' => report_table::percent($percent($correct), true),
            'incorrect' => report_table::cell($n - $correct),
            'incorrectpercent' => report_table::percent($percent($n - $correct)),
            'firsttry' => report_table::cell($total->firsttry ?? 0),
            'firsttrypercent' => report_table::percent($percent($total->firsttry ?? 0), true),
            'clips' => report_table::cell($n ? implode('; ', $clips) : ''),
            'removed' => report_table::cell($removed),
        ];
    }

    /**
     * Question versions in the order of the report: by slide, then version; deleted slides last.
     *
     * @param array $totals report_data::get_totals()
     * @return stdClass[]
     */
    protected function get_ordered_questions(array $totals): array {
        $list = [];
        $questions = $this->get_questions();
        foreach ($questions as $versions) {
            foreach ($versions as $question) {
                // Old versions and deleted slides are of interest only when someone answered them.
                if ($question->current || isset($totals[$question->slideid . '-' . $question->version])) {
                    $list[] = $question;
                }
            }
        }
        // Answers to a version that has no record (none is expected).
        foreach ($totals as $total) {
            if (!isset($questions[$total->slideid][$total->version])) {
                $list[] = $this->get_question($questions, $total->slideid, $total->version);
            }
        }
        usort($list, fn($a, $b) => [$a->numbersort, abs($a->slideid), $a->version] <=>
            [$b->numbersort, abs($b->slideid), $b->version]);
        return $list;
    }

    /**
     * One row per question, each version that was answered on its own row.
     *
     * @return report_table
     */
    public function questions(): report_table {
        $table = new report_table(self::str('report_slidescaption'));
        $this->add_question_columns($table);
        $totals = report_data::get_totals($this->data->get_results());
        foreach ($this->get_ordered_questions($totals) as $question) {
            $table->add_row($this->get_question_cells(
                $question,
                $totals[$question->slideid . '-' . $question->version] ?? null
            ));
        }
        return $table;
    }

    /**
     * The versions of the questions that have been changed, each compared with the version before it.
     *
     * @return report_table
     */
    public function versions(): report_table {
        $table = new report_table(self::str('report_viewversions'));
        $table->column('number', self::str('report_slide'), true)
            ->column('slideid', self::str('report_questionid'), true)
            ->column('version', self::str('report_version'), true)
            ->column('since', self::str('report_since'))
            ->column('description', self::str('report_alttext'))
            ->column('prompt', self::str('prompt'))
            ->column('content', self::str('report_clips'))
            ->column('changed', self::str('report_changed'))
            ->column('learners', self::str('report_learners'), true)
            ->column('correctpercent', self::str('report_correctpercent'), true)
            ->column('correctchange', self::str('report_correctchange'), true)
            ->column('firsttrypercent', self::str('report_firsttryrate'), true)
            ->column('firsttrychange', self::str('report_firsttrychange'), true);
        $totals = report_data::get_totals($this->data->get_results());
        $rows = versions::get_all((int) $this->instance->id);
        $previous = null;
        foreach ($this->get_ordered_questions($totals) as $question) {
            if (count($rows[$question->slideid] ?? []) < 2) {
                continue;
            }
            $total = $totals[$question->slideid . '-' . $question->version] ?? null;
            $correct = $total ? $total->correct / $total->learners * 100 : null;
            $firsttry = $total ? $total->firsttry / $total->learners * 100 : null;
            if (!$previous || $previous->question->slideid !== $question->slideid) {
                $previous = null;
            }
            $audios = [];
            if ($question->promptaudiourl) {
                $audios[] = ['label' => self::str('promptaudio'), 'url' => $question->promptaudiourl->out(false)];
            }
            foreach ($question->options as $option) {
                if ($option->audiourl) {
                    $audios[] = ['label' => $this->get_clip_label($question, $option->id),
                        'url' => $option->audiourl->out(false)];
                }
            }
            $change = fn(?float $now, ?float $before) => $now === null || $before === null ? null : $now - $before;
            $table->add_row([
                'number' => report_table::cell($question->numbertext, $question->numbersort),
                'slideid' => report_table::cell(abs($question->slideid)),
                'version' => report_table::cell($question->version),
                'since' => report_table::date($question->time),
                'description' => report_table::cell($question->description, null, [
                    'imageurl' => $question->imageurl ? $question->imageurl->out(false) : '']),
                'prompt' => report_table::cell($question->prompt),
                'content' => report_table::cell(self::str('report_correctis', $this->get_correct_label($question)), null, [
                    'audios' => $audios]),
                'changed' => report_table::cell($previous ? $this->get_changes($previous->question, $question) : ''),
                'learners' => report_table::cell($total->learners ?? 0),
                'correctpercent' => report_table::percent($correct, true),
                'correctchange' => report_table::percent($change($correct, $previous->correct ?? null), false, true),
                'firsttrypercent' => report_table::percent($firsttry, true),
                'firsttrychange' => report_table::percent($change($firsttry, $previous->firsttry ?? null), false, true),
            ]);
            $previous = (object) ['question' => $question, 'correct' => $correct, 'firsttry' => $firsttry];
        }
        return $table;
    }

    /**
     * What changed between two versions, as a list of words.
     *
     * @param stdClass $before earlier version
     * @param stdClass $after later version
     * @return string
     */
    protected function get_changes(stdClass $before, stdClass $after): string {
        $changes = [];
        $hash = function (?moodle_url $url): string {
            return $url ? $this->get_contenthash($url) : '';
        };
        if ($hash($before->imageurl) !== $hash($after->imageurl)) {
            $changes[] = self::str('slidepicture');
        }
        if ($before->description !== $after->description) {
            $changes[] = self::str('alttext');
        }
        if ($before->prompt !== $after->prompt) {
            $changes[] = self::str('prompt');
        }
        if ($hash($before->promptaudiourl) !== $hash($after->promptaudiourl)) {
            $changes[] = self::str('promptaudio');
        }
        $list = function (stdClass $question, callable $value): array {
            $values = [];
            foreach ($question->options as $option) {
                $values[$option->id] = $value($option);
            }
            ksort($values);
            return $values;
        };
        if ($list($before, fn($o) => $hash($o->audiourl)) !== $list($after, fn($o) => $hash($o->audiourl))) {
            $changes[] = self::str('report_changedclips');
        }
        if ($list($before, fn($o) => $o->transcript) !== $list($after, fn($o) => $o->transcript)) {
            $changes[] = self::str('report_changedtranscripts');
        }
        if ($list($before, fn($o) => (bool) $o->correct) !== $list($after, fn($o) => (bool) $o->correct)) {
            $changes[] = self::str('correctoption');
        }
        return implode(', ', $changes);
    }

    /**
     * Content hash of a file of this activity, from its URL.
     *
     * @param moodle_url $url pluginfile URL made by this plugin
     * @return string empty when the file is gone
     */
    protected function get_contenthash(moodle_url $url): string {
        // Without slash arguments the path is in a parameter.
        $parts = explode('/', trim($url->get_param('file') ?? $url->get_path(), '/'));
        // The path ends in: area, item id, revision, name.
        [$area, $itemid, , $name] = array_slice($parts, -4);
        $file = get_file_storage()->get_file($this->context->id, slides::COMPONENT, $area, (int) $itemid, '/', $name);
        return $file ? $file->get_contenthash() : '';
    }

    /**
     * The question report without learners, hiding results from too few of them.
     *
     * @return report_table
     */
    public function anonymised(): report_table {
        $table = new report_table(self::str('report_anonymised'));
        $this->add_question_columns($table);
        $totals = report_data::get_totals($this->data->get_results());
        $all = $this->filter->is_active() ?
            report_data::get_totals($this->make_data(new report_filter())->get_results()) : $totals;
        foreach ($this->get_ordered_questions($all) as $question) {
            $key = $question->slideid . '-' . $question->version;
            $hidden = report_data::is_hidden($totals[$key]->learners ?? 0, $all[$key]->learners ?? 0);
            $table->add_row($this->get_question_cells($question, $totals[$key] ?? null, $hidden));
        }
        return $table;
    }
}
