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

use context_module;
use mod_whakaharatau\form\slide_form;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the versions of a slide's content.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(versions::class)]
#[CoversClass(slides::class)]
#[CoversClass(slide_form::class)]
#[CoversClass(attempt::class)]
final class versions_test extends \advanced_testcase {
    /**
     * An activity with two slides (option index 1 is correct) and a student.
     *
     * @return array [course, instance, context, slide records, student]
     */
    private function setup_activity(): array {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id, 'mode' => 1]);
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $slides = [];
        for ($i = 0; $i < 2; $i++) {
            $slides[] = $modgen->create_slide(['whakaharatauid' => $created->id, 'options' => 3, 'correct' => 1,
                'alttext' => 'Picture ' . $i, 'transcripts' => ['a', 'b', 'c']]);
        }
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $DB->get_record('whakaharatau', ['id' => $created->id]),
            context_module::instance($created->cmid), $slides, $student];
    }

    /**
     * Save a slide through its form, changing some fields.
     *
     * @param context_module $context context
     * @param \stdClass $instance instance
     * @param int $slideid slide id
     * @param array $changes form fields to change
     * @return void
     */
    private function save(context_module $context, \stdClass $instance, int $slideid, array $changes): void {
        $data = slide_form::prepare_data($context, 1, slides::get_slide($slideid));
        $data->optioncount = 3;
        foreach ($changes as $field => $value) {
            $data->$field = $value;
        }
        slide_form::save($context, $instance, $data);
    }

    /**
     * An edit starts a new version only when the content changed and the old content has answers.
     *
     * @return void
     */
    public function test_new_version_only_for_answered_content(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $context, $slides, $student] = $this->setup_activity();
        $id = (int) $slides[0]->id;
        $version = fn() => (int) slides::get_slide($id)->version;

        // Nobody has answered: version 1 is just updated.
        $this->save($context, $instance, $id, ['alttext' => 'First idea']);
        $this->assertSame(1, $version());
        $this->assertSame('First idea', versions::get_all((int) $instance->id)[$id][1]->alttext);

        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $attempt = $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['wrong']]);
        $this->assertEquals(1, $DB->get_field('whakaharatau_response', 'version', ['attemptid' => $attempt->get_id()]));

        // The same content, the fade and the order are not changes.
        $this->save($context, $instance, $id, ['fade' => 0, 'fadewidth' => 40]);
        slides::reorder_slides((int) $instance->id, [(int) $slides[1]->id, $id]);
        $this->save($context, $instance, $id, []);
        $this->assertSame(1, $version());

        // A transcript, then the correct clip.
        $this->save($context, $instance, $id, ['transcript_0' => 'changed']);
        $this->assertSame(2, $version());
        $rows = versions::get_all((int) $instance->id)[$id];
        $this->assertSame('a', $rows[1]->options[0]->transcript);
        $this->assertSame('changed', $rows[2]->options[0]->transcript);
        $this->assertTrue($rows[1]->options[1]->correct);
        // Version 2 has no answers yet, so it is updated.
        $this->save($context, $instance, $id, ['correct' => 2]);
        $this->assertSame(2, $version());
        $this->assertTrue(versions::get_all((int) $instance->id)[$id][2]->options[2]->correct);
    }

    /**
     * The old picture and clips are kept when they are replaced.
     *
     * @return void
     */
    public function test_old_files_are_kept(): void {
        $this->resetAfterTest();
        [, $instance, $context, $slides, $student] = $this->setup_activity();
        $id = (int) $slides[0]->id;
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id, 'answers' => ['right']]);
        $old = slides::get_file($context, slides::AREA_IMAGE, $id)->get_contenthash();
        $data = slide_form::prepare_data($context, 1, slides::get_slide($id));
        $data->optioncount = 3;
        $fs = get_file_storage();
        $usercontext = \context_user::instance(get_admin()->id);
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $data->image);
        $fs->create_file_from_string(['contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $data->image, 'filepath' => '/', 'filename' => 'new.png'], $modgen->get_png() . 'x');
        slide_form::save($context, $instance, $data);

        $rows = versions::get_all((int) $instance->id)[$id];
        $this->assertCount(2, $rows);
        $hash = fn(int $version) => $fs->get_file(
            $context->id,
            slides::COMPONENT,
            versions::AREA,
            $rows[$version]->id,
            '/',
            'image.png'
        )->get_contenthash();
        $this->assertSame($old, $hash(1));
        $this->assertSame(slides::get_file($context, slides::AREA_IMAGE, $id)->get_contenthash(), $hash(2));
        $this->assertNotSame($old, $hash(2));
        $this->assertNotNull(versions::get_file_url($context, (int) $rows[1]->id, 'audio-3'));
        $this->assertNull(versions::get_file_url($context, (int) $rows[1]->id, 'audio-4'));
    }

    /**
     * An answer belongs to the version the learner's page showed; an impossible one is the current version.
     *
     * @return void
     */
    public function test_answer_has_the_version_that_was_shown(): void {
        $this->resetAfterTest();
        $slide = (object) ['version' => 3];
        $this->assertSame(2, versions::answered_version($slide, 2));
        foreach ([0, -1, 4, 99] as $seen) {
            $this->assertSame(3, versions::answered_version($slide, $seen));
        }
    }

    /**
     * A deleted slide's version stays while it has answers, and goes when they do.
     *
     * @return void
     */
    public function test_deleted_slides(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance, $context, $slides, $student] = $this->setup_activity();
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id, 'answers' => ['right']]);
        slides::delete_slide($context, (int) $slides[0]->id);
        slides::delete_slides($context, (int) $instance->id, [(int) $slides[1]->id]);
        $rows = versions::get_all((int) $instance->id);
        $this->assertSame([(int) $slides[0]->id], array_keys($rows));
        $this->assertSame('Picture 0', $rows[(int) $slides[0]->id][1]->alttext);
        attempt::delete_all_attempts((int) $instance->id);
        $this->assertSame(0, $DB->count_records('whakaharatau_version'));
        $this->assertEmpty(get_file_storage()->get_area_files(
            $context->id,
            slides::COMPONENT,
            versions::AREA,
            false,
            'id',
            false
        ));
    }
}
