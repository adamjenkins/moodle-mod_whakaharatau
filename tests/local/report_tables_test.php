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
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Tests of the report tables: sorting, filters, figures, groups, and hiding small anonymised results.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(report_table::class)]
#[CoversClass(report_filter::class)]
#[CoversClass(report_data::class)]
#[CoversClass(activity_tables::class)]
#[CoversClass(cross_report::class)]
final class report_tables_test extends \advanced_testcase {
    /** @var stdClass course */
    private $course;
    /** @var stdClass instance record */
    private $instance;
    /** @var cm_info course module */
    private $cm;
    /** @var context_module context */
    private $context;
    /** @var stdClass[] slides */
    private $slides = [];

    /**
     * A course with a two-slide Test (option index 1 is correct), as admin.
     *
     * @param array $settings activity settings
     * @return void
     */
    private function setup_activity(array $settings = []): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['summary' => '', 'enablecompletion' => 1]);
        [$this->instance, $this->cm, $this->context] = $this->add_activity('One', $settings);
        $this->slides = array_values(slides::get_slides((int) $this->instance->id));
    }

    /**
     * Add a two-slide Test.
     *
     * @param string $name name
     * @param array $settings settings
     * @return array [instance, cm, context]
     */
    private function add_activity(string $name, array $settings = []): array {
        global $DB;
        $created = $this->getDataGenerator()->create_module('whakaharatau', $settings + ['course' => $this->course->id,
            'name' => $name, 'mode' => 1]);
        for ($i = 0; $i < 2; $i++) {
            $this->modgen()->create_slide(['whakaharatauid' => $created->id, 'options' => 3, 'correct' => 1,
                'alttext' => "Picture $i", 'transcripts' => ['tahi', 'rua', 'toru']]);
        }
        return [$DB->get_record('whakaharatau', ['id' => $created->id]),
            get_fast_modinfo($this->course)->get_cm($created->cmid), context_module::instance($created->cmid)];
    }

    /**
     * The generator of this module.
     *
     * @return \mod_whakaharatau_generator
     */
    private function modgen(): \mod_whakaharatau_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
    }

    /**
     * A student with a finished attempt.
     *
     * @param string $firstname first name
     * @param string[] $answers right or wrong for each slide
     * @param int $time when it was finished, 0 = now
     * @param stdClass|null $user existing user
     * @param stdClass|null $instance activity, default the first
     * @return stdClass user
     */
    private function attempt(
        string $firstname,
        array $answers,
        int $time = 0,
        ?stdClass $user = null,
        ?stdClass $instance = null
    ): stdClass {
        global $DB;
        $user = $user ?? $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['firstname' => $firstname,
            'lastname' => 'Test']);
        $attempt = $this->modgen()->create_attempt(['whakaharatauid' => ($instance ?? $this->instance)->id,
            'userid' => $user->id, 'forcenew' => true, 'answers' => $answers, 'finish' => true]);
        if ($time) {
            $DB->set_field('whakaharatau_attempt', 'timefinish', $time, ['id' => $attempt->get_id()]);
        }
        return $user;
    }

    /**
     * The tables.
     *
     * @param report_filter|null $filter filters
     * @param int|false $groupid group
     * @param int $mode mode
     * @return activity_tables
     */
    private function tables(?report_filter $filter = null, $groupid = false, int $mode = 1): activity_tables {
        $report = new report($this->instance, $this->cm, $this->context, $mode, $groupid);
        return new activity_tables($this->instance, $this->cm, $this->context, $report, $filter ?? new report_filter());
    }

    /**
     * A filter.
     *
     * @param array $values properties
     * @return report_filter
     */
    private function filter(array $values): report_filter {
        $filter = new report_filter();
        foreach ($values as $name => $value) {
            $filter->$name = $value;
        }
        return $filter;
    }

    /**
     * One column of a table's file.
     *
     * @param report_table $table table
     * @param string $key column
     * @return string[]
     */
    private static function col(report_table $table, string $key): array {
        return array_column($table->get_download_rows(), $key);
    }

    /**
     * Numbers sort as numbers, words in the language's order, and blanks come last both ways.
     *
     * @return void
     */
    public function test_sorting(): void {
        global $PAGE;
        $this->resetAfterTest();
        $make = function (): report_table {
            $table = (new report_table('Caption'))->column('name', 'Name')->column('score', 'Score', true)
                ->column('hidden', 'Hidden', false, 'download');
            foreach ([['Ōtara', 9], ['beta', ''], ['Alpha', 100], ['Zed', 20]] as [$name, $score]) {
                $table->add_row(['name' => report_table::cell($name), 'score' => report_table::cell($score)]);
            }
            return $table;
        };
        $this->assertSame(['9', '20', '100', ''], self::col($make()->sort_by('score', false), 'score'));
        $this->assertSame(['100', '20', '9', ''], self::col($make()->sort_by('score', true), 'score'));
        $this->assertSame(['Alpha', 'beta', 'Ōtara', 'Zed'], self::col($make()->sort_by('name', false), 'name'));
        $this->assertSame(['Ōtara', 'beta', 'Alpha', 'Zed'], self::col($make()->sort_by('nosuch', false), 'name'));

        $url = new moodle_url('/mod/whakaharatau/report.php', ['id' => 1]);
        $data = $make()->sort_by('score', false)->export($url);
        $this->assertSame(['none', 'ascending'], array_column($data['columns'], 'ariasort'));
        $this->assertStringContainsString('tdir=desc', $data['columns'][1]['url']);
        $this->assertStringContainsString('tdir=asc', $data['columns'][0]['url']);
        $PAGE->set_url('/');
        $html = $PAGE->get_renderer('core')->render_from_template('mod_whakaharatau/report_table', $data + ['emptytext' => '']);
        $this->assertSame(2, substr_count($html, 'aria-sort='));
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Question figures: each learner once, from the first finished attempt.
     *
     * @return void
     */
    public function test_questions_use_first_finished_attempt(): void {
        $this->setup_activity();
        $ann = $this->attempt('Ann', ['right', 'wrong']);
        $this->attempt('Ann', ['wrong', 'right'], 0, $ann);
        $this->attempt('Bob', ['wrong', 'wrong']);
        // In progress: not counted.
        $cat = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->modgen()->create_attempt(['whakaharatauid' => $this->instance->id, 'userid' => $cat->id,
            'answers' => ['right']]);

        $table = $this->tables()->questions();
        $this->assertSame(['2', '2'], self::col($table, 'learners'));
        $this->assertSame(['1', '0'], self::col($table, 'correct'));
        $this->assertSame(['1', '2'], self::col($table, 'incorrect'));
        $this->assertSame(['50', '0'], self::col($table, 'firsttrypercent'));
        $this->assertSame(['1', '0'], self::col($table, 'clip2'));
        $this->assertSame(['rua', 'rua'], self::col($table, 'cliptext2'));
        $row = $table->get_download_rows()[0];
        $this->assertSame(2, (int) $row['clip1'] + (int) $row['clip2'] + (int) $row['clip3']);
    }

    /**
     * Practice counts the first time a learner met each slide.
     *
     * @return void
     */
    public function test_practice_uses_first_meeting(): void {
        $this->setup_activity(['mode' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->modgen()->create_attempt(['whakaharatauid' => $this->instance->id, 'userid' => $user->id,
            'answers' => ['wrongthenright', 'right']]);
        $this->modgen()->create_attempt(['whakaharatauid' => $this->instance->id, 'userid' => $user->id, 'forcenew' => true,
            'answers' => ['right', 'right']]);
        $table = $this->tables(null, false, 0)->questions();
        $this->assertSame(['1', '1'], self::col($table, 'learners'));
        $this->assertSame(['1', '1'], self::col($table, 'correct'));
        $this->assertSame(['0', '1'], self::col($table, 'firsttry'));
    }

    /**
     * Learner and answer tables.
     *
     * @return void
     */
    public function test_learners_and_answers(): void {
        global $DB;
        $this->setup_activity(['grademethod' => instance_settings::GRADE_HIGHEST]);
        $ann = $this->attempt('Ann', ['right', 'wrong']);
        $this->attempt('Ann', ['right', 'right'], 0, $ann);
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $this->cm->id, 'userid' => $ann->id,
            'completionstate' => 1, 'timemodified' => 1000000000]);
        $table = $this->tables()->learners(new moodle_url('/mod/whakaharatau/report.php'));
        $row = $table->get_download_rows()[0];
        $this->assertSame((string) $ann->id, $row['userid']);
        $this->assertSame(['2', '50', '100', '1', '1'], [$row['attempts'], $row['firstpercent'], $row['overall'],
            $row['correct'], $row['incorrect']]);
        $this->assertStringContainsString('/01', $row['completed']);

        $answers = $this->tables()->answers();
        $this->assertSame(4, $answers->count_rows());
        $this->assertSame('Clip 2 (rua)', $answers->get_download_rows()[0]['choices']);
        $this->assertSame('Right first time', $answers->get_download_rows()[0]['result']);
        $this->assertSame('Wrong', $answers->get_download_rows()[1]['result']);
    }

    /**
     * Learner, cohort and date filters. A later attempt is never taken for the first one.
     *
     * @return void
     */
    public function test_filters(): void {
        $this->setup_activity();
        $ann = $this->attempt('Ann', ['right', 'right'], 1000000);
        $this->attempt('Ann', ['wrong', 'wrong'], 2000000, $ann);
        $bob = $this->attempt('Bob', ['right', 'wrong'], 2000000);
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $bob->id);

        $learners = fn(array $values) => self::col($this->tables($this->filter($values))->questions(), 'learners');
        $this->assertSame(['2', '2'], $learners([]));
        $this->assertSame(['1', '1'], $learners(['learner' => 'ann t']));
        $this->assertSame(['1', '1'], $learners(['learner' => (string) $bob->id]));
        $this->assertSame(['0', '0'], $learners(['learner' => '%']));
        $this->assertSame(['1', '1'], $learners(['cohortid' => (int) $cohort->id]));
        // Only Bob's first attempt is in these dates; Ann's second is not a first one.
        $this->assertSame(['1', '1'], $learners(['from' => 1500000]));
        $this->assertSame(['1', '1'], $learners(['to' => 1400000]));
        // The last day is included, to its end.
        $filter = $this->filter(['to' => 1000000]);
        $this->assertTrue($filter->in_range(1000000 + DAYSECS - 1));
        $this->assertFalse($filter->in_range(1000000 + DAYSECS));
        $this->assertSame(3, $this->tables()->attempts(
            new report($this->instance, $this->cm, $this->context, 1, false),
            (int) $this->course->id
        )->count_rows());
    }

    /**
     * Separate groups limit every table, and a hidden cohort cannot be used as a filter.
     *
     * @return void
     */
    public function test_groups_and_hidden_cohorts(): void {
        $this->setup_activity(['groupmode' => SEPARATEGROUPS]);
        $ann = $this->attempt('Ann', ['right', 'right']);
        $this->attempt('Bob', ['right', 'wrong']);
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        groups_add_member($group->id, $ann->id);
        $this->assertSame(['1', '1'], self::col($this->tables(null, (int) $group->id)->questions(), 'learners'));
        $this->assertSame(2, $this->tables(null, (int) $group->id)->answers()->count_rows());

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $hidden = $this->getDataGenerator()->create_cohort(['visible' => 0]);
        $shown = $this->getDataGenerator()->create_cohort(['visible' => 1]);
        $this->setUser($teacher);
        $this->assertSame(['0', '0'], self::col($this->tables(null, 0)->questions(), 'learners'));
        $cohorts = report_filter::get_cohorts($this->context);
        $this->assertArrayHasKey($shown->id, $cohorts);
        $this->assertArrayNotHasKey($hidden->id, $cohorts);
        // Across activities: no group, so nobody; then Ann only.
        $context = \context_course::instance($this->course->id);
        $this->assertSame(0, (new cross_report($context, new report_filter()))->progress()->count_rows());
        groups_add_member($group->id, $teacher->id);
        $this->assertSame(
            [(string) $ann->id],
            self::col((new cross_report($context, new report_filter()))->progress(), 'userid')
        );
    }

    /**
     * Small anonymised results are hidden, also when the filters leave only a few learners out.
     *
     * @return void
     */
    public function test_anonymised(): void {
        $this->setup_activity();
        $cohort = $this->getDataGenerator()->create_cohort();
        for ($i = 0; $i < 4; $i++) {
            $user = $this->attempt("U$i", ['right', 'wrong']);
            cohort_add_member($cohort->id, $user->id);
        }
        $table = $this->tables()->anonymised();
        $this->assertSame(['Fewer than 5', 'Fewer than 5'], self::col($table, 'learners'));
        foreach (['correct', 'correctpercent', 'incorrect', 'firsttry', 'clip1', 'clip2', 'clip3', 'removed'] as $key) {
            $this->assertSame(['', ''], self::col($table, $key), $key);
        }
        $this->assertArrayNotHasKey('fullname', $table->get_download_columns());
        $this->assertArrayNotHasKey('userid', $table->get_download_columns());
        cohort_add_member($cohort->id, $this->attempt('U4', ['right', 'wrong'])->id);
        $this->assertSame(['5', '5'], self::col($this->tables()->anonymised(), 'learners'));
        // One more learner outside the cohort: the cohort's 5 would reveal the sixth by subtraction.
        $this->attempt('U5', ['wrong', 'wrong']);
        $this->assertSame(['6', '6'], self::col($this->tables()->anonymised(), 'learners'));
        $filter = $this->filter(['cohortid' => (int) $cohort->id]);
        $this->assertSame(['Fewer than 5', 'Fewer than 5'], self::col($this->tables($filter)->anonymised(), 'learners'));
        set_config('anonymousmin', 10, 'whakaharatau');
        $this->assertSame(['Fewer than 10', 'Fewer than 10'], self::col($this->tables()->anonymised(), 'learners'));
        set_config('anonymousmin', 1, 'whakaharatau');
        $this->assertSame(2, instance_settings::get_anonymous_minimum());
    }

    /**
     * Versions are separate rows with the change between them; deleted slides stay in the report.
     *
     * @return void
     */
    public function test_versions_and_deleted_slides(): void {
        global $DB;
        $this->setup_activity();
        $this->attempt('Ann', ['wrong', 'right']);
        $id = (int) $this->slides[0]->id;
        $this->assertSame(0, $this->tables()->versions()->count_rows());
        $DB->set_field('whakaharatau_slide', 'alttext', 'Better picture', ['id' => $id]);
        versions::sync($this->context, $id);
        $this->attempt('Bob', ['right', 'right']);

        $table = $this->tables()->versions();
        $this->assertSame(['1', '2'], self::col($table, 'version'));
        $this->assertSame(['Picture 0', 'Better picture'], self::col($table, 'description'));
        $this->assertSame(['', '100'], self::col($table, 'correctchange'));
        $this->assertSame(['', get_string('alttext', 'mod_whakaharatau')], self::col($table, 'changed'));
        $this->assertSame(['1', '1', '2'], self::col($this->tables()->questions(), 'learners'));

        slides::delete_slide($this->context, $id);
        $table = $this->tables()->questions();
        $this->assertSame(['1', 'Deleted', 'Deleted'], self::col($table, 'number'));
        $this->assertSame(['Picture 1', 'Picture 0', 'Better picture'], self::col($table, 'description'));
        $this->assertSame('Deleted', $this->tables()->answers()->get_download_rows()[0]['number']);
    }

    /**
     * Totals, progress and before and after, across activities.
     *
     * @return void
     */
    public function test_cross_report(): void {
        $this->setup_activity();
        [$second] = $this->add_activity('Two');
        $ann = $this->attempt('Ann', ['right', 'wrong'], 1000000);
        $this->attempt('Ann', ['right', 'right'], 2000000, $ann, $second);
        $bob = $this->attempt('Bob', ['right', 'right'], 1000000);
        // Bob did the second one before the first: not a comparison.
        $this->attempt('Bob', ['wrong', 'wrong'], 500000, $bob, $second);
        $cat = $this->attempt('Cat', ['wrong', 'wrong'], 1000000);
        $context = \context_course::instance($this->course->id);
        $report = new cross_report($context, new report_filter());

        $table = $report->summary('activity');
        $this->assertSame(['One', 'Two'], self::col($table, 'label'));
        $this->assertSame(['3', '2'], self::col($table, 'learners'));
        $this->assertSame(['50', '50'], self::col($table, 'percent'));
        foreach (array_keys(cross_report::GROUPINGS) as $grouping) {
            $this->assertGreaterThan(0, $report->summary($grouping)->count_rows(), $grouping);
        }
        $this->assertSame(['3'], self::col($report->summary('category'), 'learners'));
        $this->assertSame(['Fewer than 5', 'Fewer than 5'], self::col($report->summary('activity', true), 'learners'));
        $this->assertSame(['', ''], self::col($report->summary('activity', true), 'percent'));

        $progress = $report->progress();
        $this->assertSame([(string) $ann->id, (string) $bob->id, (string) $cat->id], self::col($progress, 'userid'));
        $this->assertSame(['50', '100', ''], self::col($progress, 'change'));

        [$table, $summary] = $report->prepost((int) $this->instance->id, (int) $second->id);
        $this->assertSame([(string) $ann->id], self::col($table, 'userid'));
        $this->assertSame(['50'], self::col($table, 'change'));
        $this->assertEquals([1, 50, 100, 1, 0], [$summary->learners, $summary->pre, $summary->post, $summary->better,
            $summary->worse]);
        $this->assertNull($report->prepost((int) $this->instance->id, (int) $this->instance->id)[1]);
        $this->assertNull($report->prepost(999999, (int) $second->id)[1]);
    }

    /**
     * Only activities whose results the viewer may see are in the report.
     *
     * @return void
     */
    public function test_cross_report_access(): void {
        global $DB;
        $this->setup_activity();
        [, $cm, $hiddencontext] = $this->add_activity('Two');
        $context = \context_course::instance($this->course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('mod/whakaharatau:viewreports', CAP_PROHIBIT, $roleid, $hiddencontext->id, true);
        $this->setUser($teacher);
        $this->assertTrue(cross_report::can_view($context));
        $this->assertFalse(cross_report::can_view(\context_system::instance()));
        $names = array_column((new cross_report($context, new report_filter()))->get_activities(), 'activityname');
        $this->assertSame(['One'], $names);
        $this->assertSame([], (new cross_report($context, new report_filter(), true))->get_activities());
        $this->assertSame([], cross_report::get_scopes($context));
        $this->setUser($student);
        $this->assertFalse(cross_report::can_view($context));
        $this->assertSame([], (new cross_report($context, new report_filter()))->get_activities());
        $this->setAdminUser();
        $this->assertCount(3, cross_report::get_scopes($context));
    }
}
