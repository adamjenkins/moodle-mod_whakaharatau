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
 * Tests of the instance settings rules (mode rules, defaults, choices).
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(instance_settings::class)]
final class instance_settings_test extends \advanced_testcase {
    /**
     * An instance record with the built-in defaults and the given changes.
     *
     * @param array $changes fields to set
     * @return \stdClass
     */
    private function instance(array $changes = []): \stdClass {
        $defaults = instance_settings::get_builtin_defaults();
        $defaults['triesperslide'] = $defaults['practicetries'];
        unset($defaults['practicetries'], $defaults['testtries']);
        return (object) ($changes + $defaults);
    }

    public function test_builtin_defaults(): void {
        $defaults = instance_settings::get_builtin_defaults();
        $this->assertSame(instance_settings::MODE_PRACTICE, $defaults['mode']);
        $this->assertSame(0, $defaults['shuffle']);
        $this->assertSame(instance_settings::REPEAT_OFF, $defaults['repeatmode']);
        $this->assertSame(instance_settings::OPTIONORDER_RANDOM, $defaults['optionorder']);
        $this->assertSame(instance_settings::TRIES_UNLIMITED, $defaults['practicetries']);
        $this->assertSame(1, $defaults['testtries']);
        $this->assertSame(instance_settings::GRADE_HIGHEST, $defaults['grademethod']);
    }

    public function test_site_defaults_override_builtin(): void {
        $this->resetAfterTest();
        set_config('shuffle', 1, 'whakaharatau');
        set_config('testtries', 3, 'whakaharatau');
        set_config('repeatmode', '', 'whakaharatau');
        $defaults = instance_settings::get_defaults();
        $this->assertSame(1, $defaults['shuffle']);
        $this->assertSame(3, $defaults['testtries']);
        $this->assertSame(instance_settings::REPEAT_OFF, $defaults['repeatmode']);
    }

    public function test_mode_rules(): void {
        // Practice: always feedback after each answer, Repeat as set, tries as set.
        $practice = $this->instance(['repeatmode' => instance_settings::REPEAT_ALL,
            'feedbackmode' => instance_settings::FEEDBACK_END, 'triesperslide' => 0]);
        $this->assertFalse(instance_settings::is_test($practice));
        $this->assertSame(instance_settings::REPEAT_ALL, instance_settings::effective_repeat($practice));
        $this->assertSame(instance_settings::FEEDBACK_EACH, instance_settings::effective_feedback($practice));
        $this->assertSame(0, instance_settings::effective_tries($practice));

        // Test: Repeat always off; feedback as set; feedback at the end means one try.
        $test = $this->instance(['mode' => instance_settings::MODE_TEST, 'repeatmode' => instance_settings::REPEAT_ALL,
            'feedbackmode' => instance_settings::FEEDBACK_EACH, 'triesperslide' => 3]);
        $this->assertTrue(instance_settings::is_test($test));
        $this->assertSame(instance_settings::REPEAT_OFF, instance_settings::effective_repeat($test));
        $this->assertSame(3, instance_settings::effective_tries($test));
        $test->feedbackmode = instance_settings::FEEDBACK_END;
        $this->assertSame(instance_settings::FEEDBACK_END, instance_settings::effective_feedback($test));
        $this->assertSame(1, instance_settings::effective_tries($test));

        // Out-of-range tries are clamped.
        $test->feedbackmode = instance_settings::FEEDBACK_EACH;
        $test->triesperslide = 99;
        $this->assertSame(instance_settings::TRIES_MAX, instance_settings::effective_tries($test));
    }

    public function test_playmode_description(): void {
        $sep = ' · ';
        $this->assertSame(get_string('playmodeinorder', 'mod_whakaharatau') . $sep .
            get_string('playmodeonepass', 'mod_whakaharatau'), instance_settings::get_playmode_description($this->instance()));
        $this->assertSame(
            get_string('playmodeshuffled', 'mod_whakaharatau') . $sep .
            get_string('playmoderepeatall', 'mod_whakaharatau'),
            instance_settings::get_playmode_description($this->instance(['shuffle' => 1,
            'repeatmode' => instance_settings::REPEAT_ALL]))
        );
        $this->assertSame(
            get_string('playmodeinorder', 'mod_whakaharatau') . $sep .
            get_string('playmoderepeatmissed', 'mod_whakaharatau'),
            instance_settings::get_playmode_description($this->instance(['repeatmode' => instance_settings::REPEAT_MISSED]))
        );
        // Test mode ignores a stored Repeat.
        $this->assertStringContainsString(
            get_string('playmodeonepass', 'mod_whakaharatau'),
            instance_settings::get_playmode_description($this->instance(['mode' => instance_settings::MODE_TEST,
            'repeatmode' => instance_settings::REPEAT_ALL]))
        );
    }

    public function test_choices_are_plain_text(): void {
        $lists = [
            instance_settings::get_mode_choices(),
            instance_settings::get_repeat_choices(),
            instance_settings::get_optionorder_choices(),
            instance_settings::get_display_choices(),
            instance_settings::get_feedback_choices(),
            instance_settings::get_tries_choices(),
            instance_settings::get_slidescore_choices(),
            instance_settings::get_attempts_choices(),
            instance_settings::get_grademethod_choices(),
            instance_settings::get_scoring_choices(),
            instance_settings::get_yesno_choices(),
        ];
        foreach ($lists as $choices) {
            $this->assertNotEmpty($choices);
            foreach ($choices as $label) {
                $this->assertSame(strip_tags((string) $label), (string) $label);
                $this->assertStringNotContainsString('[[', (string) $label);
            }
        }
        $this->assertCount(instance_settings::TRIES_MAX + 1, instance_settings::get_tries_choices());
        $this->assertArrayHasKey(instance_settings::GRADE_LAST, instance_settings::get_grademethod_choices());
    }
}
