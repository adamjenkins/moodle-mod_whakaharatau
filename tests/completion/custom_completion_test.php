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

namespace mod_whakaharatau\completion;

use mod_whakaharatau\local\attempt;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the custom completion rule "practised every slide at least once".
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(custom_completion::class)]
final class custom_completion_test extends \advanced_testcase {
    /**
     * Load the completion library.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course with completion, an activity with the rule on and two slides, and a student.
     *
     * @return array [course, instance, cm_info, student]
     */
    private function setup_activity(): array {
        $course = $this->getDataGenerator()->create_course(['summary' => '', 'enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionallslides' => 1]);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->create_slide(['whakaharatauid' => $instance->id]);
        $modgen->create_slide(['whakaharatauid' => $instance->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        return [$course, $instance, $cm, $student];
    }

    public function test_rule_definition(): void {
        $this->assertSame(['completionallslides'], custom_completion::get_defined_custom_rules());
        $this->resetAfterTest();
        [, , $cm, $student] = $this->setup_activity();
        $completion = new custom_completion($cm, (int) $student->id);
        $this->assertArrayHasKey('completionallslides', $completion->get_custom_rule_descriptions());
        $this->assertContains('completionallslides', $completion->get_sort_order());
        $this->assertTrue($completion->is_defined('completionallslides'));
    }

    public function test_get_state(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $student] = $this->setup_activity();
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');

        $completion = new custom_completion($cm, (int) $student->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionallslides'));

        $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id, 'answers' => ['right']]);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionallslides'));

        $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id, 'answers' => ['wrong']]);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionallslides'));

        $this->expectException(\moodle_exception::class);
        $completion->get_state('completionunknown');
    }

    public function test_answering_updates_completion(): void {
        $this->resetAfterTest();
        [$course, $instance, $cm, $student] = $this->setup_activity();
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $info = new \completion_info($course);
        $this->assertSame(COMPLETION_INCOMPLETE, (int) $info->get_data($cm, false, (int) $student->id)->completionstate);

        // The attempt re-checks completion after each answer: no cron or page view needed.
        $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'right']]);
        $this->assertSame(COMPLETION_COMPLETE, (int) $info->get_data($cm, false, (int) $student->id)->completionstate);
        $this->assertTrue(attempt::has_answered_all_slides((int) $instance->id, (int) $student->id));
    }
}
