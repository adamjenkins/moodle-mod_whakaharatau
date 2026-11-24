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

namespace mod_whakaharatau\backup;

use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;

/**
 * Backup and restore round trip, with and without user data.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass source course */
    private $course;
    /** @var \stdClass source activity record */
    private $instance;
    /** @var \stdClass[] source slides (with ->options) */
    private $slides;
    /** @var \stdClass student */
    private $student;
    /** @var int finished attempt id */
    private $finishedid;
    /** @var int unfinished (round 2) attempt id */
    private $inprogressid;

    /**
     * Load the backup and restore libraries.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course with a shuffled Repeat All activity, three slides (one with prompt audio) and a cover, a finished
     * session and a session in round 2.
     *
     * @return void
     */
    private function setup_data(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $generator->get_plugin_generator('mod_whakaharatau');
        $this->course = $generator->create_course(['summary' => '']);
        $created = $generator->create_module('whakaharatau', ['course' => $this->course->id, 'shuffle' => 1,
            'repeatmode' => instance_settings::REPEAT_ALL, 'optionorder' => instance_settings::OPTIONORDER_RANDOM,
            'transcriptsbefore' => 1, 'name' => 'Kurī listening']);
        $context = \context_module::instance($created->cmid);
        $this->slides = [
            $modgen->create_slide(['whakaharatauid' => $created->id, 'alttext' => 'A dog', 'prompt' => 'He aha?',
                'promptaudio' => true, 'options' => 3, 'correct' => 2, 'transcripts' => ['kurī', 'ngeru', 'manu']]),
            $modgen->create_slide(['whakaharatauid' => $created->id, 'alttext' => 'A cat', 'options' => 2, 'correct' => 0]),
            $modgen->create_slide(['whakaharatauid' => $created->id, 'decorative' => 1, 'alttext' => '', 'options' => 4,
                'correct' => 3]),
        ];
        slides::save_file_from_string($context, slides::AREA_COVER, 0, $modgen->get_png());
        $this->instance = $DB->get_record('whakaharatau', ['id' => $created->id], '*', MUST_EXIST);
        $this->student = $generator->create_and_enrol($this->course, 'student');

        $finished = $modgen->create_attempt(['whakaharatauid' => $this->instance->id, 'userid' => $this->student->id,
            'answers' => ['right', 'wrongthenright', 'right'], 'finish' => true]);
        $this->finishedid = $finished->get_id();
        // A second session: round 1 done, round 2 started with one answer.
        $inprogress = $modgen->create_attempt(['whakaharatauid' => $this->instance->id, 'userid' => $this->student->id,
            'forcenew' => true, 'answers' => ['right', 'right', 'right', 'wrong']]);
        $this->inprogressid = $inprogress->get_id();
        $this->assertSame(2, $inprogress->get_round());
    }

    /**
     * Back up the course and restore it as a new course.
     *
     * @param bool $users include user data
     * @return \stdClass the restored activity record
     */
    private function backup_and_restore(bool $users): \stdClass {
        global $DB, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $dirname = 'whakaharatau_restore_' . (int) $users;
        $path = make_backup_temp_directory($dirname);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored' . (int) $users, $this->course->category);
        $rc = new \restore_controller(
            $dirname,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $DB->get_record('whakaharatau', ['course' => $newcourseid], '*', MUST_EXIST);
    }

    /**
     * Map old slide and option ids to the restored ones by sort order.
     *
     * @param \stdClass $restored restored activity
     * @return array [slide map old => new, option map old => new]
     */
    private function id_maps(\stdClass $restored): array {
        $newslides = array_values(slides::get_slides((int) $restored->id));
        $this->assertCount(count($this->slides), $newslides);
        $slidemap = [];
        $optionmap = [];
        foreach ($this->slides as $i => $slide) {
            $slidemap[(int) $slide->id] = (int) $newslides[$i]->id;
            $newoptions = array_values(slides::get_options((int) $newslides[$i]->id));
            $this->assertCount(count($slide->options), $newoptions);
            foreach ($slide->options as $j => $option) {
                $optionmap[(int) $option->id] = (int) $newoptions[$j]->id;
            }
        }
        return [$slidemap, $optionmap];
    }

    public function test_restore_without_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();
        $DB->update_record('whakaharatau', (object) ['id' => $this->instance->id, 'coverfade' => 0, 'fadewidth' => 22]);
        $this->instance = $DB->get_record('whakaharatau', ['id' => $this->instance->id]);
        slides::update_slide((object) ['id' => $this->slides[0]->id, 'fade' => 0, 'fadewidth' => 44]);
        $restored = $this->backup_and_restore(false);
        $copy = slides::get_slide($this->id_maps($restored)[0][(int) $this->slides[0]->id]);
        $this->assertEquals([0, 44], [$copy->fade, $copy->fadewidth]);

        foreach (
            ['name', 'mode', 'shuffle', 'repeatmode', 'optionorder', 'showprogress', 'displayinline',
                'feedbackmode', 'triesperslide', 'slidescore', 'attempts', 'grademethod', 'allowreview', 'scoring',
                'autoplay', 'transcriptsbefore', 'grade', 'completionallslides', 'fade', 'fadewidth', 'coverfade',
                'coverfadewidth'] as $field
        ) {
            $this->assertEquals($this->instance->$field, $restored->$field, $field);
        }
        [$slidemap, $optionmap] = $this->id_maps($restored);
        $cm = get_coursemodule_from_instance('whakaharatau', $restored->id, $restored->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        foreach ($this->slides as $slide) {
            $new = slides::get_slide($slidemap[(int) $slide->id]);
            $this->assertSame($slide->alttext, $new->alttext);
            $this->assertSame((int) $slide->decorative, (int) $new->decorative);
            $this->assertSame($slide->prompt, $new->prompt);
            $this->assertNotNull(slides::get_file($context, slides::AREA_IMAGE, (int) $new->id));
            $this->assertSame(slides::get_correct_optionid((int) $slide->id) === null ? null :
                $optionmap[slides::get_correct_optionid((int) $slide->id)], slides::get_correct_optionid((int) $new->id));
            foreach ($slide->options as $option) {
                $newoption = slides::get_option($optionmap[(int) $option->id]);
                $this->assertSame($option->transcript, $newoption->transcript);
                $this->assertNotNull(slides::get_file($context, slides::AREA_AUDIO, (int) $newoption->id));
            }
        }
        $first = $slidemap[(int) $this->slides[0]->id];
        $this->assertNotNull(slides::get_file($context, slides::AREA_PROMPT, $first));
        $this->assertNotNull(slides::get_file($context, slides::AREA_COVER, 0));
        $this->assertTrue(slides::is_playable($restored, $context));
        $this->assertSame(0, $DB->count_records('whakaharatau_attempt', ['whakaharatauid' => $restored->id]));
    }

    public function test_restore_with_user_data_remaps_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();
        $restored = $this->backup_and_restore(true);
        [$slidemap, $optionmap] = $this->id_maps($restored);

        $oldattempts = $DB->get_records('whakaharatau_attempt', ['whakaharatauid' => $this->instance->id], 'attempt ASC');
        $newattempts = array_values($DB->get_records(
            'whakaharatau_attempt',
            ['whakaharatauid' => $restored->id],
            'attempt ASC'
        ));
        $this->assertCount(2, $newattempts);
        foreach (array_values($oldattempts) as $i => $old) {
            $new = $newattempts[$i];
            $this->assertSame((int) $this->student->id, (int) $new->userid);
            foreach (['mode', 'attempt', 'state', 'shuffle', 'repeatmode', 'round', 'currentslide', 'beststreak'] as $f) {
                $this->assertEquals($old->$f, $new->$f, $f);
            }
            $this->assertEquals($old->score, $new->score);

            // The frozen slide order is the same order in new ids.
            $expected = implode(',', array_map(fn($id) => $slidemap[(int) $id], explode(',', $old->slideorder)));
            $this->assertSame($expected, $new->slideorder);

            // The frozen option orders: keys and values in new ids, same order.
            $oldorder = json_decode($old->optionorder, true);
            $neworder = json_decode($new->optionorder, true);
            $this->assertCount(count($oldorder), $neworder);
            foreach ($oldorder as $oldslideid => $optionids) {
                $this->assertArrayHasKey((string) $slidemap[(int) $oldslideid], $neworder);
                $this->assertSame(
                    array_map(fn($id) => $optionmap[(int) $id], $optionids),
                    array_map('intval', $neworder[(string) $slidemap[(int) $oldslideid]])
                );
            }

            // Responses point at the new slides and options.
            $oldresponses = array_values($DB->get_records('whakaharatau_response', ['attemptid' => $old->id], 'id ASC'));
            $newresponses = array_values($DB->get_records('whakaharatau_response', ['attemptid' => $new->id], 'id ASC'));
            $this->assertCount(count($oldresponses), $newresponses);
            foreach ($oldresponses as $j => $response) {
                $this->assertSame($slidemap[(int) $response->slideid], (int) $newresponses[$j]->slideid);
                $this->assertSame($optionmap[(int) $response->optionid], (int) $newresponses[$j]->optionid);
                $this->assertSame((int) $response->correct, (int) $newresponses[$j]->correct);
                $this->assertSame((int) $response->round, (int) $newresponses[$j]->round);
            }
        }

        // The restored session in round 2 resumes where it stopped and plays on.
        $cm = get_coursemodule_from_instance('whakaharatau', $restored->id, $restored->course, false, MUST_EXIST);
        $resumed = attempt::get_inprogress($restored, $cm, (int) $this->student->id);
        $this->assertNotNull($resumed);
        $original = attempt::get_by_id($this->inprogressid);
        $this->assertSame(2, $resumed->get_round());
        $this->assertSame($original->get_position(), $resumed->get_position());
        $this->assertSame(array_map(fn($id) => $slidemap[$id], $original->get_slideorder()), $resumed->get_slideorder());
        $slideid = $resumed->get_slideorder()[$resumed->get_position()];
        $this->assertSame(array_map(fn($id) => $optionmap[$id], $original->get_optionorder(
            array_search($slideid, $slidemap, true)
        )), $resumed->get_optionorder($slideid));
        $result = $resumed->submit_answer($slideid, (int) slides::get_correct_optionid($slideid));
        $this->assertTrue($result['correct']);

        // The finished session's summary is the same.
        $finished = attempt::get_latest_finished($restored, $cm, (int) $this->student->id);
        $this->assertSame(
            attempt::get_by_id($this->finishedid)->get_summary()['firsttrycorrect'],
            $finished->get_summary()['firsttrycorrect']
        );
    }

    /**
     * A backup is input: hostile slide and option fields are cleaned on restore as the forms clean them, and the
     * instance settings are clamped.
     *
     * @return void
     */
    public function test_restore_cleans_hostile_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();
        // What a crafted backup could carry, written straight into the source rows.
        $first = $this->slides[0];
        $DB->update_record('whakaharatau_slide', (object) ['id' => $first->id, 'alttext' => '<b>A dog</b>',
            'prompt' => '<a href="javascript:alert(1)">Listen</a>', 'promptformat' => FORMAT_HTML,
            'promptlang' => '<img src=x onerror=1>', 'decorative' => 7]);
        $DB->set_field('whakaharatau_option', 'transcript', '<i>kurī</i>', ['id' => $first->options[0]->id]);
        $DB->set_field('whakaharatau_option', 'iscorrect', 5, ['id' => $first->options[2]->id]);
        $DB->set_field('whakaharatau', 'grade', -40, ['id' => $this->instance->id]);

        $restored = $this->backup_and_restore(false);
        [$slidemap, $optionmap] = $this->id_maps($restored);
        $slide = slides::get_slide($slidemap[(int) $first->id]);
        $this->assertSame('A dog', $slide->alttext);
        $this->assertSame('Listen', $slide->prompt);
        $this->assertSame((int) FORMAT_PLAIN, (int) $slide->promptformat);
        $this->assertSame('imgsrcxonerror1', $slide->promptlang);
        $this->assertSame(1, (int) $slide->decorative);
        $this->assertSame('kurī', slides::get_option($optionmap[(int) $first->options[0]->id])->transcript);
        $this->assertSame(1, (int) slides::get_option($optionmap[(int) $first->options[2]->id])->iscorrect);
        $this->assertSame(0, (int) $restored->grade);
    }

    /**
     * Versions, with their files, and answers to a slide that was deleted come back with the user data.
     *
     * @return void
     */
    public function test_restore_versions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();
        $cm = get_coursemodule_from_instance('whakaharatau', $this->instance->id);
        $context = \context_module::instance($cm->id);
        $first = (int) $this->slides[0]->id;
        $DB->set_field('whakaharatau_slide', 'alttext', 'A new dog', ['id' => $first]);
        $this->assertSame(2, \mod_whakaharatau\local\versions::sync($context, $first));
        $deleted = (int) array_pop($this->slides)->id;
        slides::delete_slide($context, $deleted);
        $answers = $DB->count_records('whakaharatau_response');

        $restored = $this->backup_and_restore(true);
        $newcontext = \context_module::instance(get_coursemodule_from_instance('whakaharatau', $restored->id)->id);
        $newfirst = $this->id_maps($restored)[0][$first];
        $versions = \mod_whakaharatau\local\versions::get_all((int) $restored->id);
        $this->assertSame(['A dog', 'A new dog'], array_column($versions[$newfirst], 'alttext'));
        $this->assertSame(2, (int) slides::get_slide($newfirst)->version);
        $this->assertNotNull(\mod_whakaharatau\local\versions::get_file_url(
            $newcontext,
            (int) $versions[$newfirst][1]->id,
            'audio-3'
        ));
        $this->assertArrayHasKey(-$deleted, $versions);
        $this->assertSame(2 * $answers, $DB->count_records('whakaharatau_response'));
        $this->assertTrue($DB->record_exists('whakaharatau_response', ['slideid' => -$deleted]));
        // The restored content is its current version: saving it unchanged makes no new one.
        $this->assertSame(2, \mod_whakaharatau\local\versions::sync($newcontext, $newfirst));

        // Without the answers, everything is version 1.
        $bare = $this->backup_and_restore(false);
        $this->assertSame([1, 1], array_map('intval', array_column(slides::get_slides((int) $bare->id), 'version')));
        $this->assertSame([], \mod_whakaharatau\local\versions::get_all((int) $bare->id));
    }
}
