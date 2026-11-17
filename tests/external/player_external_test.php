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

use core_external\external_api;
use mod_whakaharatau\local\instance_settings;

/**
 * Tests of the player's external functions: capability checks, ownership, and that the correct option
 * never reaches the browser before the slide is finished.
 *
 * @package    mod_whakaharatau
 * @category   external
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_whakaharatau\external\start_attempt
 * @covers     \mod_whakaharatau\external\get_attempt_state
 * @covers     \mod_whakaharatau\external\submit_answer
 * @covers     \mod_whakaharatau\external\finish_attempt
 * @covers     \mod_whakaharatau\external\player_api
 */
final class player_external_test extends \core_external\tests\externallib_testcase {
    /**
     * A course, an activity with two 3-option slides (correct: option index 1), a student and a teacher.
     *
     * @param array $settings instance settings
     * @return array [course, instance, slides, student, teacher]
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $instance = $generator->create_module('whakaharatau', ['course' => $course->id] + $settings);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $generator->get_plugin_generator('mod_whakaharatau');
        $slides = [
            $modgen->create_slide(['whakaharatauid' => $instance->id, 'options' => 3, 'correct' => 1]),
            $modgen->create_slide(['whakaharatauid' => $instance->id, 'options' => 3, 'correct' => 1]),
        ];
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        return [$course, $instance, $slides, $student, $teacher];
    }

    /**
     * Call start_attempt and clean the result as the web service layer does.
     *
     * @param int $cmid course module id
     * @param bool $forcenew start a new attempt
     * @return array
     */
    private function start(int $cmid, bool $forcenew = false): array {
        $result = start_attempt::execute($cmid, $forcenew, 0);
        return external_api::clean_returnvalue(start_attempt::execute_returns(), $result);
    }

    /**
     * Call submit_answer and clean the result.
     *
     * @param int $attemptid attempt id
     * @param int $slideid slide id
     * @param int $optionid option id
     * @return array
     */
    private function submit(int $attemptid, int $slideid, int $optionid): array {
        $result = submit_answer::execute($attemptid, $slideid, $optionid);
        return external_api::clean_returnvalue(submit_answer::execute_returns(), $result);
    }

    /**
     * The slide id at the current position and the option ids of a slide by their generator index.
     *
     * @param array $state get_state()
     * @param array $slides generator slides
     * @return array [slideid, optionids by index (teacher order)]
     */
    private function current_slide(array $state, array $slides): array {
        $slideid = $state['slides'][$state['position']]['slideid'];
        foreach ($slides as $slide) {
            if ((int) $slide->id === $slideid) {
                return [$slideid, array_map(fn($o) => (int) $o->id, $slide->options)];
            }
        }
        $this->fail('Slide not found');
    }

    public function test_start_attempt_sends_no_answer(): void {
        $this->resetAfterTest();
        [, $instance, , $student] = $this->setup_activity();
        $this->setUser($student);

        $result = $this->start((int) $instance->cmid);
        $json = json_encode($result);
        $this->assertStringNotContainsString('iscorrect', $json);
        $this->assertStringNotContainsString('correctoptionid', $json);
        $this->assertCount(2, $result['state']['slides']);
        $this->assertSame(0, $result['state']['position']);
        foreach ($result['state']['slides'] as $slide) {
            $this->assertNull($slide['correct']);
            foreach ($slide['options'] as $option) {
                $this->assertSame('none', $option['state']);
            }
        }
        // The current slide and the next one, for preloading.
        $this->assertCount(2, $result['slides']);
        foreach ($result['slides'][0]['options'] as $option) {
            $this->assertNotEmpty($option['audiourl']);
            // Transcripts are hidden before answering unless the teacher shows them.
            $this->assertNull($option['transcript']);
        }
    }

    public function test_submit_answer_marks_on_server(): void {
        $this->resetAfterTest();
        [, $instance, $slides, $student] = $this->setup_activity();
        $this->setUser($student);

        $start = $this->start((int) $instance->cmid);
        $attemptid = $start['state']['attemptid'];
        [$slideid, $optionids] = $this->current_slide($start['state'], $slides);

        // Wrong (Practice: until correct): no correct option revealed.
        $wrong = $this->submit($attemptid, $slideid, $optionids[0]);
        $this->assertFalse($wrong['correct']);
        $this->assertFalse($wrong['slidefinished']);
        $this->assertNull($wrong['correctoptionid']);
        $this->assertNull($wrong['summary']);

        // The same option can't be chosen twice.
        try {
            $this->submit($attemptid, $slideid, $optionids[0]);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('optionalreadychosen', $e->errorcode);
        }

        // Right: the slide is finished and the transcripts may now be shown.
        $right = $this->submit($attemptid, $slideid, $optionids[1]);
        $this->assertTrue($right['correct']);
        $this->assertTrue($right['slidefinished']);
        $this->assertSame($optionids[1], $right['correctoptionid']);
        $this->assertSame(1, $right['state']['position']);
        $this->assertSame($slideid, $right['slides'][0]['slideid']);
        $this->assertNotNull($right['slides'][0]['options'][0]['transcript']);
    }

    public function test_test_mode_feedback_at_end_hides_result(): void {
        $this->resetAfterTest();
        [, $instance, $slides, $student] = $this->setup_activity([
            'mode' => instance_settings::MODE_TEST,
            'feedbackmode' => instance_settings::FEEDBACK_END,
        ]);
        $this->setUser($student);

        $start = $this->start((int) $instance->cmid);
        $attemptid = $start['state']['attemptid'];
        [$slideid, $optionids] = $this->current_slide($start['state'], $slides);
        $result = $this->submit($attemptid, $slideid, $optionids[0]);
        $this->assertFalse($result['feedback']);
        $this->assertNull($result['correct']);
        $this->assertNull($result['correctoptionid']);
        $this->assertTrue($result['slidefinished']);
        $this->assertNull($result['state']['slides'][0]['correct']);
        $this->assertSame('chosen', $result['state']['slides'][0]['options'][array_search(
            $optionids[0],
            array_column($result['state']['slides'][0]['options'], 'optionid')
        )]['state']);
    }

    public function test_attempt_belongs_to_its_owner(): void {
        $this->resetAfterTest();
        [$course, $instance, $slides, $student] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($student);
        $start = $this->start((int) $instance->cmid);
        [$slideid, $optionids] = $this->current_slide($start['state'], $slides);

        $this->setUser($other);
        foreach (['state', 'submit', 'finish'] as $call) {
            try {
                if ($call === 'state') {
                    get_attempt_state::execute($start['state']['attemptid'], []);
                } else if ($call === 'submit') {
                    submit_answer::execute($start['state']['attemptid'], $slideid, $optionids[1]);
                } else {
                    finish_attempt::execute($start['state']['attemptid'], true);
                }
                $this->fail("Exception expected for $call");
            } catch (\moodle_exception $e) {
                $this->assertSame('errornotyourattempt', $e->errorcode);
            }
        }
    }

    public function test_start_attempt_needs_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $instance] = $this->setup_activity();
        $context = \context_module::instance($instance->cmid);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/whakaharatau:attempt', CAP_PROHIBIT, $studentroleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/whakaharatau:attempt', $context, $user));

        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        start_attempt::execute((int) $instance->cmid, false, 0);
    }

    public function test_teacher_preview_allowed(): void {
        $this->resetAfterTest();
        [, $instance, , , $teacher] = $this->setup_activity();
        $this->setUser($teacher);
        $result = $this->start((int) $instance->cmid);
        $this->assertGreaterThan(0, $result['state']['attemptid']);
    }

    public function test_finish_attempt_review_only_when_allowed(): void {
        $this->resetAfterTest();
        [, $instance, $slides, $student] = $this->setup_activity([
            'mode' => instance_settings::MODE_TEST,
            'allowreview' => 0,
        ]);
        $this->setUser($student);
        $start = $this->start((int) $instance->cmid);
        $attemptid = $start['state']['attemptid'];

        $result = finish_attempt::execute($attemptid, true);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), $result);
        $this->assertTrue($result['summary']['finished']);
        $this->assertFalse($result['summary']['canreview']);
        $this->assertArrayNotHasKey('review', $result);
        $this->assertCount(2, $result['images']);

        // An unfinished Test attempt reveals nothing, and a finished one can't be answered.
        try {
            [$slideid, $optionids] = $this->current_slide($start['state'], $slides);
            submit_answer::execute($attemptid, $slideid, $optionids[1]);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptfinished', $e->errorcode);
        }
    }

    public function test_finish_attempt_with_review(): void {
        $this->resetAfterTest();
        [, $instance, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST]);
        $this->setUser($student);
        $start = $this->start((int) $instance->cmid);
        $result = finish_attempt::execute($start['state']['attemptid'], true);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), $result);
        $this->assertTrue($result['summary']['canreview']);
        $this->assertCount(2, $result['review']);
        // Finished without answering while more attempts are allowed: the key stays hidden.
        $this->assertNull($result['review'][0]['correctoptionid']);
        $this->assertNull($result['review'][1]['correctoptionid']);
    }

    public function test_finish_attempt_review_reveals_when_no_attempts_left(): void {
        $this->resetAfterTest();
        [, $instance, , $student] = $this->setup_activity(['mode' => instance_settings::MODE_TEST, 'attempts' => 1]);
        $this->setUser($student);
        $start = $this->start((int) $instance->cmid);
        $result = finish_attempt::execute($start['state']['attemptid'], true);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), $result);
        $this->assertCount(2, $result['review']);
        $this->assertNotNull($result['review'][0]['correctoptionid']);
    }

    public function test_renderer_renders_player(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, $instance, , $student] = $this->setup_activity();
        $this->setUser($student);
        [, $cm] = get_course_and_cm_from_cmid($instance->cmid, 'whakaharatau');
        $PAGE->set_url('/mod/whakaharatau/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm, $course);
        /** @var \mod_whakaharatau\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('mod_whakaharatau');

        $html = $renderer->render_player($instance, $cm, false);
        $this->assertStringContainsString('data-cmid="' . $cm->id . '"', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringNotContainsString('[[', $html);

        $inline = $renderer->render_inline_player($cm);
        $this->assertStringContainsString('mod-whakaharatau-player-inline', $inline);
        $this->assertStringNotContainsString('[[', $inline);
    }

    public function test_templates_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');
        $renderer = $PAGE->get_renderer('core');
        $option = ['optionid' => 1, 'number' => 1, 'audiourl' => 'https://example.com/a.mp3', 'transcript' => 'Kia ora',
            'playlabel' => 'Play', 'pauselabel' => 'Pause', 'chooselabel' => 'Choose'];
        $html = $renderer->render_from_template('mod_whakaharatau/player_slide', [
            'idprefix' => 'p', 'slideid' => 1, 'slidelabel' => 'Slide 1 of 1', 'showprogress' => true, 'number' => 1,
            'count' => 1, 'percent' => 100, 'imageurl' => 'https://example.com/i.png', 'alttext' => 'A <b>', 'prompthtml' => '',
            'promptlang' => '', 'promptaudiourl' => '', 'promptplaylabel' => 'P', 'promptpauselabel' => 'Q',
            'exitlabel' => 'Exit', 'nextlabel' => 'Next', 'options' => [$option],
        ]);
        $this->assertStringContainsString('alt="A &lt;b&gt;"', $html);
        $this->assertStringNotContainsString('[[', $html);

        $html = $renderer->render_from_template('mod_whakaharatau/end_screen', [
            'idprefix' => 'p', 'heading' => 'Your result', 'istest' => true, 'scoretext' => '1 / 2', 'percenttext' => '50%',
            'scorelabel' => 'x', 'thumbs' => [['imageurl' => '', 'label' => 'Slide 1: correct', 'correct' => true]],
            'stats' => [], 'showreview' => true, 'showtryagain' => true, 'showgoagain' => true, 'showmissed' => true,
            'backlabel' => 'Back', 'exitlabel' => 'Exit',
        ]);
        $this->assertStringContainsString('data-action="review"', $html);
        $this->assertStringNotContainsString('[[', $html);

        $html = $renderer->render_from_template('mod_whakaharatau/player_round_banner', [
            'idprefix' => 'p', 'roundlabel' => 'Round 2', 'detail' => 'Again', 'exitlabel' => 'Exit', 'nextlabel' => 'Go',
        ]);
        $this->assertStringContainsString('Round 2', $html);

        $html = $renderer->render_from_template('mod_whakaharatau/player_review', [
            'idprefix' => 'p', 'heading' => 'Review answers', 'exitlabel' => 'Exit', 'items' => [[
                'slideid' => 1, 'label' => 'Slide 1', 'imageurl' => '', 'alttext' => '', 'prompthtml' => '', 'promptlang' => '',
                'options' => [$option + ['state' => 'correct', 'markicon' => 'tick', 'marklabel' => 'Option 1: correct']],
            ]],
        ]);
        $this->assertStringContainsString('mod-whakaharatau-icon-tick', $html);
        $this->assertStringNotContainsString('[[', $html);
    }
}
