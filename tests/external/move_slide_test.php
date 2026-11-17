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

use mod_whakaharatau\local\slides;

/**
 * Tests for mod_whakaharatau_move_slide.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_whakaharatau\external\move_slide::class)]
final class move_slide_test extends \advanced_testcase {
    /**
     * A teacher moves a slide; a student may not; a slide of another activity is refused.
     *
     * @return void
     */
    public function test_execute(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $a = $generator->create_slide(['whakaharatauid' => $instance->id]);
        $b = $generator->create_slide(['whakaharatauid' => $instance->id]);
        $c = $generator->create_slide(['whakaharatauid' => $instance->id]);
        $foreign = $generator->create_slide(['whakaharatauid' => $other->id]);

        $this->setUser($teacher);
        $result = move_slide::execute((int) $instance->cmid, (int) $c->id, 0);
        $result = \core_external\external_api::clean_returnvalue(move_slide::execute_returns(), $result);
        $this->assertTrue($result['success']);
        $this->assertSame(
            [(int) $c->id, (int) $a->id, (int) $b->id],
            array_map('intval', array_keys(slides::get_slides((int) $instance->id)))
        );

        move_slide::execute((int) $instance->cmid, (int) $c->id, (int) $b->id);
        $this->assertSame(
            [(int) $a->id, (int) $b->id, (int) $c->id],
            array_map('intval', array_keys(slides::get_slides((int) $instance->id)))
        );

        try {
            move_slide::execute((int) $instance->cmid, (int) $foreign->id, 0);
            $this->fail('A slide of another activity was moved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorslidenotinactivity', $e->errorcode);
        }

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        move_slide::execute((int) $instance->cmid, (int) $a->id, 0);
    }
}
