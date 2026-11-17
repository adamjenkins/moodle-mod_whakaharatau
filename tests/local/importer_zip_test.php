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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Importer tests with real ZIP files: the committed fixture, hostile entry names and a manifest import.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(importer::class)]
final class importer_zip_test extends \advanced_testcase {
    /**
     * A course, an editing teacher (the current user) and an activity.
     *
     * @return array [instance, context]
     */
    private function setup_activity(): array {
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $instance = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        return [$instance, context_module::instance($instance->cmid)];
    }

    /**
     * Put one file into a new draft area of the current user.
     *
     * @param string $filename file name
     * @param string $content content
     * @return int draft item id
     */
    private function upload(string $filename, string $content): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(['contextid' => context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/',
            'filename' => $filename], $content);
        return $draftid;
    }

    /**
     * Build a ZIP in a temp file with the given entries (names written as given, unsanitised).
     *
     * @param array $entries entry name => content
     * @return string the ZIP's bytes
     */
    private function make_zip(array $entries): string {
        $path = make_request_directory() . '/upload.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return file_get_contents($path);
    }

    public function test_fixture_zip_is_grouped_by_file_names(): void {
        global $CFG;
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $zip = file_get_contents($CFG->dirroot . '/mod/whakaharatau/tests/fixtures/import_filenames.zip');
        $staged = importer::stage($this->upload('import_filenames.zip', $zip));
        $this->assertSame([['name' => 'notes.txt', 'reason' => 'importnotmedia']], $staged['rejected']);

        $plan = importer::build_plan($staged['draftitemid']);
        $this->assertSame(importer::SOURCE_FILENAMES, $plan['source']);
        $this->assertCount(2, $plan['slides']);
        $this->assertSame(1, $plan['slides'][0]['correct']);
        $this->assertNull($plan['slides'][1]['correct']);
        $this->assertSame(['03-a.mp3'], $plan['unmatched']);

        // The teacher fixes slide 2 on the review screen (its second clip) and adds alt text.
        $plan = importer::apply_choices($plan, [
            0 => ['alttext' => 'A dog'],
            1 => ['alttext' => 'A cat', 'correct' => 1],
        ]);
        $this->assertSame(2, importer::apply($context, $instance, $staged['draftitemid'], $plan, false));
        $this->assertTrue(slides::is_playable($instance, $context));
    }

    public function test_hostile_entry_names_stay_inside_the_stage(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $png = $generator->get_png();
        $mp3 = $generator->get_mp3();
        $zip = $this->make_zip([
            '../../../evil/01.png' => $png,
            'set/../../01-a.mp3' => $mp3,
            '/absolute/01-b_x.mp3' => $mp3,
            '__MACOSX/._01.png' => 'junk',
            'set/.hidden.mp3' => $mp3,
            'ok/02.png' => $png,
        ]);
        $staged = importer::stage($this->upload('hostile.zip', $zip));

        $files = importer::get_staged_files($staged['draftitemid']);
        $this->assertNotEmpty($files);
        foreach ($files as $path => $file) {
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringStartsNotWith('/', $path);
            $this->assertStringNotContainsString('__MACOSX', $path);
            $this->assertStringNotContainsString('.hidden', $path);
            $this->assertSame(context_user::instance($USER->id)->id, (int) $file->get_contextid());
            $this->assertSame('draft', $file->get_filearea());
            $this->assertSame((int) $staged['draftitemid'], (int) $file->get_itemid());
        }
        // Nothing was written anywhere but the user's own draft areas.
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "filename = '01.png' AND component <> 'user'"
        ));
        importer::discard_stage($staged['draftitemid']);
        $this->assertSame([], importer::get_staged_files($staged['draftitemid']));
    }

    public function test_manifest_zip_import(): void {
        $this->resetAfterTest();
        [$instance, $context] = $this->setup_activity();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $png = $generator->get_png();
        $mp3 = $generator->get_mp3();
        $csv = "image,alt,prompt,correct,audio1,audio2,audio3,transcript1,transcript2,transcript3\n"
            . "kuri.png,A dog,He aha tēnei?,2,one.mp3,two.mp3,three.mp3,tahi,rua,toru\n"
            . "ngeru.png,A cat,,3,one.mp3,two.mp3,three.mp3,,,\n";
        $zip = $this->make_zip([
            'slides.csv' => $csv,
            'kuri.png' => $png,
            'ngeru.png' => $png,
            'one.mp3' => $mp3,
            'two.mp3' => $mp3,
            'three.mp3' => $mp3,
            // Named like a slide, but the manifest decides.
            '01.png' => $png,
        ]);
        $staged = importer::stage($this->upload('set.zip', $zip));
        $plan = importer::build_plan($staged['draftitemid']);
        $this->assertSame(importer::SOURCE_MANIFEST, $plan['source']);
        $this->assertCount(2, $plan['slides']);
        $this->assertSame(['01.png'], $plan['unmatched']);
        $this->assertSame(1, $plan['slides'][0]['correct']);
        $this->assertSame(2, $plan['slides'][1]['correct']);

        importer::apply($context, $instance, $staged['draftitemid'], $plan, false);
        $slides = array_values(slides::get_slides((int) $instance->id));
        $this->assertSame('He aha tēnei?', $slides[0]->prompt);
        $options = array_values(slides::get_options((int) $slides[0]->id));
        $this->assertSame(['tahi', 'rua', 'toru'], array_column($options, 'transcript'));
        $this->assertSame((int) $options[1]->id, slides::get_correct_optionid((int) $slides[0]->id));
        // Each slide got its own copy of a shared clip.
        $a = slides::get_file($context, slides::AREA_AUDIO, (int) $options[0]->id);
        $secondoptions = array_values(slides::get_options((int) $slides[1]->id));
        $b = slides::get_file($context, slides::AREA_AUDIO, (int) $secondoptions[0]->id);
        $this->assertNotSame($a->get_id(), $b->get_id());
        $this->assertSame($a->get_contenthash(), $b->get_contenthash());
    }
}
