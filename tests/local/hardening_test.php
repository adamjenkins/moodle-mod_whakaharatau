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
 * Regression tests for the release preflight findings: a stale attempt copy, report delete scope, text cleaning,
 * spreadsheet formulas in the exported manifest, and an over-limit file inside an import ZIP.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attempt::class)]
#[CoversClass(report::class)]
#[CoversClass(slides::class)]
#[CoversClass(exporter::class)]
#[CoversClass(importer::class)]
final class hardening_test extends \advanced_testcase {
    /**
     * Load the libraries the attempt and report code use.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
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
     * A course with a two-slide activity.
     *
     * @param array $settings instance settings
     * @return array [course, instance record]
     */
    private function setup_activity(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id] + $settings);
        $this->modgen()->create_slide(['whakaharatauid' => $created->id, 'options' => 3, 'correct' => 1]);
        $this->modgen()->create_slide(['whakaharatauid' => $created->id, 'options' => 3, 'correct' => 1]);
        return [$course, $DB->get_record('whakaharatau', ['id' => $created->id], '*', MUST_EXIST)];
    }

    /**
     * A copy of an attempt loaded before another request finished it can neither answer nor write "in progress"
     * back: the finished state, score and grade stay.
     *
     * @return void
     */
    public function test_stale_copy_cannot_reopen_a_finished_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $instance] = $this->setup_activity(['mode' => instance_settings::MODE_TEST]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id,
            'answers' => ['right']]);

        $stale = attempt::get_by_id($attempt->get_id());
        attempt::get_by_id($attempt->get_id())->finish();
        $stored = $DB->get_record('whakaharatau_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(attempt::STATE_FINISHED, $stored->state);

        $order = $stale->get_slideorder();
        $slideid = $order[$stale->get_position()];
        try {
            $stale->submit_answer($slideid, $this->modgen()->pick_option($stale, $slideid, true));
            $this->fail('A stale copy answered a finished attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptfinished', $e->errorcode);
        }
        // Finishing the stale copy returns the stored result and rewrites nothing.
        $stale->finish();
        $after = $DB->get_record('whakaharatau_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(attempt::STATE_FINISHED, $after->state);
        $this->assertEquals($stored->score, $after->score);
        $this->assertSame((int) $stored->timefinish, (int) $after->timefinish);
        $this->assertSame(1, $DB->count_records('whakaharatau_response', ['attemptid' => $attempt->get_id()]));
    }

    /**
     * Deleting from the report ignores another activity's attempt ids, and, in separate groups for a teacher
     * without "access all groups", an attempt of a learner outside the teacher's group.
     *
     * @return void
     */
    public function test_report_delete_is_scoped_to_instance_and_group(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $instance] = $this->setup_activity(['groupmode' => SEPARATEGROUPS]);
        [, $other] = $this->setup_activity();
        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $ina = $generator->create_and_enrol($course, 'student');
        $inb = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $ina->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $inb->id]);
        $othercourse = $DB->get_record('course', ['id' => $other->course]);
        $outsider = $generator->create_and_enrol($othercourse, 'student');

        $attemptb = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $inb->id]);
        $attempta = $this->modgen()->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $ina->id]);
        $foreign = $this->modgen()->create_attempt(['whakaharatauid' => $other->id, 'userid' => $outsider->id]);

        $this->setUser($teacher);
        $cmid = (int) get_coursemodule_from_instance('whakaharatau', $instance->id, $course->id, false, MUST_EXIST)->id;
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $context = context_module::instance($cm->id);
        // The teacher's report shows their own group.
        $report = new report($instance, $cm, $context, instance_settings::MODE_PRACTICE, (int) $groupa->id);
        $this->assertSame(1, $report->delete_attempts([$attemptb->get_id(), $foreign->get_id(), $attempta->get_id()]));
        $this->assertTrue($DB->record_exists('whakaharatau_attempt', ['id' => $attemptb->get_id()]));
        $this->assertTrue($DB->record_exists('whakaharatau_attempt', ['id' => $foreign->get_id()]));
        $this->assertFalse($DB->record_exists('whakaharatau_attempt', ['id' => $attempta->get_id()]));

        // All participants in separate groups without accessallgroups: nobody is in scope.
        $all = new report($instance, $cm, $context, instance_settings::MODE_PRACTICE, 0);
        $this->assertFalse($all->can_see_any());
        $this->assertSame(0, $all->delete_attempts([$attemptb->get_id()]));
    }

    /**
     * Alt text, prompts and transcripts are stored as plain text whichever way they arrive, and the prompt format
     * is always plain.
     *
     * @return void
     */
    public function test_text_fields_are_cleaned_on_every_path(): void {
        global $DB;
        $this->resetAfterTest();
        [, $instance] = $this->setup_activity();
        $slideid = slides::create_slide((int) $instance->id, (object) ['alttext' => '<b>A dog</b> &amp; cat',
            'prompt' => '<script>x</script>Listen', 'promptformat' => FORMAT_HTML, 'promptlang' => '<img src=x>']);
        $slide = $DB->get_record('whakaharatau_slide', ['id' => $slideid]);
        $this->assertSame('A dog &amp; cat', $slide->alttext);
        $this->assertStringNotContainsString('<', $slide->prompt);
        $this->assertSame((int) FORMAT_PLAIN, (int) $slide->promptformat);
        $this->assertSame('imgsrcx', $slide->promptlang);

        slides::update_slide((object) ['id' => $slideid, 'prompt' => '<i>Again</i>', 'promptformat' => FORMAT_HTML]);
        $slide = $DB->get_record('whakaharatau_slide', ['id' => $slideid]);
        $this->assertSame('Again', $slide->prompt);
        $this->assertSame((int) FORMAT_PLAIN, (int) $slide->promptformat);

        $optionid = slides::create_option($slideid, (object) ['transcript' => '<u>tahi</u>']);
        $this->assertSame('tahi', $DB->get_field('whakaharatau_option', 'transcript', ['id' => $optionid]));
        slides::update_option((object) ['id' => $optionid, 'transcript' => '<b>rua</b>']);
        $this->assertSame('rua', $DB->get_field('whakaharatau_option', 'transcript', ['id' => $optionid]));
    }

    /**
     * Cells that a spreadsheet would run as a formula are written with a leading ' in slides.csv, and the importer
     * reads them back unchanged.
     *
     * @return void
     */
    public function test_manifest_formula_cells_are_escaped_and_round_trip(): void {
        $csv = exporter::to_csv([['alt', 'transcript1'], ['=HYPERLINK("x")', '+tahi'], ['Plain', '-']]);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+tahi", $csv);
        $this->assertStringContainsString(',-', $csv);

        $plan = importer::parse_manifest(
            "image,alt,correct,audio1,audio2,transcript1\r\n01.png,'=1+1,1,a.mp3,b.mp3,'@rua\r\n",
            ['01.png' => importer::KIND_IMAGE, 'a.mp3' => importer::KIND_AUDIO, 'b.mp3' => importer::KIND_AUDIO]
        );
        $this->assertCount(1, $plan['slides']);
        $this->assertSame('=1+1', $plan['slides'][0]['alttext']);
        $this->assertSame('@rua', $plan['slides'][0]['options'][0]['transcript']);
    }

    /**
     * A ZIP holding a file over the per-file upload limit is refused before anything is unpacked.
     *
     * @return void
     */
    public function test_zip_with_a_file_over_the_limit_is_not_unpacked(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $path = make_request_directory() . '/big.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('01.png', $this->modgen()->get_png());
        $zip->addFromString('01-a.mp3', str_repeat('x', 5000));
        $zip->close();
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname(['contextid' => context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/',
            'filename' => 'big.zip'], $path);

        $staged = importer::stage($draftid, 1000);
        $this->assertContains(['name' => '01-a.mp3', 'reason' => 'importfiletoolarge'], $staged['rejected']);
        $this->assertContains(['name' => 'big.zip', 'reason' => 'importzipbigfile'], $staged['rejected']);
        $this->assertSame([], importer::get_staged_files($staged['draftitemid']));
    }
}
