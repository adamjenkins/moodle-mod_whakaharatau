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

/**
 * Data generator for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;

/**
 * Data generator for mod_whakaharatau: instances, slides with media, and attempts.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_whakaharatau_generator extends testing_module_generator {
    /** @var string Smallest valid PNG (1x1), used as a slide image. */
    const PNG = "\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1F\x15\xC4\x89"
        . "\x00\x00\x00\rIDATx\x9Cc\xF8\x0F\x00\x00\x01\x01\x00\x05\x18\xD8N\x00\x00\x00\x00IEND\xAEB\x60\x82";

    /** @var string Bytes that pass the MP3 content check when the fixture file is missing (an ID3 header). */
    const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00 test audio";

    /**
     * Create an instance with the built-in defaults unless given.
     *
     * @param array|stdClass|null $record instance fields
     * @param array|null $options generator options
     * @return stdClass the instance record with cmid
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array) ($record ?? []);
        $defaults = instance_settings::get_builtin_defaults();
        $istest = (int) ($record['mode'] ?? $defaults['mode']) === instance_settings::MODE_TEST;
        $record += [
            'triesperslide' => $istest ? $defaults['testtries'] : $defaults['practicetries'],
            'grade' => 100,
            'completionallslides' => 0,
        ];
        foreach ($defaults as $name => $value) {
            if (!in_array($name, ['practicetries', 'testtries'], true) && !isset($record[$name])) {
                $record[$name] = $value;
            }
        }
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Content of a small playable MP3 (tests/fixtures/tiny.mp3: four silent MPEG-1 Layer III frames).
     *
     * @return string
     */
    public function get_mp3(): string {
        global $CFG;
        $path = $CFG->dirroot . '/mod/whakaharatau/tests/fixtures/tiny.mp3';
        return is_readable($path) ? file_get_contents($path) : self::MP3;
    }

    /**
     * Content of a small PNG (tests/fixtures/tiny.png, 16x12), or the 1x1 PNG when the fixture is missing.
     *
     * @return string
     */
    public function get_png(): string {
        global $CFG;
        $path = $CFG->dirroot . '/mod/whakaharatau/tests/fixtures/tiny.png';
        return is_readable($path) ? file_get_contents($path) : self::PNG;
    }

    /**
     * Create a playable slide: an image, alt text and N options with audio, one of them correct.
     *
     * @param array $record whakaharatauid (required), alttext, decorative, prompt, promptlang, promptaudio (bool),
     *      noimage (bool: leave the image out), options (int, default 4), correct (0-based index of the correct
     *      option, default 0; -1 for none), transcripts (string[] or a comma-separated string)
     * @return stdClass the slide record, with ->options (option records in order)
     */
    public function create_slide(array $record): stdClass {
        global $DB;
        $whakaharatauid = (int) $record['whakaharatauid'];
        $instance = $DB->get_record('whakaharatau', ['id' => $whakaharatauid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('whakaharatau', $instance->id, $instance->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        $slideid = slides::create_slide($whakaharatauid, (object) [
            'alttext' => $record['alttext'] ?? 'Picture ' . (slides::count_slides($whakaharatauid) + 1),
            'decorative' => (int) ($record['decorative'] ?? 0),
            'prompt' => $record['prompt'] ?? '',
            'promptlang' => $record['promptlang'] ?? '',
        ]);
        if (empty($record['noimage'])) {
            slides::save_file_from_string($context, slides::AREA_IMAGE, $slideid, $this->get_png());
        }
        if (!empty($record['promptaudio'])) {
            slides::save_file_from_string($context, slides::AREA_PROMPT, $slideid, $this->get_mp3());
        }

        $transcripts = $record['transcripts'] ?? [];
        if (is_string($transcripts)) {
            $transcripts = $transcripts === '' ? [] : array_map('trim', explode(',', $transcripts));
        }
        $count = (int) ($record['options'] ?? 4);
        $correct = (int) ($record['correct'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $optionid = slides::create_option($slideid, (object) [
                'transcript' => $transcripts[$i] ?? 'Clip ' . ($i + 1),
                'iscorrect' => $i === $correct ? 1 : 0,
            ]);
            slides::save_file_from_string($context, slides::AREA_AUDIO, $optionid, $this->get_mp3());
        }
        $slide = slides::get_slide($slideid);
        $slide->options = array_values(slides::get_options($slideid));
        return $slide;
    }

    /**
     * Start (or resume) an attempt for a user and optionally answer slides.
     *
     * @param array $record whakaharatauid (required), userid (required), forcenew (bool), answers (list of
     *      'right' | 'wrong' | 'wrongthenright', applied to the current slide in turn), finish (bool: finish the
     *      attempt afterwards if it is still in progress)
     * @return attempt the attempt, reloaded
     */
    public function create_attempt(array $record): attempt {
        global $DB;
        $instance = $DB->get_record('whakaharatau', ['id' => (int) $record['whakaharatauid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('whakaharatau', $instance->id, $instance->course, false, MUST_EXIST);
        $attempt = attempt::start($instance, $cm, (int) $record['userid'], !empty($record['forcenew']));
        foreach ($record['answers'] ?? [] as $answer) {
            $attempt = attempt::get_by_id($attempt->get_id());
            if ($attempt->is_finished()) {
                break;
            }
            if ($answer === 'wrongthenright') {
                $this->answer_current($attempt, false);
                $attempt = attempt::get_by_id($attempt->get_id());
                $this->answer_current($attempt, true);
            } else {
                $this->answer_current($attempt, $answer === 'right');
            }
        }
        $attempt = attempt::get_by_id($attempt->get_id());
        if (!empty($record['finish']) && !$attempt->is_finished()) {
            $attempt->finish();
            $attempt = attempt::get_by_id($attempt->get_id());
        }
        return $attempt;
    }

    /**
     * Answer the attempt's current slide with its correct option, or with a wrong option not chosen yet.
     *
     * @param attempt $attempt the attempt (in progress)
     * @param bool $correct choose the correct option
     * @return array the submit_answer() result
     */
    public function answer_current(attempt $attempt, bool $correct): array {
        $order = $attempt->get_slideorder();
        $slideid = $order[$attempt->get_position()];
        return $attempt->submit_answer($slideid, $this->pick_option($attempt, $slideid, $correct));
    }

    /**
     * An option of a slide: the correct one, or the first wrong one not chosen yet in this round.
     *
     * @param attempt $attempt the attempt
     * @param int $slideid slide id
     * @param bool $correct pick the correct option
     * @return int option id
     */
    public function pick_option(attempt $attempt, int $slideid, bool $correct): int {
        $correctid = slides::get_correct_optionid($slideid);
        if ($correct) {
            return (int) $correctid;
        }
        $chosen = $attempt->get_slide_status($slideid)['chosen'];
        foreach ($attempt->get_optionorder($slideid) as $optionid) {
            if ($optionid !== $correctid && !in_array($optionid, $chosen, true)) {
                return $optionid;
            }
        }
        throw new coding_exception('No wrong option left on slide ' . $slideid);
    }
}
