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

namespace mod_whakaharatau\form;

use context_module;
use context_user;
use mod_whakaharatau\local\slides;

/**
 * Tests for saving the slide form, and rendering the authoring templates.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_whakaharatau\form\slide_form
 * @covers     \mod_whakaharatau\output\edit_slides
 * @covers     \mod_whakaharatau\output\import_review
 */
final class slide_form_test extends \advanced_testcase {
    /** @var string Bytes that pass the MP3 content check. */
    const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00 test audio";

    /**
     * Create a slide through save(), then edit it: one option emptied, the correct one changed, and an option id
     * of another slide posted back (which must be ignored).
     *
     * @return void
     */
    public function test_save_create_and_edit(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $context = context_module::instance($instance->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $foreign = $generator->create_slide(['whakaharatauid' => $instance->id]);

        $data = (object) [
            'slideid' => 0,
            'alttext' => ' A dog ',
            'decorative' => 0,
            'prompt' => 'He aha tēnei?',
            'promptlang' => 'mi',
            'image' => $this->draft('pic.png', \mod_whakaharatau_generator::PNG),
            'promptaudio' => 0,
            'optioncount' => 3,
            'audio_0' => $this->draft('a.mp3', self::MP3),
            'transcript_0' => 'kurī',
            'audio_1' => $this->draft('b.mp3', self::MP3),
            'transcript_1' => 'ngeru',
            'audio_2' => $this->draft('c.mp3', self::MP3),
            'transcript_2' => 'manu',
            'correct' => 1,
        ];
        $slideid = slide_form::save($context, $instance, $data);
        $slide = slides::get_slide($slideid);
        $this->assertSame('A dog', $slide->alttext);
        $this->assertSame('mi', $slide->promptlang);
        $options = array_values(slides::get_options($slideid));
        $this->assertSame(['kurī', 'ngeru', 'manu'], array_column($options, 'transcript'));
        $this->assertSame((int) $options[1]->id, slides::get_correct_optionid($slideid));
        $this->assertNotEmpty(slides::get_file($context, slides::AREA_IMAGE, $slideid));

        // Edit: the form is prepared from the slide; then option 1 is emptied and option 3 made correct.
        $edit = slide_form::prepare_data($context, (int) $instance->cmid, $slide);
        $this->assertSame(1, $edit->correct);
        $edit->optioncount = 4;
        $edit->audio_1 = file_get_unused_draft_itemid();
        $edit->correct = 2;
        $edit->optionid_3 = (int) $foreign->options[0]->id;
        $edit->audio_3 = $this->draft('d.mp3', self::MP3);
        $edit->transcript_3 = 'ika';
        slide_form::save($context, $instance, $edit);

        $options = array_values(slides::get_options($slideid));
        $this->assertSame(['kurī', 'manu', 'ika'], array_column($options, 'transcript'));
        $this->assertSame((int) $options[1]->id, slides::get_correct_optionid($slideid));
        // The other slide's option is untouched.
        $this->assertSame((int) $foreign->id, (int) slides::get_option((int) $foreign->options[0]->id)->slideid);
        $this->assertCount(4, slides::get_options((int) $foreign->id));
        $this->assertTrue(slides::is_playable($instance, $context));
    }

    /**
     * The Edit slides list and the import review render with real data, every string resolved.
     *
     * @return void
     */
    public function test_templates_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $context = context_module::instance($instance->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $generator->create_slide(['whakaharatauid' => $instance->id, 'alttext' => '<b>Kai</b> & co']);
        $generator->create_slide(['whakaharatauid' => $instance->id, 'correct' => -1]);

        $output = $PAGE->get_renderer('core');
        $list = new \mod_whakaharatau\output\edit_slides($instance, (int) $instance->cmid, $context);
        $html = $output->render_from_template('mod_whakaharatau/edit_slides', $list->export_for_template($output));
        $this->assertStringContainsString('&lt;b&gt;Kai&lt;/b&gt; &amp; co', $html);
        $this->assertStringContainsString(get_string('problem_nocorrect', 'mod_whakaharatau'), $html);
        $this->assertStringNotContainsString('[[', $html);

        $plan = \mod_whakaharatau\local\importer::group_by_filenames([
            '01.jpg' => 'image', '01-a.mp3' => 'audio', '01-b_x.mp3' => 'audio', 'x.mp3' => 'audio',
        ]);
        $review = new \mod_whakaharatau\output\import_review(
            (int) $instance->cmid,
            0,
            'replace',
            $plan,
            [['name' => 'r.txt', 'reason' => 'importnotmedia']],
            ['importerror_toolarge'],
            2
        );
        $html = $output->render_from_template('mod_whakaharatau/import_review', $review->export_for_template($output));
        $this->assertStringContainsString('01-b_x.mp3', $html);
        $this->assertStringContainsString('x.mp3', $html);
        $this->assertStringContainsString('id="mod_whakaharatau-correct-0-1"', $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Put one file into a new draft area of the current user.
     *
     * @param string $name file name
     * @param string $content file content
     * @return int draft item id
     */
    private function draft(string $name, string $content): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $name,
        ], $content);
        return $draftid;
    }
}
