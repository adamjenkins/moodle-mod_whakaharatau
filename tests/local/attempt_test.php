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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the attempt engine: marking, tries, scoring, grading, rounds, shuffle and resume.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attempt::class)]
final class attempt_test extends \advanced_testcase {
    /**
     * Load the grade library.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * The generator for this module.
     *
     * @return \mod_whakaharatau_generator
     */
    private function modgen(): \mod_whakaharatau_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
    }

    /**
     * A course, an activity with slides (correct option: index 1 of each), and an enrolled student.
     *
     * @param array $settings instance settings
     * @param int $slidecount number of slides
     * @param int $optioncount options per slide
     * @return array [course, instance record, cm, slide records (with ->options), student]
     */
    private function setup_activity(array $settings = [], int $slidecount = 2, int $optioncount = 3): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id] + $settings);
        $slides = [];
        for ($i = 0; $i < $slidecount; $i++) {
            $slides[] = $this->modgen()->create_slide(['whakaharatauid' => $created->id, 'options' => $optioncount,
                'correct' => 1]);
        }
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $DB->get_record('whakaharatau', ['id' => $created->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('whakaharatau', $instance->id, $course->id, false, MUST_EXIST);
        return [$course, $instance, $cm, $slides, $student];
    }

    /**
     * Option ids of a generated slide in teacher order.
     *
     * @param \stdClass $slide generated slide
     * @return int[]
     */
    private function optionids(\stdClass $slide): array {
        return array_map(fn($o) => (int) $o->id, $slide->options);
    }

    /**
     * The slide id at the attempt's current position.
     *
     * @param attempt $attempt attempt
     * @return int
     */
    private function current(attempt $attempt): int {
        return $attempt->get_slideorder()[$attempt->get_position()];
    }

    /**
     * Answer the current slide (right or wrong) and return the result.
     *
     * @param attempt $attempt attempt (reloaded first)
     * @param bool $right choose the correct option
     * @return array submit_answer() result
     */
    private function answer(attempt $attempt, bool $right): array {
        return $this->modgen()->answer_current(attempt::get_by_id($attempt->get_id()), $right);
    }

    public function test_not_playable_refuses_start(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity([], 0);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notplayable', 'mod_whakaharatau'));
        attempt::start($instance, $cm, (int) $student->id);
    }

    public function test_practice_until_correct(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->assertSame(1, (int) $attempt->get_record()->attempt);
        $this->assertSame(0, $attempt->get_position());
        $this->assertSame(1, $attempt->get_round());
        $first = (int) $slides[0]->id;
        $ids = $this->optionids($slides[0]);
        $this->assertSame($first, $this->current($attempt));

        $result = $attempt->submit_answer($first, $ids[0]);
        $this->assertFalse($result['correct']);
        $this->assertFalse($result['slidefinished']);
        $this->assertNull($result['triesleft']);
        $this->assertNull($result['correctoptionid']);

        $attempt = attempt::get_by_id($attempt->get_id());
        $result = $attempt->submit_answer($first, $ids[2]);
        $this->assertFalse($result['slidefinished'], 'until correct: two wrong tries do not finish the slide');

        $attempt = attempt::get_by_id($attempt->get_id());
        $result = $attempt->submit_answer($first, $ids[1]);
        $this->assertTrue($result['correct']);
        $this->assertTrue($result['slidefinished']);
        $this->assertSame($ids[1], $result['correctoptionid']);
        $this->assertFalse($result['roundfinished']);

        $attempt = attempt::get_by_id($attempt->get_id());
        $status = $attempt->get_slide_status($first);
        $this->assertSame(3, $status['tries']);
        $this->assertSame([$ids[0], $ids[2], $ids[1]], $status['chosen']);
        $this->assertTrue($status['correct']);
        $this->assertFalse($status['firsttry']);
        $this->assertSame(1, $attempt->get_position());

        // The state shows wrong, wrong and correct rows in display order.
        $state = $attempt->get_state();
        $this->assertTrue($state['slides'][0]['correct']);
        $this->assertSame(
            [attempt::OPTION_WRONG, attempt::OPTION_CORRECT, attempt::OPTION_WRONG],
            array_column($state['slides'][0]['options'], 'state')
        );

        // Slide 2 right first time: one pass (Repeat off) ends the session.
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['roundfinished']);
        $this->assertNull($result['newround']);
        $this->assertTrue($result['attemptfinished']);
        $summary = $result['summary'];
        $this->assertTrue($summary['finished']);
        $this->assertSame(2, $summary['slidesplayed']);
        $this->assertSame(1, $summary['firsttrycorrect']);
        $this->assertEquals(1.0, $summary['score']);
        $this->assertEquals(2.0, $summary['maxscore']);
        $this->assertNull($summary['percent']);
        $this->assertNull($summary['grade']);
        $this->assertSame(1, $summary['beststreak']);
        $this->assertSame(1, $summary['missedcount']);
        $this->assertTrue($summary['canreview']);
    }

    public function test_limited_tries_reveal_the_answer(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['triesperslide' => 2, 'optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $first = (int) $slides[0]->id;
        $ids = $this->optionids($slides[0]);

        $result = $attempt->submit_answer($first, $ids[0]);
        $this->assertSame(1, $result['triesleft']);
        $this->assertFalse($result['slidefinished']);
        $result = attempt::get_by_id($attempt->get_id())->submit_answer($first, $ids[2]);
        $this->assertSame(0, $result['triesleft']);
        $this->assertTrue($result['slidefinished']);
        $this->assertFalse($result['correct']);
        $this->assertSame($ids[1], $result['correctoptionid']);

        $state = attempt::get_by_id($attempt->get_id())->get_state();
        $this->assertFalse($state['slides'][0]['correct']);
        $this->assertSame(
            [attempt::OPTION_WRONG, attempt::OPTION_REVEALED, attempt::OPTION_WRONG],
            array_column($state['slides'][0]['options'], 'state')
        );
        $this->assertSame(1, $state['position']);
    }

    public function test_submit_rules(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $first = (int) $slides[0]->id;
        $second = (int) $slides[1]->id;

        $cases = [
            'notcurrentslide' => [$second, $this->optionids($slides[1])[1]],
            'errorwrongslide' => [$first, $this->optionids($slides[1])[1]],
        ];
        foreach ($cases as $code => [$slideid, $optionid]) {
            try {
                $attempt->submit_answer($slideid, $optionid);
                $this->fail("Exception $code expected");
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }

        $attempt->submit_answer($first, $this->optionids($slides[0])[0]);
        try {
            attempt::get_by_id($attempt->get_id())->submit_answer($first, $this->optionids($slides[0])[0]);
            $this->fail('Exception optionalreadychosen expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('optionalreadychosen', $e->errorcode);
        }

        $attempt = attempt::get_by_id($attempt->get_id());
        $attempt->finish();
        $this->assertSame($attempt->get_summary(), $attempt->finish(), 'finish() is idempotent');
        try {
            $attempt->submit_answer($first, $this->optionids($slides[0])[1]);
            $this->fail('Exception attemptfinished expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptfinished', $e->errorcode);
        }
    }

    public function test_test_mode_first_try_scoring_and_grade(): void {
        $this->resetAfterTest();
        [$course, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST], 4);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $results = [];
        foreach ([true, false, true, false] as $right) {
            $results[] = $this->answer($attempt, $right);
        }
        // One try in a test: a wrong choice finishes the slide and reveals the answer.
        $this->assertTrue($results[1]['slidefinished']);
        $this->assertNotNull($results[1]['correctoptionid']);
        $this->assertTrue($results[3]['attemptfinished']);

        $summary = $results[3]['summary'];
        $this->assertEquals(2.0, $summary['score']);
        $this->assertEquals(4.0, $summary['maxscore']);
        $this->assertEquals(50.0, $summary['percent']);
        $this->assertEquals(50.0, $summary['grade']);
        $this->assertSame([1.0, 0.0, 1.0, 0.0], array_column($summary['slides'], 'score'));

        $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $instance->id, $student->id);
        $this->assertEquals(50.0, (float) $grades->items[0]->grades[$student->id]->grade);
    }

    public function test_test_mode_partial_credit(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'triesperslide' => 4, 'slidescore' => instance_settings::SLIDESCORE_PARTIAL], 3, 4);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        // Slide 1: right first try = 1. Slide 2: right on try 2 of 4 options = 2/3. Slide 3: right on try 4 = 0.
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $this->answer($attempt, false);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['attemptfinished']);
        $scores = array_column($result['summary']['slides'], 'score');
        $this->assertEqualsWithDelta([1.0, 2 / 3, 0.0], $scores, 0.00001);
        $this->assertEqualsWithDelta(5 / 3, $result['summary']['score'], 0.00001);
        $this->assertEquals(3.0, $result['summary']['maxscore']);
    }

    public function test_test_mode_first_try_only_gives_nothing_for_later_tries(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'triesperslide' => 3, 'slidescore' => instance_settings::SLIDESCORE_FIRSTTRY], 1, 4);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['correct']);
        $this->assertEquals(0.0, $result['summary']['score']);
    }

    public function test_test_mode_scoring_by_slides_completed(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'scoring' => instance_settings::SCORING_COMPLETED]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, false);
        // As the SCORM package did: every finished slide counts, right or not.
        $this->assertEquals(2.0, $result['summary']['score']);
        $this->assertEquals(100.0, $result['summary']['percent']);

        // An unanswered slide counts nothing when the attempt is finished early.
        $second = attempt::start($instance, $cm, (int) $student->id, true);
        $this->answer($second, false);
        $summary = attempt::get_by_id($second->get_id())->finish();
        $this->assertEquals(1.0, $summary['score']);
        $this->assertEquals(2.0, $summary['maxscore']);
    }

    public function test_aggregate_grading_methods(): void {
        $rows = [
            (object) ['score' => 1, 'maxscore' => 2],
            (object) ['score' => 4, 'maxscore' => 4],
            (object) ['score' => 1, 'maxscore' => 4],
        ];
        $this->assertEqualsWithDelta(1.0, attempt::aggregate($rows, instance_settings::GRADE_HIGHEST), 0.00001);
        $this->assertEqualsWithDelta(1.75 / 3, attempt::aggregate($rows, instance_settings::GRADE_AVERAGE), 0.00001);
        $this->assertEqualsWithDelta(0.5, attempt::aggregate($rows, instance_settings::GRADE_FIRST), 0.00001);
        $this->assertEqualsWithDelta(0.25, attempt::aggregate($rows, instance_settings::GRADE_LAST), 0.00001);
        $this->assertSame(0.0, attempt::aggregate([], instance_settings::GRADE_HIGHEST));
    }

    public function test_grading_methods_reach_the_gradebook(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'grademethod' => instance_settings::GRADE_AVERAGE, 'grade' => 10], 2);
        // Attempt 1: 2/2. Attempt 2: 0/2. Attempt 3: 1/2.
        foreach ([[true, true], [false, false], [true, false]] as $answers) {
            $attempt = attempt::start($instance, $cm, (int) $student->id, true);
            foreach ($answers as $right) {
                $this->answer($attempt, $right);
            }
        }
        $expected = [
            instance_settings::GRADE_AVERAGE => 5.0,
            instance_settings::GRADE_HIGHEST => 10.0,
            instance_settings::GRADE_FIRST => 10.0,
            instance_settings::GRADE_LAST => 5.0,
        ];
        foreach ($expected as $method => $grade) {
            $DB->set_field('whakaharatau', 'grademethod', $method, ['id' => $instance->id]);
            $instance = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
            $this->assertEqualsWithDelta($grade, attempt::get_user_grades($instance, (int) $student->id)[$student->id]
                ->rawgrade, 0.00001, "method $method");
            whakaharatau_update_grades($instance, (int) $student->id);
            $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $instance->id, $student->id);
            $this->assertEqualsWithDelta($grade, (float) $grades->items[0]->grades[$student->id]->grade, 0.00001);
        }
    }

    public function test_attempt_limit(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'attempts' => 2]);
        $userid = (int) $student->id;
        $this->assertSame(2, attempt::attempts_left($instance, $userid));

        $first = attempt::start($instance, $cm, $userid);
        // The attempt in progress counts as used, but can be resumed.
        $this->assertSame(1, attempt::attempts_left($instance, $userid));
        $this->assertSame($first->get_id(), attempt::start($instance, $cm, $userid)->get_id());

        // Choosing "Start again" finishes (scores) the first attempt; a test can't be restarted for free.
        $second = attempt::start($instance, $cm, $userid, true);
        $this->assertNotSame($first->get_id(), $second->get_id());
        $this->assertTrue(attempt::get_by_id($first->get_id())->is_finished());
        $this->assertSame(2, (int) $second->get_record()->attempt);
        $this->assertSame(0, attempt::attempts_left($instance, $userid));
        $this->assertFalse(attempt::can_start_new($instance, $userid));

        $second->finish();
        $this->assertFalse(attempt::get_by_id($second->get_id())->get_summary()['canretry']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nomoreattempts', 'mod_whakaharatau'));
        attempt::start($instance, $cm, $userid, true);
    }

    public function test_practice_attempts_are_unlimited(): void {
        $this->resetAfterTest();
        [, $instance, , , $student] = $this->setup_activity(['attempts' => 1]);
        $this->assertNull(attempt::attempts_left($instance, (int) $student->id));
        $this->assertTrue(attempt::can_start_new($instance, (int) $student->id));
    }

    public function test_resume_keeps_position(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['shuffle' => 1], 4);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, true);
        $resumed = attempt::start($instance, $cm, (int) $student->id);
        $this->assertSame($attempt->get_id(), $resumed->get_id());
        $this->assertSame(1, $resumed->get_position());
        $this->assertSame($attempt->get_slideorder(), $resumed->get_slideorder());
        $this->assertNotNull(attempt::get_inprogress($instance, $cm, (int) $student->id));
        $this->assertNull(attempt::get_latest_finished($instance, $cm, (int) $student->id));
    }

    public function test_shuffle_frozen_per_round(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['shuffle' => 1,
            'optionorder' => instance_settings::OPTIONORDER_RANDOM, 'repeatmode' => instance_settings::REPEAT_ALL], 6, 4);
        $teacherorder = array_map(fn($s) => (int) $s->id, $slides);

        $slidesdiffer = false;
        $optionsdiffer = false;
        for ($i = 0; $i < 10 && !($slidesdiffer && $optionsdiffer); $i++) {
            $attempt = attempt::start($instance, $cm, (int) $student->id, true);
            $order = $attempt->get_slideorder();
            $sorted = $order;
            sort($sorted);
            $this->assertSame($teacherorder, $sorted, 'every slide once');

            // Reloads and resumes never reshuffle.
            $reloaded = attempt::get_by_id($attempt->get_id());
            $this->assertSame($order, $reloaded->get_slideorder());
            $this->assertSame($order, attempt::start($instance, $cm, (int) $student->id)->get_slideorder());
            foreach ($slides as $slide) {
                $options = $reloaded->get_optionorder((int) $slide->id);
                $this->assertSame($attempt->get_optionorder((int) $slide->id), $options);
                $sortedoptions = $options;
                sort($sortedoptions);
                $this->assertSame($this->optionids($slide), $sortedoptions);
                $optionsdiffer = $optionsdiffer || $options !== $this->optionids($slide);
            }
            $slidesdiffer = $slidesdiffer || $order !== $teacherorder;
        }
        $this->assertTrue($slidesdiffer, 'Shuffle on: a slide order differs from the teacher order');
        $this->assertTrue($optionsdiffer, 'Random option order: an option order differs from the teacher order');

        // The order of the current round is kept while it is played, and round 2 has its own frozen order.
        $attempt = attempt::start($instance, $cm, (int) $student->id, true);
        $roundone = $attempt->get_slideorder();
        $result = [];
        for ($i = 0; $i < count($roundone); $i++) {
            $this->assertSame($roundone, attempt::get_by_id($attempt->get_id())->get_slideorder());
            $result = $this->answer($attempt, true);
        }
        $this->assertSame(2, $result['newround']);
        $roundtwo = attempt::get_by_id($attempt->get_id());
        $this->assertSame(2, $roundtwo->get_round());
        $sorted = $roundtwo->get_slideorder();
        sort($sorted);
        $this->assertSame($teacherorder, $sorted);
        $this->assertSame($roundtwo->get_slideorder(), attempt::get_by_id($attempt->get_id())->get_slideorder());
    }

    public function test_fixed_option_order(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['optionorder' => instance_settings::OPTIONORDER_FIXED,
            'shuffle' => 0], 3, 5);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->assertSame(array_map(fn($s) => (int) $s->id, $slides), $attempt->get_slideorder());
        foreach ($slides as $slide) {
            $this->assertSame($this->optionids($slide), $attempt->get_optionorder((int) $slide->id));
        }
    }

    public function test_repeat_all_rounds(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['repeatmode' => instance_settings::REPEAT_ALL]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['roundfinished']);
        $this->assertSame(2, $result['newround']);
        $this->assertFalse($result['attemptfinished']);
        $this->assertNull($result['summary']);

        $attempt = attempt::get_by_id($attempt->get_id());
        $state = $attempt->get_state();
        $this->assertSame(2, $state['round']);
        $this->assertSame(0, $state['position']);
        $this->assertSame(2, $state['slidecount']);
        $this->assertFalse($state['roundcomplete']);
        $this->assertSame(instance_settings::REPEAT_ALL, $state['repeatmode']);
        foreach ($state['slides'] as $slide) {
            $this->assertFalse($slide['finished']);
            $this->assertSame(0, $slide['tries']);
        }

        // The summary works on the unfinished session (the exit X in Repeat All).
        $summary = $attempt->get_summary();
        $this->assertFalse($summary['finished']);
        $this->assertSame(2, $summary['slidesplayed']);
        $this->assertSame(1, $summary['firsttrycorrect']);

        // Round 2 all right: round 3 starts; Repeat All never ends by itself.
        $this->answer($attempt, true);
        $result = $this->answer($attempt, true);
        $this->assertSame(3, $result['newround']);
        $summary = attempt::get_by_id($attempt->get_id())->finish();
        $this->assertTrue($summary['finished']);
        $this->assertSame(2, $summary['rounds']);
        $this->assertSame(4, $summary['slidesplayed']);
        $this->assertSame(3, $summary['firsttrycorrect']);
        $this->assertSame(2, $summary['beststreak']);
        $this->assertSame(0, $summary['missedcount'], 'slide 2 was right first time in its latest round');
    }

    public function test_repeat_missed_shrinks_until_complete(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['repeatmode' => instance_settings::REPEAT_MISSED,
            'shuffle' => 0], 3);
        $ids = array_map(fn($s) => (int) $s->id, $slides);
        $attempt = attempt::start($instance, $cm, (int) $student->id);

        // Round 1: right, missed, missed.
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, true);
        $this->assertSame(2, $result['newround']);
        $this->assertSame([$ids[1], $ids[2]], attempt::get_by_id($attempt->get_id())->get_slideorder());

        // Round 2: right, missed.
        $this->answer($attempt, true);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, true);
        $this->assertSame(3, $result['newround']);
        $this->assertSame([$ids[2]], attempt::get_by_id($attempt->get_id())->get_slideorder());

        // Round 3: right first time, nothing missed: the session ends.
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['roundfinished']);
        $this->assertNull($result['newround']);
        $this->assertTrue($result['attemptfinished']);
        $this->assertSame(0, $result['summary']['missedcount']);
        $this->assertSame(3, $result['summary']['rounds']);
        $this->assertSame([], attempt::get_by_id($attempt->get_id())->get_missed_slideids());
    }

    public function test_repeat_missed_ends_when_all_right_first_time(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['repeatmode' => instance_settings::REPEAT_MISSED]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, true);
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['attemptfinished']);
        $this->assertSame(1, $result['summary']['rounds']);
    }

    public function test_test_mode_never_repeats(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST]);
        // Even with a stored Repeat (not possible through the form), a test ends after one pass.
        $DB->set_field('whakaharatau', 'repeatmode', instance_settings::REPEAT_ALL, ['id' => $instance->id]);
        $instance->repeatmode = instance_settings::REPEAT_ALL;
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->assertSame(instance_settings::REPEAT_OFF, (int) $attempt->get_record()->repeatmode);
        $this->answer($attempt, false);
        $result = $this->answer($attempt, false);
        $this->assertTrue($result['attemptfinished']);
    }

    public function test_practise_missed_slides(): void {
        $this->resetAfterTest();
        [$course, $instance, $cm, $slides, $student] = $this->setup_activity([], 3);
        $ids = array_map(fn($s) => (int) $s->id, $slides);
        $session = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'wrongthenright', 'right']]);
        $this->assertTrue($session->is_finished());
        $this->assertSame([$ids[1]], $session->get_missed_slideids());

        $missed = attempt::start($instance, $cm, (int) $student->id, false, $session->get_id());
        $this->assertSame([$ids[1]], $missed->get_slideorder());
        $this->assertSame(instance_settings::REPEAT_MISSED, (int) $missed->get_record()->repeatmode);
        $this->assertSame(2, (int) $missed->get_record()->attempt);

        // A session with nothing missed, or someone else's session, gives nothing to practise.
        $clean = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'forcenew' => true, 'answers' => ['right', 'right', 'right']]);
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach ([[$student, $clean->get_id()], [$other, $session->get_id()]] as [$user, $from]) {
            try {
                attempt::start($instance, $cm, (int) $user->id, false, $from);
                $this->fail('Exception nomissedslides expected');
            } catch (\moodle_exception $e) {
                $this->assertSame('nomissedslides', $e->errorcode);
            }
        }
    }

    public function test_practice_creates_no_grade(): void {
        $this->resetAfterTest();
        [$course, $instance, , , $student] = $this->setup_activity();
        $attempt = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'right']]);
        $this->assertTrue($attempt->is_finished());
        $this->assertSame([], attempt::get_user_grades($instance));
        $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $instance->id, $student->id);
        $this->assertEmpty($grades->items);
        $this->assertNull($attempt->get_summary()['grade']);
    }

    public function test_feedback_only_at_the_end(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST,
            'feedbackmode' => instance_settings::FEEDBACK_END, 'triesperslide' => 3, 'optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->assertFalse($attempt->shows_feedback());
        $first = (int) $slides[0]->id;
        $ids = $this->optionids($slides[0]);

        $result = $attempt->submit_answer($first, $ids[0]);
        $this->assertFalse($result['feedback']);
        $this->assertNull($result['correct']);
        $this->assertNull($result['correctoptionid']);
        $this->assertTrue($result['slidefinished'], 'feedback at the end allows one try only');

        $state = attempt::get_by_id($attempt->get_id())->get_state();
        $this->assertNull($state['slides'][0]['correct']);
        $this->assertSame(
            [attempt::OPTION_CHOSEN, attempt::OPTION_NONE, attempt::OPTION_NONE],
            array_column($state['slides'][0]['options'], 'state')
        );
        $this->assertStringNotContainsString('"correctoptionid"', json_encode($state));

        // The review, once finished, reveals the answers.
        $attempt = attempt::get_by_id($attempt->get_id());
        try {
            $attempt->get_review();
            $this->fail('Exception attemptnotfinished expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptnotfinished', $e->errorcode);
        }
        $this->assertFalse($attempt->can_review());
        $this->answer($attempt, true);
        $attempt = attempt::get_by_id($attempt->get_id());
        $this->assertTrue($attempt->is_finished());
        $this->assertTrue($attempt->can_review());
        $review = $attempt->get_review();
        $this->assertCount(2, $review);
        $this->assertSame($ids[1], $review[0]['correctoptionid']);
        $this->assertSame([$ids[0]], $review[0]['chosen']);
        $this->assertFalse($review[0]['correct']);
        $this->assertTrue($review[1]['correct']);
    }

    public function test_review_not_allowed(): void {
        $this->resetAfterTest();
        [, $instance, , , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST, 'allowreview' => 0]);
        $attempt = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'right']]);
        $this->assertTrue($attempt->is_finished());
        $this->assertFalse($attempt->can_review());
        $this->assertFalse($attempt->get_summary()['canreview']);
    }

    public function test_slide_data_never_says_which_option_is_correct(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $first = (int) $slides[0]->id;

        $data = $attempt->get_slide_data($first);
        $this->assertSame(['slideid', 'imageurl', 'alttext', 'decorative', 'prompthtml', 'promptlang', 'promptaudiourl',
            'options'], array_keys($data));
        $this->assertSame(['optionid', 'number', 'audiourl', 'transcript'], array_keys($data['options'][0]));
        $json = json_encode($data);
        $this->assertStringNotContainsString('iscorrect', $json);
        $this->assertStringNotContainsString('correct', $json);
        $this->assertNotEmpty($data['imageurl']);
        $this->assertSame([null, null, null], array_column($data['options'], 'transcript'));

        // After the slide is finished the transcripts may be shown.
        $this->answer($attempt, true);
        $data = attempt::get_by_id($attempt->get_id())->get_slide_data($first);
        $this->assertSame(['Clip 1', 'Clip 2', 'Clip 3'], array_column($data['options'], 'transcript'));

        // A slide of another activity is refused.
        [, $otherinstance] = $this->setup_activity([], 1);
        $foreign = array_keys(slides::get_slides((int) $otherinstance->id))[0];
        $this->expectException(\moodle_exception::class);
        $attempt->get_slide_data((int) $foreign);
    }

    public function test_transcripts_before_answering(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['transcriptsbefore' => 1, 'optionorder' => 0]);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $data = $attempt->get_slide_data((int) $slides[0]->id);
        $this->assertSame(['Clip 1', 'Clip 2', 'Clip 3'], array_column($data['options'], 'transcript'));
    }

    public function test_decorative_slide_has_empty_alt(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity([], 0);
        $slide = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'decorative' => 1,
            'alttext' => 'ignored', 'prompt' => 'He aha tēnei?', 'promptlang' => 'mi']);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $data = $attempt->get_slide_data((int) $slide->id);
        $this->assertSame('', $data['alttext']);
        $this->assertTrue($data['decorative']);
        $this->assertStringContainsString('He aha tēnei?', $data['prompthtml']);
        $this->assertSame('mi', $data['promptlang']);
    }

    public function test_mode_switch_finishes_the_other_mode_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity();
        $practice = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($practice, true);

        $DB->set_field('whakaharatau', 'mode', instance_settings::MODE_TEST, ['id' => $instance->id]);
        $instance->mode = instance_settings::MODE_TEST;
        $test = attempt::start($instance, $cm, (int) $student->id);
        $this->assertNotSame($practice->get_id(), $test->get_id());
        $this->assertTrue($test->is_test());
        $this->assertTrue(attempt::get_by_id($practice->get_id())->is_finished());
        // The Practice session does not count as a Test attempt.
        $this->assertSame(1, attempt::count_test_attempts((int) $instance->id, (int) $student->id));
        $this->assertCount(1, attempt::get_attempts(
            (int) $instance->id,
            (int) $student->id,
            instance_settings::MODE_PRACTICE,
            true
        ));
    }

    public function test_deleted_slide_is_skipped(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $slides, $student] = $this->setup_activity(['shuffle' => 0], 3);
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $this->answer($attempt, true);
        slides::delete_slide(\context_module::instance($cm->id), (int) $slides[1]->id);

        $attempt = attempt::get_by_id($attempt->get_id());
        $this->assertSame([(int) $slides[0]->id, (int) $slides[2]->id], $attempt->get_slideorder());
        $this->assertSame(1, $attempt->get_position());
        $result = $this->answer($attempt, true);
        $this->assertTrue($result['attemptfinished']);
        $this->assertCount(2, attempt::get_by_id($attempt->get_id())->get_review());
    }

    public function test_delete_attempts_and_has_answered_all(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $instance, , , $student] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertFalse(attempt::has_answered_all_slides((int) $instance->id, (int) $student->id));

        $mine = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);
        $this->assertFalse(attempt::has_answered_all_slides((int) $instance->id, (int) $student->id));
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['wrong']]);
        $this->assertTrue(
            attempt::has_answered_all_slides((int) $instance->id, (int) $student->id),
            'one answer on each slide is enough, right or not'
        );
        $theirs = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $other->id,
            'answers' => ['right', 'right']]);

        attempt::delete_all_attempts((int) $instance->id, (int) $student->id);
        $this->assertFalse($DB->record_exists('whakaharatau_attempt', ['id' => $mine->get_id()]));
        $this->assertFalse($DB->record_exists('whakaharatau_response', ['attemptid' => $mine->get_id()]));
        $this->assertTrue($DB->record_exists('whakaharatau_attempt', ['id' => $theirs->get_id()]));
        $this->assertTrue(attempt::has_answered_all_slides((int) $instance->id, (int) $other->id));

        attempt::delete_attempt($theirs->get_id());
        $this->assertSame(0, $DB->count_records('whakaharatau_response'));
        $this->assertSame(0, $DB->count_records('whakaharatau_attempt'));
    }

    public function test_get_latest_finished(): void {
        $this->resetAfterTest();
        [, $instance, $cm, , $student] = $this->setup_activity();
        $first = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'right']]);
        $second = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['wrongthenright', 'right']]);
        $this->assertNotSame($first->get_id(), $second->get_id());
        $latest = attempt::get_latest_finished($instance, $cm, (int) $student->id);
        $this->assertSame($second->get_id(), $latest->get_id());
        $this->assertNull(attempt::get_latest_finished($instance, $cm, (int) $student->id, instance_settings::MODE_TEST));
    }
}
