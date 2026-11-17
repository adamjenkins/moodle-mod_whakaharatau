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

namespace mod_whakaharatau;

use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests of lib.php: add, update, delete, grade item, course module info, inline display and course reset.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('whakaharatau_supports')]
#[CoversFunction('whakaharatau_normalise_instance_data')]
#[CoversFunction('whakaharatau_add_instance')]
#[CoversFunction('whakaharatau_update_instance')]
#[CoversFunction('whakaharatau_delete_instance')]
#[CoversFunction('whakaharatau_grade_item_update')]
#[CoversFunction('whakaharatau_update_grades')]
#[CoversFunction('whakaharatau_get_coursemodule_info')]
#[CoversFunction('whakaharatau_cm_info_view')]
#[CoversFunction('whakaharatau_reset_userdata')]
#[CoversFunction('whakaharatau_get_completion_active_rule_descriptions')]
#[CoversFunction('whakaharatau_extend_settings_navigation')]
final class lib_test extends \advanced_testcase {
    /**
     * Load lib.php and the grade library.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
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
     * Grade items of an instance.
     *
     * @param \stdClass $instance whakaharatau record
     * @return \grade_item[]
     */
    private function grade_items(\stdClass $instance): array {
        return \grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => 'whakaharatau',
            'iteminstance' => $instance->id, 'courseid' => $instance->course]) ?: [];
    }

    public function test_supports(): void {
        $this->assertTrue(whakaharatau_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(whakaharatau_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(whakaharatau_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(whakaharatau_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(whakaharatau_supports(FEATURE_GROUPS));
        $this->assertTrue(whakaharatau_supports(FEATURE_GROUPINGS));
        $this->assertSame(MOD_PURPOSE_INTERACTIVECONTENT, whakaharatau_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(whakaharatau_supports('nonexistent_feature'));
    }

    public function test_normalise_instance_data(): void {
        $this->resetAfterTest();
        // Test mode: the test tries apply, Repeat is forced off.
        $data = (object) ['mode' => instance_settings::MODE_TEST, 'practicetries' => 0, 'testtries' => 3,
            'repeatmode' => instance_settings::REPEAT_ALL, 'grade' => 50];
        whakaharatau_normalise_instance_data($data);
        $this->assertSame(3, $data->triesperslide);
        $this->assertSame(instance_settings::REPEAT_OFF, $data->repeatmode);
        $this->assertSame(50, $data->grade);
        $this->assertSame(0, $data->completionallslides);

        // Practice mode: the practice tries apply; hidden elements keep the stored values; grade is kept.
        $current = (object) ['mode' => 1, 'triesperslide' => 1, 'shuffle' => 1, 'repeatmode' => 0, 'optionorder' => 0,
            'showprogress' => 0, 'displayinline' => 1, 'feedbackmode' => 1, 'slidescore' => 1, 'attempts' => 3,
            'grademethod' => 2, 'allowreview' => 0, 'scoring' => 1, 'autoplay' => 1, 'transcriptsbefore' => 1,
            'grade' => 80];
        $data = (object) ['mode' => instance_settings::MODE_PRACTICE, 'practicetries' => 0, 'testtries' => 1,
            'repeatmode' => instance_settings::REPEAT_MISSED, 'grade' => 0, 'completionallslides' => 1];
        whakaharatau_normalise_instance_data($data, $current);
        $this->assertSame(0, $data->triesperslide);
        $this->assertSame(instance_settings::REPEAT_MISSED, $data->repeatmode);
        $this->assertSame(1, $data->shuffle);
        $this->assertSame(3, $data->attempts);
        $this->assertSame(80, $data->grade);
        $this->assertSame(1, $data->completionallslides);
    }

    public function test_add_instance_practice_has_no_grade_item(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);

        $record = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(instance_settings::MODE_PRACTICE, (int) $record->mode);
        $this->assertSame(instance_settings::OPTIONORDER_RANDOM, (int) $record->optionorder);
        $this->assertGreaterThan(0, (int) $record->timecreated);
        $this->assertSame([], $this->grade_items($record));
    }

    public function test_add_instance_test_creates_grade_item(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST, 'grade' => 20, 'repeatmode' => instance_settings::REPEAT_ALL]);

        $record = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
        // Test mode never repeats.
        $this->assertSame(instance_settings::REPEAT_OFF, (int) $record->repeatmode);
        $items = $this->grade_items($record);
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertEquals(20, $item->grademax);
        $this->assertSame(GRADE_TYPE_VALUE, (int) $item->gradetype);
    }

    public function test_update_instance_switches_grade_item(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'wrong']]);
        $record = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertCount(1, $this->grade_items($record));

        // To Practice: the grade item goes.
        $data = clone $record;
        $data->instance = $record->id;
        $data->coursemodule = $instance->cmid;
        $data->mode = instance_settings::MODE_PRACTICE;
        $data->practicetries = 0;
        $this->assertTrue(whakaharatau_update_instance($data));
        $this->assertSame([], $this->grade_items($record));
        $this->assertSame(100, (int) $DB->get_field('whakaharatau', 'grade', ['id' => $record->id]));

        // Back to Test: the grade item and the earlier Test grade come back.
        $data = clone $record;
        $data->instance = $record->id;
        $data->coursemodule = $instance->cmid;
        $data->mode = instance_settings::MODE_TEST;
        $data->testtries = 2;
        whakaharatau_update_instance($data);
        $this->assertSame(2, (int) $DB->get_field('whakaharatau', 'triesperslide', ['id' => $record->id]));
        $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $record->id, $student->id);
        $this->assertCount(1, $grades->items);
        $this->assertEquals(50.0, (float) $grades->items[0]->grades[$student->id]->grade);
    }

    public function test_delete_instance_removes_everything(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST]);
        $context = \context_module::instance($instance->cmid);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'promptaudio' => true]);
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);
        $this->assertTrue($DB->record_exists('whakaharatau_response', []));

        $this->assertTrue(whakaharatau_delete_instance($instance->id));
        $this->assertFalse($DB->record_exists('whakaharatau', ['id' => $instance->id]));
        $this->assertFalse($DB->record_exists('whakaharatau_slide', ['whakaharatauid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('whakaharatau_option'));
        $this->assertSame(0, $DB->count_records('whakaharatau_attempt'));
        $this->assertSame(0, $DB->count_records('whakaharatau_response'));
        $files = get_file_storage()->get_area_files($context->id, slides::COMPONENT, false, false, 'id', false);
        $this->assertSame([], $files);
        $this->assertSame([], $this->grade_items((object) ['id' => $instance->id, 'course' => $course->id]));
        $this->assertFalse(whakaharatau_delete_instance($instance->id));
    }

    public function test_coursemodule_info(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '', 'enablecompletion' => 1]);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'displayinline' => 1, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionallslides' => 1]);
        $cm = $DB->get_record('course_modules', ['id' => $instance->cmid], '*', MUST_EXIST);

        $info = whakaharatau_get_coursemodule_info($cm);
        $this->assertSame(1, $info->customdata['displayinline']);
        $this->assertSame(1, $info->customdata['customcompletionrules']['completionallslides']);

        $cminfo = get_fast_modinfo($course)->get_cm($instance->cmid);
        $descriptions = whakaharatau_get_completion_active_rule_descriptions($cminfo);
        $this->assertSame([get_string('completionallslides_desc', 'mod_whakaharatau')], $descriptions);
    }

    public function test_cm_info_view_inline(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $inline = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id, 'displayinline' => 1]);
        $link = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id, 'displayinline' => 0]);
        $this->modgen()->create_slide(['whakaharatauid' => $inline->id]);

        $this->setUser($student);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_course($course);
        $modinfo = get_fast_modinfo($course);

        $cm = $modinfo->get_cm($inline->cmid);
        $content = $cm->get_formatted_content();
        $this->assertStringContainsString('data-region="mod-whakaharatau-player"', $content);
        $this->assertStringContainsString('data-cmid="' . $inline->cmid . '"', $content);
        $this->assertStringNotContainsString('[[', $content);
        $this->assertStringContainsString('whakaharatau-inline', $cm->extraclasses);

        $this->assertStringNotContainsString('mod-whakaharatau-player', (string) $modinfo->get_cm($link->cmid)->content);
    }

    public function test_reset_userdata(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);
        $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $instance->id, $student->id);
        $this->assertEquals(100.0, (float) $grades->items[0]->grades[$student->id]->grade);

        $status = whakaharatau_reset_userdata((object) ['courseid' => $course->id, 'reset_whakaharatau_attempts' => 1]);
        $this->assertCount(1, $status);
        $this->assertFalse($status[0]['error']);
        $this->assertSame(0, $DB->count_records('whakaharatau_attempt', ['whakaharatauid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('whakaharatau_response'));
        $grades = grade_get_grades($course->id, 'mod', 'whakaharatau', $instance->id, $student->id);
        $this->assertNull($grades->items[0]->grades[$student->id]->grade);
        // The slides are kept.
        $this->assertSame(1, slides::count_slides((int) $instance->id));
        // Nothing asked: nothing done.
        $this->assertSame([], whakaharatau_reset_userdata((object) ['courseid' => $course->id]));
    }

    public function test_reset_through_core(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);

        $data = (object) ['id' => $course->id, 'courseid' => $course->id, 'reset_whakaharatau_attempts' => 1];
        reset_course_userdata($data);
        $this->assertSame(0, $DB->count_records('whakaharatau_attempt', ['whakaharatauid' => $instance->id]));
        $this->assertSame(['reset_whakaharatau_attempts' => 1], whakaharatau_reset_course_form_defaults($course));
    }

    public function test_get_user_grades_and_attempt_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id,
            'mode' => instance_settings::MODE_TEST, 'grade' => 10]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right', 'wrong']]);
        $this->assertCount(1, attempt::get_attempts((int) $instance->id, (int) $student->id));

        $record = $DB->get_record('whakaharatau', ['id' => $instance->id], '*', MUST_EXIST);
        $grades = whakaharatau_get_user_grades($record, (int) $student->id);
        $this->assertEquals(5.0, $grades[$student->id]->rawgrade);
        $this->assertSame([], whakaharatau_get_user_grades($record, (int) $student->id + 1000));
    }

    public function test_settings_navigation(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        [, $cm] = get_course_and_cm_from_cmid($instance->cmid, 'whakaharatau');
        $PAGE->set_url('/mod/whakaharatau/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm, $course);
        $node = \navigation_node::create('test');
        whakaharatau_extend_settings_navigation($PAGE->settingsnav, $node);
        foreach (
            ['mod_whakaharatau_edit', 'mod_whakaharatau_import', 'mod_whakaharatau_export',
                'mod_whakaharatau_report'] as $key
        ) {
            $this->assertNotFalse($node->get($key), $key);
        }
    }
}
