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

namespace mod_whakaharatau\event;

use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\report;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the module's events.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attempt_started::class)]
#[CoversClass(attempt_submitted::class)]
#[CoversClass(attempt_deleted::class)]
#[CoversClass(course_module_viewed::class)]
#[CoversClass(course_module_instance_list_viewed::class)]
final class events_test extends \advanced_testcase {
    /**
     * A course, a Test activity with one slide, and a student.
     *
     * @return array [course, instance record, cm, student]
     */
    private function setup_activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST]);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->create_slide(['whakaharatauid' => $created->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $DB->get_record('whakaharatau', ['id' => $created->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('whakaharatau', $instance->id, $course->id, false, MUST_EXIST);
        return [$course, $instance, $cm, $student];
    }

    public function test_attempt_started_and_submitted(): void {
        $this->resetAfterTest();
        [, $instance, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $context = \context_module::instance($cm->id);

        $sink = $this->redirectEvents();
        $attempt = attempt::start($instance, $cm, (int) $student->id);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof attempt_started));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame($attempt->get_id(), (int) $event->objectid);
        $this->assertSame((int) $student->id, (int) $event->relateduserid);
        $this->assertSame($context->id, $event->get_context()->id);
        $this->assertSame((int) $instance->id, (int) $event->other['whakaharatauid']);
        $this->assertSame(instance_settings::MODE_TEST, (int) $event->other['mode']);
        $this->assertSame('c', $event->crud);
        $this->assertEquals(new \moodle_url('/mod/whakaharatau/view.php', ['id' => $cm->id]), $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty(attempt_started::get_name());
        $sink->clear();

        // Resuming triggers nothing.
        attempt::start($instance, $cm, (int) $student->id);
        $this->assertSame([], array_filter($sink->get_events(), fn($e) => $e instanceof attempt_started));

        // Answering the only slide finishes the attempt.
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->answer_current(attempt::get_by_id($attempt->get_id()), true);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof attempt_submitted));
        $this->assertCount(1, $events);
        $this->assertSame($attempt->get_id(), (int) $events[0]->objectid);
        $this->assertSame('u', $events[0]->crud);
        $this->assertSame((int) $student->id, (int) $events[0]->relateduserid);

        // Calling finish() again is idempotent and logs nothing more.
        $sink->clear();
        attempt::get_by_id($attempt->get_id())->finish();
        $this->assertSame([], array_filter($sink->get_events(), fn($e) => $e instanceof attempt_submitted));
        $sink->close();
    }

    public function test_attempt_deleted_from_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $instance, $cm, $student] = $this->setup_activity();
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $attempt = $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);

        $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
        $context = \context_module::instance($cm->id);
        $report = new report($instance, $cminfo, $context, instance_settings::MODE_TEST, false);
        $sink = $this->redirectEvents();
        $this->assertSame(1, $report->delete_attempts([$attempt->get_id(), 999999]));
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof attempt_deleted));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame($attempt->get_id(), (int) $events[0]->objectid);
        $this->assertSame('d', $events[0]->crud);
        $this->assertSame((int) $student->id, (int) $events[0]->relateduserid);
    }

    public function test_attempt_events_need_their_data(): void {
        $this->resetAfterTest();
        [, $instance, $cm] = $this->setup_activity();
        $context = \context_module::instance($cm->id);
        foreach ([attempt_started::class, attempt_submitted::class, attempt_deleted::class] as $class) {
            foreach (
                [['other' => ['whakaharatauid' => (int) $instance->id, 'mode' => 0]],
                    ['relateduserid' => 2, 'other' => ['mode' => 0]]] as $missing
            ) {
                try {
                    $class::create(['objectid' => 1, 'context' => $context] + $missing);
                    $this->fail("coding_exception expected for $class");
                } catch (\coding_exception $e) {
                    $this->assertStringContainsString('must be set', $e->getMessage());
                }
            }
        }
    }

    public function test_course_module_viewed(): void {
        $this->resetAfterTest();
        [$course, $instance, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $context = \context_module::instance($cm->id);
        $event = course_module_viewed::create(['objectid' => $instance->id, 'context' => $context]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('whakaharatau', $instance);
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(course_module_viewed::class, $events[0]);
        $this->assertSame('whakaharatau', $events[0]->objecttable);
        $this->assertEquals(new \moodle_url('/mod/whakaharatau/view.php', ['id' => $cm->id]), $events[0]->get_url());

        $list = course_module_instance_list_viewed::create(['context' => \context_course::instance($course->id)]);
        $sink = $this->redirectEvents();
        $list->trigger();
        $this->assertCount(1, $sink->get_events());
        $sink->close();
    }
}
