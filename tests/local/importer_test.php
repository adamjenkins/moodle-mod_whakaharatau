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
use context_user;

/**
 * Tests for the slide importer and exporter.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_whakaharatau\local\importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_whakaharatau\local\exporter::class)]
final class importer_test extends \advanced_testcase {
    /** @var string Bytes that pass the MP3 content check. */
    const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00 test audio";

    /**
     * File-name grouping: prefix, natural order, correct markers, prompt audio and unmatched files.
     *
     * @return void
     */
    public function test_group_by_filenames(): void {
        $plan = importer::group_by_filenames([
            '02.png' => importer::KIND_IMAGE,
            '02-b_x.mp3' => importer::KIND_AUDIO,
            '02-a.mp3' => importer::KIND_AUDIO,
            '01.jpg' => importer::KIND_IMAGE,
            '01-c.mp3' => importer::KIND_AUDIO,
            '01-a.mp3' => importer::KIND_AUDIO,
            '01-b-correct.mp3' => importer::KIND_AUDIO,
            '01-prompt.mp3' => importer::KIND_AUDIO,
            '010-a.mp3' => importer::KIND_AUDIO,
            'lonely.jpg' => importer::KIND_IMAGE,
        ]);
        $this->assertSame(importer::SOURCE_FILENAMES, $plan['source']);
        $this->assertCount(2, $plan['slides']);

        [$first, $second] = $plan['slides'];
        $this->assertSame('01.jpg', $first['image']);
        $this->assertSame(['01-a.mp3', '01-b-correct.mp3', '01-c.mp3'], array_column($first['options'], 'audio'));
        $this->assertSame(1, $first['correct']);
        $this->assertSame('01-prompt.mp3', $first['promptaudio']);
        $this->assertSame('', $first['alttext']);

        $this->assertSame('02.png', $second['image']);
        $this->assertSame(['02-a.mp3', '02-b_x.mp3'], array_column($second['options'], 'audio'));
        $this->assertSame(1, $second['correct']);

        // The name "010-a" does not belong to "01"; an image with no clips is not a slide.
        $this->assertSame(['010-a.mp3', 'lonely.jpg'], $plan['unmatched']);
    }

    /**
     * File-name grouping with no marker, two markers and too few clips.
     *
     * @return void
     */
    public function test_group_by_filenames_warnings(): void {
        $plan = importer::group_by_filenames([
            '01.jpg' => importer::KIND_IMAGE,
            '01-a.mp3' => importer::KIND_AUDIO,
            '01-b.mp3' => importer::KIND_AUDIO,
            '02.jpg' => importer::KIND_IMAGE,
            '02-a_x.mp3' => importer::KIND_AUDIO,
            '02-b-correct.mp3' => importer::KIND_AUDIO,
            '03.jpg' => importer::KIND_IMAGE,
            '03-a-correct.mp3' => importer::KIND_AUDIO,
        ]);
        $keys = fn(array $slide) => array_column($slide['warnings'], 'key');
        $this->assertNull($plan['slides'][0]['correct']);
        $this->assertContains('importwarn_nocorrect', $keys($plan['slides'][0]));
        $this->assertNull($plan['slides'][1]['correct']);
        $this->assertContains('importwarn_manycorrect', $keys($plan['slides'][1]));
        $this->assertSame(0, $plan['slides'][2]['correct']);
        $this->assertContains('importwarn_toofewoptions', $keys($plan['slides'][2]));
    }

    /**
     * Manifest parsing: columns, correct by audio column number, folders, missing files and unmatched files.
     *
     * @return void
     */
    public function test_parse_manifest(): void {
        $csv = "\xEF\xBB\xBFimage,alt,prompt,correct,audio1,audio2,audio3,transcript1,transcript2,transcript3\r\n"
            . "pics/kuri.jpg,A dog,\"He aha, tēnei?\",3,a.mp3,,c.mp3,kurī,,ngeru\r\n"
            . ",,,,,,,,,\r\n"
            . "missing.jpg,Gone,,1,a.mp3,b.mp3,,,,\r\n";
        $plan = importer::parse_manifest($csv, [
            'set/pics/kuri.jpg' => importer::KIND_IMAGE,
            'set/a.mp3' => importer::KIND_AUDIO,
            'set/b.mp3' => importer::KIND_AUDIO,
            'set/c.mp3' => importer::KIND_AUDIO,
            'set/extra.mp3' => importer::KIND_AUDIO,
        ], 'set/');
        $this->assertSame(importer::SOURCE_MANIFEST, $plan['source']);
        $this->assertCount(2, $plan['slides']);

        $first = $plan['slides'][0];
        $this->assertSame('set/pics/kuri.jpg', $first['image']);
        $this->assertSame('A dog', $first['alttext']);
        $this->assertSame('He aha, tēnei?', $first['prompt']);
        $this->assertSame(['set/a.mp3', 'set/c.mp3'], array_column($first['options'], 'audio'));
        $this->assertSame(['kurī', 'ngeru'], array_column($first['options'], 'transcript'));
        // Column audio3 is the second option.
        $this->assertSame(1, $first['correct']);

        $second = $plan['slides'][1];
        $this->assertNull($second['image']);
        $this->assertContains('importwarn_missingfile', array_column($second['warnings'], 'key'));
        $this->assertSame(0, $second['correct']);

        $this->assertSame(['set/extra.mp3'], $plan['unmatched']);
    }

    /**
     * A manifest with semicolons, and one without an image column.
     *
     * @return void
     */
    public function test_parse_manifest_separator_and_bad_header(): void {
        $plan = importer::parse_manifest("image;alt;correct;audio1;audio2\n01.jpg;Kai;2;a.mp3;b.mp3\n", [
            '01.jpg' => importer::KIND_IMAGE,
            'a.mp3' => importer::KIND_AUDIO,
            'b.mp3' => importer::KIND_AUDIO,
        ]);
        $this->assertCount(1, $plan['slides']);
        $this->assertSame(1, $plan['slides'][0]['correct']);

        $plan = importer::parse_manifest("picture,alt\n01.jpg,Kai\n", ['01.jpg' => importer::KIND_IMAGE]);
        $this->assertSame([], $plan['slides']);
        $this->assertSame('importwarn_noimagecolumn', $plan['warnings'][0]['key']);
        $this->assertSame(['01.jpg'], $plan['unmatched']);
    }

    /**
     * Path sanitising drops traversal, hidden files and system folders.
     *
     * @return void
     */
    public function test_sanitise_path(): void {
        $this->assertSame('etc/passwd.jpg', importer::sanitise_path('../../etc/passwd.jpg'));
        $this->assertSame('a/b.mp3', importer::sanitise_path('/a//./b.mp3'));
        $this->assertSame('a/b.mp3', importer::sanitise_path('a\\b.mp3'));
        $this->assertNull(importer::sanitise_path('__MACOSX/01.jpg'));
        $this->assertNull(importer::sanitise_path('set/._01.jpg'));
        $this->assertNull(importer::sanitise_path('.hidden'));
        $this->assertNull(importer::sanitise_path(''));
    }

    /**
     * Review choices: exclude a slide, set alt text, change and clear the correct option.
     *
     * @return void
     */
    public function test_apply_choices(): void {
        $plan = importer::group_by_filenames([
            '01.jpg' => importer::KIND_IMAGE,
            '01-a.mp3' => importer::KIND_AUDIO,
            '01-b_x.mp3' => importer::KIND_AUDIO,
            '02.jpg' => importer::KIND_IMAGE,
            '02-a_x.mp3' => importer::KIND_AUDIO,
            '02-b.mp3' => importer::KIND_AUDIO,
            '03.jpg' => importer::KIND_IMAGE,
            '03-a.mp3' => importer::KIND_AUDIO,
            '03-b.mp3' => importer::KIND_AUDIO,
        ]);
        $result = importer::apply_choices($plan, [
            0 => ['include' => true, 'alttext' => ' Kai ', 'correct' => 0],
            1 => ['include' => false],
            2 => ['decorative' => true, 'correct' => 9],
        ]);
        $this->assertCount(2, $result['slides']);
        $this->assertSame('Kai', $result['slides'][0]['alttext']);
        $this->assertSame(0, $result['slides'][0]['correct']);
        $this->assertSame('03.jpg', $result['slides'][1]['image']);
        $this->assertSame(1, $result['slides'][1]['decorative']);
        $this->assertNull($result['slides'][1]['correct']);
    }

    /**
     * Stage a ZIP plus loose files, build the plan and apply it; nothing is written before apply().
     *
     * @return void
     */
    public function test_stage_and_apply(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();

        $uploadid = $this->make_upload([
            '01.jpg' => \mod_whakaharatau_generator::PNG,
            '01-a.mp3' => self::MP3,
            '01-b-correct.mp3' => self::MP3,
            'readme.txt' => 'not media',
        ], [
            '02.png' => \mod_whakaharatau_generator::PNG,
            '02-a_x.mp3' => self::MP3,
            '02-b.mp3' => self::MP3,
            '__MACOSX/._02.png' => 'junk',
        ]);

        $staged = importer::stage($uploadid);
        $this->assertSame([['name' => 'readme.txt', 'reason' => 'importnotmedia']], $staged['rejected']);
        $this->assertSame(0, slides::count_slides((int) $instance->id));

        $plan = importer::build_plan($staged['draftitemid']);
        $this->assertCount(2, $plan['slides']);
        $hash = importer::plan_hash($plan);
        $this->assertSame($hash, importer::plan_hash(importer::build_plan($staged['draftitemid'])));

        $plan = importer::apply_choices($plan, [0 => ['alttext' => 'One'], 1 => ['alttext' => 'Two']]);
        $this->assertSame(2, importer::apply($context, $instance, $staged['draftitemid'], $plan, false));

        $slides = array_values(slides::get_slides((int) $instance->id));
        $this->assertCount(2, $slides);
        $this->assertSame('One', $slides[0]->alttext);
        $this->assertTrue(slides::is_playable($instance, $context));
        // Stored names are neutral: the "-correct" marker never reaches the activity.
        $options = array_values(slides::get_options((int) $slides[0]->id));
        $this->assertSame('audio.mp3', slides::get_file($context, slides::AREA_AUDIO, (int) $options[1]->id)->get_filename());
        $this->assertSame((int) $options[1]->id, slides::get_correct_optionid((int) $slides[0]->id));
        // The stage is gone.
        $this->assertSame([], importer::get_staged_files($staged['draftitemid']));
    }

    /**
     * Export then import into another activity gives the same slides (round trip), and "replace" replaces.
     *
     * @return void
     */
    public function test_export_import_round_trip(): void {
        $this->resetAfterTest();
        [$source, $sourcecontext] = $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $generator->create_slide(['whakaharatauid' => $source->id, 'alttext' => 'Kurī', 'prompt' => 'He aha?',
            'options' => 3, 'correct' => 2, 'transcripts' => ['kurī', 'ngeru', 'manu']]);
        $firstid = (int) array_key_first(slides::get_slides((int) $source->id));
        slides::update_slide((object) ['id' => $firstid, 'fade' => 0, 'fadewidth' => 44]);
        $generator->create_slide(['whakaharatauid' => $source->id, 'decorative' => 1, 'alttext' => '', 'options' => 2,
            'correct' => 0]);

        $files = exporter::get_archive_files($sourcecontext, $source);
        $this->assertArrayHasKey(importer::MANIFEST, $files);
        foreach (array_keys($files) as $path) {
            $this->assertStringNotContainsString('correct', $path);
        }

        // Pack, upload as the teacher would, and import into a second activity that already has a slide.
        [$target, $targetcontext] = $this->setup_activity();
        $generator->create_slide(['whakaharatauid' => $target->id]);
        $zippath = exporter::export_to_file($sourcecontext, $source);
        $uploadid = $this->make_upload(['slides.zip' => file_get_contents($zippath)]);
        $staged = importer::stage($uploadid);
        $this->assertSame([], $staged['rejected']);
        $plan = importer::build_plan($staged['draftitemid']);
        $this->assertSame(importer::SOURCE_MANIFEST, $plan['source']);
        $this->assertSame([], $plan['unmatched']);
        importer::apply($targetcontext, $target, $staged['draftitemid'], $plan, true);

        $sourceslides = array_values(slides::get_slides((int) $source->id));
        $targetslides = array_values(slides::get_slides((int) $target->id));
        $this->assertCount(2, $targetslides);
        foreach ($sourceslides as $i => $slide) {
            $copy = $targetslides[$i];
            $this->assertSame($slide->alttext, $copy->alttext);
            $this->assertSame((int) $slide->decorative, (int) $copy->decorative);
            $this->assertSame($slide->prompt, $copy->prompt);
            $this->assertEquals([$slide->fade, $slide->fadewidth], [$copy->fade, $copy->fadewidth]);
            $a = array_values(slides::get_options((int) $slide->id));
            $b = array_values(slides::get_options((int) $copy->id));
            $this->assertSame(array_column($a, 'transcript'), array_column($b, 'transcript'));
            $this->assertSame(
                array_map('intval', array_column($a, 'iscorrect')),
                array_map('intval', array_column($b, 'iscorrect'))
            );
        }
        $this->assertTrue(slides::is_playable($target, $targetcontext));
    }

    /**
     * A course, an editing teacher (set as the current user) and an activity.
     *
     * @return array [instance record, module context]
     */
    private function setup_activity(): array {
        global $USER;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        if (empty($USER->id) || isguestuser()) {
            $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
            $this->setUser($teacher);
        } else {
            $this->getDataGenerator()->enrol_user($USER->id, $course->id, 'editingteacher');
        }
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        return [$instance, context_module::instance($instance->cmid)];
    }

    /**
     * Put files into a new draft area of the current user, as the import form's file manager would.
     *
     * @param array $loose file name => content of loose files
     * @param array $zipped path => content of files packed into one "set.zip" (none when empty)
     * @return int draft item id
     */
    private function make_upload(array $loose, array $zipped = []): int {
        global $USER;
        $fs = get_file_storage();
        $usercontextid = context_user::instance($USER->id)->id;
        $draftid = file_get_unused_draft_itemid();
        $record = ['contextid' => $usercontextid, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/'];
        foreach ($loose as $name => $content) {
            $fs->create_file_from_string($record + ['filename' => $name], $content);
        }
        if ($zipped) {
            $packer = get_file_packer('application/zip');
            $packer->archive_to_storage(
                array_map(fn($c) => [$c], $zipped),
                $usercontextid,
                'user',
                'draft',
                $draftid,
                '/',
                'set.zip'
            );
        }
        return $draftid;
    }
}
