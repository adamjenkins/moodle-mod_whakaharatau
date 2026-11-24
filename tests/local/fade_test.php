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
use mod_whakaharatau\form\fade_slider;
use mod_whakaharatau\form\slide_form;
use mod_whakaharatau\output\player;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the picture fade: site defaults, activity defaults, each slide's own fade and the cover's.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(instance_settings::class)]
#[CoversClass(slides::class)]
#[CoversClass(slide_form::class)]
#[CoversClass(fade_slider::class)]
#[CoversClass(player::class)]
final class fade_test extends \advanced_testcase {
    /**
     * Load lib.php.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course and an activity.
     *
     * @param array $settings instance settings
     * @return array [course, instance record, cmid]
     */
    private function setup_activity(array $settings = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id] + $settings);
        return [$course, $DB->get_record('whakaharatau', ['id' => $created->id], '*', MUST_EXIST), (int) $created->cmid];
    }

    /**
     * Built in, the fade is on at 15%; the site's defaults replace it for new activities and their slides.
     *
     * @return void
     */
    public function test_defaults_site_to_activity_to_slide(): void {
        $this->resetAfterTest();
        [, $instance] = $this->setup_activity();
        $slide = slides::get_slide(slides::create_slide((int) $instance->id, (object) []));
        $this->assertEquals([1, 15, 1, 15], [$instance->fade, $instance->fadewidth, $instance->coverfade,
            $instance->coverfadewidth]);
        $this->assertEquals([1, 15], [$slide->fade, $slide->fadewidth]);

        set_config('fade', 0, 'whakaharatau');
        set_config('fadewidth', 30, 'whakaharatau');
        set_config('coverfade', 0, 'whakaharatau');
        $this->assertSame(0, instance_settings::get_defaults()['fade']);
        $this->assertSame(30, instance_settings::get_defaults()['fadewidth']);
        $defaults = instance_settings::get_defaults();
        [, $second] = $this->setup_activity(['fade' => $defaults['fade'], 'fadewidth' => $defaults['fadewidth']]);
        $slide = slides::get_slide(slides::create_slide((int) $second->id, (object) []));
        $this->assertEquals([0, 30], [$slide->fade, $slide->fadewidth]);
        // A submitted form without the fields (locked) takes the site default.
        $data = (object) [];
        whakaharatau_normalise_instance_data($data);
        $this->assertSame([0, 30, 0, 15], [$data->fade, $data->fadewidth, $data->coverfade, $data->coverfadewidth]);
        // The existing activity and its slide did not change.
        $this->assertEquals(1, slides::get_slide((int) array_key_first(slides::get_slides((int) $instance->id)))->fade);
    }

    /**
     * Widths are kept between 0 and 50; off, or 0, is no fade at either edge; the bottom fade is the site's.
     *
     * @return void
     */
    public function test_get_fade_and_range(): void {
        $this->resetAfterTest();
        $this->assertSame(['fadeside' => 25, 'fadebottom' => 10], instance_settings::get_fade(1, 25));
        $this->assertSame(['fadeside' => 0, 'fadebottom' => 0], instance_settings::get_fade(0, 25));
        $this->assertSame(['fadeside' => 0, 'fadebottom' => 0], instance_settings::get_fade(1, 0));
        $this->assertSame(50, instance_settings::get_fade(1, 99)['fadeside']);
        set_config('stackedfade', 0, 'whakaharatau');
        $this->assertSame(0, instance_settings::get_fade(1, 25)['fadebottom']);

        [, $instance] = $this->setup_activity(['fadewidth' => 400, 'coverfadewidth' => -3]);
        $this->assertEquals([50, 0], [$instance->fadewidth, $instance->coverfadewidth]);
        $slideid = slides::create_slide((int) $instance->id, (object) ['fade' => 1, 'fadewidth' => 80]);
        $this->assertEquals(50, slides::get_slide($slideid)->fadewidth);

        foreach (['0', '15', '50', ' 7 '] as $good) {
            $this->assertNull(fade_slider::validate($good), $good);
        }
        foreach (['', '51', '-1', '7.5', 'abc', '100'] as $bad) {
            $this->assertNotNull(fade_slider::validate($bad), $bad);
        }
    }

    /**
     * A slide's fade is saved from its form, unless the site locks it.
     *
     * @return void
     */
    public function test_locked_fade_is_not_taken_from_the_slide_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $instance, $cmid] = $this->setup_activity();
        $context = context_module::instance($cmid);
        $post = (object) ['slideid' => 0, 'alttext' => 'A dog', 'fade' => 0, 'fadewidth' => '40', 'optioncount' => 2];
        $slideid = slide_form::save($context, $instance, clone $post);
        $this->assertEquals([0, 40], [slides::get_slide($slideid)->fade, slides::get_slide($slideid)->fadewidth]);

        set_config('fade_locked', 1, 'whakaharatau');
        set_config('fadewidth_locked', 1, 'whakaharatau');
        $newid = slide_form::save($context, $instance, clone $post);
        $this->assertEquals([1, 15], [slides::get_slide($newid)->fade, slides::get_slide($newid)->fadewidth]);
        $post->slideid = $slideid;
        $post->fade = 1;
        slide_form::save($context, $instance, $post);
        $this->assertEquals(0, slides::get_slide($slideid)->fade);
    }

    /**
     * The player gets each slide's fade; the start screen uses the first slide's until there is a cover.
     *
     * @return void
     */
    public function test_player_gets_the_fade(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');
        [$course, $instance, $cmid] = $this->setup_activity(['coverfade' => 1, 'coverfadewidth' => 33]);
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_whakaharatau');
        $slide = $modgen->create_slide(['whakaharatauid' => $instance->id]);
        slides::update_slide((object) ['id' => $slide->id, 'fade' => 1, 'fadewidth' => 42]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = $modgen->create_attempt(['whakaharatauid' => $instance->id, 'userid' => $student->id]);
        $data = $attempt->get_slide_data((int) $slide->id);
        $this->assertSame([42, 10], [$data['fadeside'], $data['fadebottom']]);

        $this->setUser($student);
        $renderer = $PAGE->get_renderer('core');
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $start = (new player($instance, $cm, false))->export_for_template($renderer);
        $this->assertSame(42, $start['fadeside']);
        slides::save_file_from_string(context_module::instance($cmid), slides::AREA_COVER, 0, $modgen->get_png());
        $start = (new player($instance, $cm, false))->export_for_template($renderer);
        $this->assertSame(33, $start['fadeside']);
        $html = $renderer->render_from_template('mod_whakaharatau/player', $start);
        $this->assertStringContainsString('--mwh-fade-side: 33%; --mwh-fade-bottom: 10%;', $html);
    }

    /**
     * slides.csv carries the fade; an empty or unreadable cell leaves it to the activity's default.
     *
     * @return void
     */
    public function test_manifest_fade(): void {
        $paths = ['1.png' => importer::KIND_IMAGE, 'a.mp3' => importer::KIND_AUDIO, 'b.mp3' => importer::KIND_AUDIO];
        $plan = importer::parse_manifest("image,fade,fadewidth,audio1,audio2,correct\n1.png,0,35%,a.mp3,b.mp3,1\n"
            . "1.png,,,a.mp3,b.mp3,1\n1.png,yes,lots,a.mp3,b.mp3,1\n1.png,1,90,a.mp3,b.mp3,1\n", $paths);
        $got = array_map(fn($slide) => [$slide['fade'], $slide['fadewidth']], $plan['slides']);
        $this->assertSame([[0, 35], [null, null], [1, null], [1, 50]], $got);
    }
}
