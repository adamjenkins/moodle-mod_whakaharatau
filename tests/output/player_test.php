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

namespace mod_whakaharatau\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the start screen data: who is told they cannot take part, and why.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(player::class)]
final class player_test extends \advanced_testcase {
    /**
     * A course with a playable Test (one slide, two attempts allowed).
     *
     * @return array [course, instance]
     */
    private function setup_test(): array {
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id, 'mode' => 1,
            'attempts' => 2]);
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->create_slide(['whakaharatauid' => $instance->id]);
        return [$course, $instance];
    }

    /**
     * The start screen data for the current user.
     *
     * @param \stdClass $course course
     * @param \stdClass $instance instance record
     * @return array template data
     */
    private function export(\stdClass $course, \stdClass $instance): array {
        global $DB, $PAGE;
        $record = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        return (new player($record, $cm, false))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A student can take the test: no notice, and the attempts left are shown.
     *
     * @return void
     */
    public function test_student_sees_attempts_left(): void {
        $this->resetAfterTest();
        [$course, $instance] = $this->setup_test();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $data = $this->export($course, $instance);

        $this->assertTrue($data['canattempt']);
        $this->assertFalse($data['cannotattempt']);
        $this->assertSame('', $data['cannotattempttext']);
        $this->assertStringContainsString(get_string('starttestattemptsleft', 'mod_whakaharatau', 2), $data['factstext']);
    }

    /**
     * An enrolled non-editing teacher (view, no attempt capability) is told it is their role, with no enrolment hint
     * and no attempt count.
     *
     * @return void
     */
    public function test_enrolled_role_without_attempt_capability(): void {
        $this->resetAfterTest();
        [$course, $instance] = $this->setup_test();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $data = $this->export($course, $instance);

        $this->assertFalse($data['canattempt']);
        $this->assertTrue($data['cannotattempt']);
        $this->assertSame(get_string('cannotattemptrole', 'mod_whakaharatau'), $data['cannotattempttext']);
        $this->assertStringContainsString(get_string('modetest', 'mod_whakaharatau'), $data['factstext']);
        $this->assertStringNotContainsString(get_string('starttestattemptsleft', 'mod_whakaharatau', 2), $data['factstext']);
    }

    /**
     * A guest is told to log in with an enrolled account.
     *
     * @return void
     */
    public function test_guest_gets_enrolment_hint(): void {
        $this->resetAfterTest();
        [$course, $instance] = $this->setup_test();
        $this->setGuestUser();

        $data = $this->export($course, $instance);

        $this->assertTrue($data['cannotattempt']);
        $this->assertSame(get_string('cannotattempt', 'mod_whakaharatau'), $data['cannotattempttext']);
    }
}
