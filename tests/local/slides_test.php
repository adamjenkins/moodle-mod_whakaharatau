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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests of the slides manager: order, options, validation and media checks.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(slides::class)]
final class slides_test extends \advanced_testcase {
    /**
     * A course with an activity.
     *
     * @param array $settings instance settings
     * @return array [instance, context]
     */
    private function setup_activity(array $settings = []): array {
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id] + $settings);
        return [$instance, context_module::instance($instance->cmid)];
    }

    /**
     * The generator for this module.
     *
     * @return \mod_whakaharatau_generator
     */
    private function modgen(): \mod_whakaharatau_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
    }

    public function test_create_and_order(): void {
        $this->resetAfterTest();
        [$instance] = $this->setup_activity();
        $a = slides::create_slide((int) $instance->id, (object) ['alttext' => 'A']);
        $b = slides::create_slide((int) $instance->id, (object) ['alttext' => 'B']);
        $c = slides::create_slide((int) $instance->id, (object) ['alttext' => 'C']);
        $this->assertSame([$a, $b, $c], array_map('intval', array_keys(slides::get_slides((int) $instance->id))));
        $this->assertSame(3, slides::count_slides((int) $instance->id));
        $this->assertSame(2, (int) slides::get_slide($c)->sortorder);

        slides::reorder_slides((int) $instance->id, [$c, $a, $b]);
        $this->assertSame([$c, $a, $b], array_map('intval', array_keys(slides::get_slides((int) $instance->id))));

        slides::move_slide_after($c, $b);
        $this->assertSame([$a, $b, $c], array_map('intval', array_keys(slides::get_slides((int) $instance->id))));
        slides::move_slide_after($b, 0);
        $this->assertSame([$b, $a, $c], array_map('intval', array_keys(slides::get_slides((int) $instance->id))));

        slides::update_slide((object) ['id' => $a, 'alttext' => 'Changed']);
        $this->assertSame('Changed', slides::get_slide($a)->alttext);
        $this->assertFalse(slides::get_slide(-1, IGNORE_MISSING));
    }

    /**
     * Reorder lists that leave out, repeat or add a slide are refused.
     *
     * @return array
     */
    public static function bad_order_provider(): array {
        return [
            'missing one' => [[0, 1]],
            'duplicate' => [[0, 1, 1]],
            'foreign' => [[0, 1, 2, 99]],
        ];
    }

    /**
     * Reorder must list every slide exactly once.
     *
     * @param int[] $indexes slide indexes (99 = a slide of another activity)
     * @return void
     */
    #[DataProvider('bad_order_provider')]
    public function test_reorder_refuses_bad_lists(array $indexes): void {
        $this->resetAfterTest();
        [$instance] = $this->setup_activity();
        [$other] = $this->setup_activity();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = slides::create_slide((int) $instance->id, (object) ['alttext' => "S$i"]);
        }
        $foreign = slides::create_slide((int) $other->id, (object) ['alttext' => 'X']);
        $list = array_map(fn($i) => $i === 99 ? $foreign : $ids[$i], $indexes);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorslideorder', 'mod_whakaharatau'));
        slides::reorder_slides((int) $instance->id, $list);
    }

    public function test_delete_slide_resequences_and_removes_files(): void {
        global $DB;
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $first = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'promptaudio' => true]);
        $second = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->assertNotNull(slides::get_file($context, slides::AREA_PROMPT, (int) $first->id));

        slides::delete_slide($context, (int) $first->id);
        $this->assertFalse(slides::get_slide((int) $first->id, IGNORE_MISSING));
        $this->assertSame(0, $DB->count_records('whakaharatau_option', ['slideid' => $first->id]));
        $this->assertNull(slides::get_file($context, slides::AREA_IMAGE, (int) $first->id));
        $this->assertNull(slides::get_file($context, slides::AREA_PROMPT, (int) $first->id));
        $this->assertNull(slides::get_file($context, slides::AREA_AUDIO, (int) $first->options[0]->id));
        $this->assertSame(0, (int) slides::get_slide((int) $second->id)->sortorder);

        slides::delete_all_slides($context, (int) $instance->id);
        $this->assertSame(0, slides::count_slides((int) $instance->id));
        $this->assertSame([], get_file_storage()->get_area_files(
            $context->id,
            slides::COMPONENT,
            slides::AREA_AUDIO,
            false,
            'id',
            false
        ));
    }

    public function test_correct_option(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $slide = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'options' => 3, 'correct' => 0]);
        $ids = array_map(fn($o) => (int) $o->id, $slide->options);
        $this->assertSame($ids[0], slides::get_correct_optionid((int) $slide->id));

        slides::set_correct_option((int) $slide->id, $ids[2]);
        $this->assertSame($ids[2], slides::get_correct_optionid((int) $slide->id));
        $correct = array_filter(slides::get_options((int) $slide->id), fn($o) => !empty($o->iscorrect));
        $this->assertSame([$ids[2]], array_map('intval', array_keys($correct)));

        // Two marked correct (create_option does not unmark the others): no single correct answer.
        slides::create_option((int) $slide->id, (object) ['transcript' => 'Extra', 'iscorrect' => 1]);
        $this->assertNull(slides::get_correct_optionid((int) $slide->id));

        // An option of another slide is refused.
        $other = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        try {
            slides::set_correct_option((int) $slide->id, (int) $other->options[0]->id);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwrongslide', $e->errorcode);
        }

        slides::delete_option($context, $ids[0]);
        $this->assertFalse(slides::get_option($ids[0], IGNORE_MISSING));
        $this->assertNull(slides::get_file($context, slides::AREA_AUDIO, $ids[0]));
    }

    public function test_validate_slide_and_playable(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $this->assertFalse(slides::is_playable($instance, $context), 'no slides');

        $good = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $this->assertSame([], slides::validate_slide($context, $good, $good->options));
        $this->assertTrue(slides::is_playable($instance, $context));

        $bad = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'noimage' => true, 'alttext' => ' ',
            'options' => 1, 'correct' => -1]);
        $problems = slides::validate_slide($context, $bad, $bad->options);
        $this->assertContains(slides::PROBLEM_NOIMAGE, $problems);
        $this->assertContains(slides::PROBLEM_NOALT, $problems);
        $this->assertContains(slides::PROBLEM_TOOFEW, $problems);
        $this->assertContains(slides::PROBLEM_NOCORRECT, $problems);
        $this->assertNotContains(slides::PROBLEM_NOAUDIO, $problems);
        $this->assertSame([(int) $bad->id], array_map('intval', array_keys(slides::get_problems(
            $context,
            (int) $instance->id
        ))));
        $this->assertFalse(slides::is_playable($instance, $context));

        // Decorative needs no alt text; a missing clip and too many options are problems too.
        $deco = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'decorative' => 1, 'alttext' => '',
            'options' => slides::MAX_OPTIONS + 1]);
        get_file_storage()->delete_area_files(
            $context->id,
            slides::COMPONENT,
            slides::AREA_AUDIO,
            (int) $deco->options[0]->id
        );
        $problems = slides::validate_slide($context, $deco, $deco->options);
        $this->assertNotContains(slides::PROBLEM_NOALT, $problems);
        $this->assertContains(slides::PROBLEM_TOOMANY, $problems);
        $this->assertContains(slides::PROBLEM_NOAUDIO, $problems);

        $many = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        slides::create_option((int) $many->id, (object) ['iscorrect' => 1]);
        $options = slides::get_options((int) $many->id);
        $this->assertContains(slides::PROBLEM_MANYCORRECT, slides::validate_slide($context, $many, $options));

        // Every problem code is a lang string.
        foreach (
            [slides::PROBLEM_NOIMAGE, slides::PROBLEM_NOALT, slides::PROBLEM_TOOFEW, slides::PROBLEM_TOOMANY,
                slides::PROBLEM_NOCORRECT, slides::PROBLEM_MANYCORRECT, slides::PROBLEM_NOAUDIO] as $code
        ) {
            $this->assertTrue(get_string_manager()->string_exists($code, 'mod_whakaharatau'), $code);
        }
    }

    /**
     * Magic bytes and the type each one is recognised as.
     *
     * @return array
     */
    public static function sniff_provider(): array {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'jpg'],
            'png' => ["\x89PNG\r\n\x1A\n\x00\x00", 'png'],
            'gif' => ['GIF89a....', 'gif'],
            'webp' => ['RIFF0000WEBPVP8 ', 'webp'],
            'wav' => ['RIFF0000WAVEfmt ', 'wav'],
            'ogg' => ['OggS' . "\x00\x02", 'ogg'],
            'mp3 id3' => ['ID3' . "\x03\x00", 'mp3'],
            'mp3 frame' => ["\xFF\xFB\x90\xC4", 'mp3'],
            'm4a' => ["\x00\x00\x00\x20ftypM4A ", 'm4a'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg">', null],
            'html' => ['<html><script>', null],
            'empty' => ['', null],
        ];
    }

    /**
     * Content types are recognised by magic bytes, never by name.
     *
     * @param string $head first bytes
     * @param string|null $expected canonical extension
     * @return void
     */
    #[DataProvider('sniff_provider')]
    public function test_sniff_media_type(string $head, ?string $expected): void {
        $this->assertSame($expected, slides::sniff_media_type($head));
    }

    public function test_type_allowed_and_area_kind(): void {
        $this->assertTrue(slides::type_allowed(slides::AREA_IMAGE, 'png'));
        $this->assertFalse(slides::type_allowed(slides::AREA_IMAGE, 'mp3'));
        $this->assertFalse(slides::type_allowed(slides::AREA_IMAGE, 'svg'));
        $this->assertFalse(slides::type_allowed(slides::AREA_IMAGE, null));
        $this->assertTrue(slides::type_allowed(slides::AREA_AUDIO, 'mp3'));
        $this->assertTrue(slides::type_allowed(slides::AREA_PROMPT, 'ogg'));
        $this->assertFalse(slides::type_allowed(slides::AREA_AUDIO, 'png'));
        $this->assertTrue(slides::type_allowed(slides::AREA_COVER, 'jpg'));
        $this->assertSame('image', slides::area_kind(slides::AREA_COVER));
        $this->assertSame('audio', slides::area_kind(slides::AREA_PROMPT));
        $this->expectException(\coding_exception::class);
        slides::area_kind('intro');
    }

    public function test_save_file_checks_content_and_uses_neutral_names(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $slide = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);
        $optionid = (int) $slide->options[0]->id;

        $file = slides::save_file_from_string($context, slides::AREA_AUDIO, $optionid, $this->modgen()->get_mp3());
        $this->assertSame('audio.mp3', $file->get_filename());
        $this->assertCount(1, get_file_storage()->get_area_files(
            $context->id,
            slides::COMPONENT,
            slides::AREA_AUDIO,
            $optionid,
            'id',
            false
        ), 'the old file is replaced');
        $this->assertSame('image.png', slides::get_file($context, slides::AREA_IMAGE, (int) $slide->id)->get_filename());
        $url = slides::get_file_url($context, slides::AREA_AUDIO, $optionid);
        $this->assertStringContainsString('/mod_whakaharatau/optionaudio/' . $optionid . '/', $url->out(false));
        $this->assertStringNotContainsString('correct', $url->out(false));
        $this->assertNull(slides::get_file_url($context, slides::AREA_PROMPT, (int) $slide->id));

        // Audio in the image area, and SVG anywhere, are refused.
        foreach (
            [[slides::AREA_IMAGE, $this->modgen()->get_mp3()], [slides::AREA_IMAGE, '<svg></svg>'],
                [slides::AREA_AUDIO, \mod_whakaharatau_generator::PNG]] as [$area, $content]
        ) {
            try {
                slides::save_file_from_string($context, $area, (int) $slide->id, $content);
                $this->fail('Exception expected for ' . $area);
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidmediafile', $e->errorcode);
            }
        }
        // The refused upload did not remove the existing image.
        $this->assertNotNull(slides::get_file($context, slides::AREA_IMAGE, (int) $slide->id));
    }

    public function test_save_file_from_draft(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$instance, $context] = $this->setup_activity();
        $slide = $this->modgen()->create_slide(['whakaharatauid' => $instance->id]);

        $draftid = slides::prepare_draft($context, slides::AREA_IMAGE, (int) $slide->id);
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $drafts = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false);
        $this->assertCount(1, $drafts, 'the existing image is in the draft area');

        // Replace it with a GIF named to look like something else.
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftid);
        $fs->create_file_from_string(['contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $draftid, 'filepath' => '/', 'filename' => '01-correct.png'], "GIF89a\x01\x00\x01\x00");
        $file = slides::save_file_from_draft($context, slides::AREA_IMAGE, (int) $slide->id, $draftid);
        $this->assertSame('image.gif', $file->get_filename());

        // An empty draft deletes the file.
        $empty = file_get_unused_draft_itemid();
        $this->assertNull(slides::save_file_from_draft($context, slides::AREA_IMAGE, (int) $slide->id, $empty));
        $this->assertNull(slides::get_file($context, slides::AREA_IMAGE, (int) $slide->id));
    }

    public function test_filemanager_options(): void {
        $options = slides::filemanager_options(slides::AREA_IMAGE);
        $this->assertSame(1, (int) $options['maxfiles']);
        $this->assertNotContains('.svg', (array) $options['accepted_types']);
        $this->assertSame('audio.ogg', slides::stored_filename(slides::AREA_AUDIO, 'ogg'));
        $this->assertSame('cover.webp', slides::stored_filename(slides::AREA_COVER, 'webp'));
    }

    public function test_options_for_instance(): void {
        $this->resetAfterTest();
        [$instance] = $this->setup_activity();
        $a = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'options' => 2]);
        $b = $this->modgen()->create_slide(['whakaharatauid' => $instance->id, 'options' => 3]);
        $all = slides::get_options_for_instance((int) $instance->id);
        $this->assertCount(2, $all[$a->id]);
        $this->assertCount(3, $all[$b->id]);
        $this->assertSame(array_map(fn($o) => (int) $o->id, $b->options), array_map('intval', array_keys($all[$b->id])));
    }
}
