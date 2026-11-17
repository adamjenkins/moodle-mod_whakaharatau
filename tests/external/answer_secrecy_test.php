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
use mod_whakaharatau\local\slides;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Walks whole attempts through the web service layer and checks that no response reveals the correct
 * option of a slide before that slide is finished, and that writes need the attempt capability.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(start_attempt::class)]
#[CoversClass(get_attempt_state::class)]
#[CoversClass(submit_answer::class)]
#[CoversClass(finish_attempt::class)]
#[CoversClass(player_api::class)]
final class answer_secrecy_test extends \core_external\tests\externallib_testcase {
    /**
     * A course, an activity with three 4-option slides and a student.
     *
     * @param array $settings instance settings
     * @return array [course, instance, student]
     */
    private function setup_activity(array $settings): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $instance = $generator->create_module('whakaharatau', ['course' => $course->id] + $settings);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $generator->get_plugin_generator('mod_whakaharatau');
        foreach ([0, 2, 3] as $correct) {
            $modgen->create_slide(['whakaharatauid' => $instance->id, 'options' => 4, 'correct' => $correct]);
        }
        $student = $generator->create_and_enrol($course, 'student');
        return [$course, $instance, $student];
    }

    /**
     * Assert that a cleaned response says nothing about the correct option of the unfinished slides.
     *
     * @param array $response cleaned response with 'state' and optionally 'slides'
     * @param int[] $finished ids of slides finished so far
     * @return void
     */
    private function assert_no_leak(array $response, array $finished): void {
        foreach ($response['state']['slides'] as $slide) {
            if (in_array($slide['slideid'], $finished, true)) {
                continue;
            }
            $this->assertNull($slide['correct'], 'unfinished slide ' . $slide['slideid']);
            foreach ($slide['options'] as $option) {
                $this->assertContains($option['state'], ['none', 'wrong', 'chosen']);
            }
        }
        foreach ($response['slides'] ?? [] as $slide) {
            $this->assertArrayNotHasKey('iscorrect', $slide);
            foreach ($slide['options'] as $option) {
                $this->assertSame(['optionid', 'number', 'audiourl', 'transcript'], array_keys($option));
            }
        }
        $this->assertStringNotContainsString('iscorrect', json_encode($response));
    }

    /**
     * Play a whole attempt through the externals: two wrong tries then the right one on each slide.
     *
     * @param array $settings instance settings
     * @return void
     */
    private function play_through(array $settings): void {
        [, $instance, $student] = $this->setup_activity($settings);
        $this->setUser($student);
        $response = external_api::clean_returnvalue(
            start_attempt::execute_returns(),
            start_attempt::execute((int) $instance->cmid, false, 0)
        );
        $attemptid = $response['state']['attemptid'];
        $finished = [];
        $this->assert_no_leak($response, $finished);

        $guard = 0;
        while (!$response['state']['finished'] && $guard++ < 20) {
            $state = $response['state'];
            $slide = $state['slides'][$state['position']];
            $correctid = slides::get_correct_optionid($slide['slideid']);
            $wrong = array_values(array_filter(
                array_column($slide['options'], 'optionid'),
                fn($id) => $id !== $correctid
            ));
            $done = false;
            foreach ([$wrong[0], $wrong[1], $correctid] as $optionid) {
                $response = external_api::clean_returnvalue(
                    submit_answer::execute_returns(),
                    submit_answer::execute($attemptid, $slide['slideid'], $optionid)
                );
                if ($response['slidefinished']) {
                    $finished[] = $slide['slideid'];
                    $done = true;
                } else {
                    $this->assertNull($response['correctoptionid']);
                }
                $this->assert_no_leak($response, $finished);

                // A fresh state read agrees.
                $read = external_api::clean_returnvalue(
                    get_attempt_state::execute_returns(),
                    get_attempt_state::execute($attemptid, [])
                );
                $this->assert_no_leak($read, $finished);
                if ($done) {
                    break;
                }
            }
            if (!empty($response['summary']) || $response['state']['roundcomplete']) {
                break;
            }
        }
        $this->assertCount(3, $finished);
    }

    public function test_practice_never_leaks(): void {
        $this->resetAfterTest();
        $this->play_through([]);
    }

    public function test_test_with_three_tries_never_leaks(): void {
        $this->resetAfterTest();
        $this->play_through(['mode' => instance_settings::MODE_TEST, 'triesperslide' => 3,
            'slidescore' => instance_settings::SLIDESCORE_PARTIAL]);
    }

    public function test_writes_need_attempt_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $student] = $this->setup_activity([]);
        $this->setUser($student);
        $start = external_api::clean_returnvalue(
            start_attempt::execute_returns(),
            start_attempt::execute((int) $instance->cmid, false, 0)
        );
        $attemptid = $start['state']['attemptid'];
        $slide = $start['state']['slides'][0];

        $context = \context_module::instance($instance->cmid);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/whakaharatau:attempt', CAP_PROHIBIT, $studentroleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/whakaharatau:attempt', $context, $student));

        try {
            submit_answer::execute($attemptid, $slide['slideid'], $slide['options'][0]['optionid']);
            $this->fail('Exception expected for submit_answer');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        try {
            finish_attempt::execute($attemptid, false);
            $this->fail('Exception expected for finish_attempt');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        // Reading the state stays possible (view only).
        $read = get_attempt_state::execute($attemptid, []);
        $this->assertSame($attemptid, $read['state']['attemptid']);
        $this->assertSame(0, $DB->count_records('whakaharatau_response', ['attemptid' => $attemptid]));
    }

    public function test_view_capability_needed(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $student] = $this->setup_activity([]);
        $context = \context_module::instance($instance->cmid);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/whakaharatau:view', CAP_PROHIBIT, $studentroleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($student);
        // Without mod/whakaharatau:view the activity is not available to the user at all.
        $this->expectException(\moodle_exception::class);
        start_attempt::execute((int) $instance->cmid, false, 0);
    }

    public function test_state_ignores_slides_of_other_activities(): void {
        $this->resetAfterTest();
        [$course, $instance, $student] = $this->setup_activity([]);
        $other = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $foreign = $modgen->create_slide(['whakaharatauid' => $other->id]);

        $this->setUser($student);
        $start = external_api::clean_returnvalue(
            start_attempt::execute_returns(),
            start_attempt::execute((int) $instance->cmid, false, 0)
        );
        $read = external_api::clean_returnvalue(
            get_attempt_state::execute_returns(),
            get_attempt_state::execute($start['state']['attemptid'], [(int) $foreign->id])
        );
        $this->assertSame([], $read['slides']);
    }
}
