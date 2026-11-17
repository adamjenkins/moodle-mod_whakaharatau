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

namespace mod_whakaharatau\external;

use context_module;
use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\slides;
use moodle_exception;

/**
 * Shared checks and return structures of the player's external functions.
 *
 * Every function: validate_context() on the module context, mod/whakaharatau:view, and for writes
 * mod/whakaharatau:attempt or mod/whakaharatau:manage (teacher preview). An attempt is only ever
 * read or changed by its owner. Nothing returned here says which option is correct before the slide is
 * finished: the payloads come from attempt::get_state(), get_slide_data(), submit_answer(),
 * get_summary() and get_review(), which enforce that.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class player_api extends external_api {
    /**
     * Course module, instance and context of a cmid, after validate_context() and the view capability.
     *
     * @param int $cmid course module id
     * @return array [\cm_info $cm, stdClass $instance, context_module $context]
     */
    protected static function setup_cm(int $cmid): array {
        global $DB;
        [, $cm] = get_course_and_cm_from_cmid($cmid, 'whakaharatau');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/whakaharatau:view', $context);
        $instance = $DB->get_record('whakaharatau', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$cm, $instance, $context];
    }

    /**
     * Require the right to play: mod/whakaharatau:attempt, or mod/whakaharatau:manage for a teacher preview.
     *
     * @param context_module $context module context
     * @return void
     * @throws \required_capability_exception
     */
    protected static function require_can_attempt(context_module $context): void {
        if (!has_any_capability(['mod/whakaharatau:attempt', 'mod/whakaharatau:manage'], $context)) {
            throw new \required_capability_exception($context, 'mod/whakaharatau:attempt', 'nopermissions', '');
        }
    }

    /**
     * Load an attempt that belongs to the current user, after the context and capability checks.
     *
     * @param int $attemptid attempt id
     * @param bool $write whether the call changes the attempt (needs attempt or manage)
     * @return attempt
     * @throws moodle_exception errornotyourattempt
     */
    protected static function load_own_attempt(int $attemptid, bool $write): attempt {
        global $USER;
        $attempt = attempt::get_by_id($attemptid);
        $context = $attempt->get_context();
        self::validate_context($context);
        require_capability('mod/whakaharatau:view', $context);
        if ($write) {
            self::require_can_attempt($context);
        }
        if ($attempt->get_userid() !== (int) $USER->id) {
            throw new moodle_exception('errornotyourattempt', 'mod_whakaharatau');
        }
        return $attempt;
    }

    /**
     * Slide data for the player: the requested slides of the current round, or by default the slide at the
     * current position and the one after it (so the browser can preload the next slide's media).
     *
     * @param attempt $attempt the attempt
     * @param int[] $slideids requested slide ids; ids not in the current round are ignored
     * @return array list of attempt::get_slide_data() arrays
     */
    protected static function slides_payload(attempt $attempt, array $slideids = []): array {
        $order = $attempt->get_slideorder();
        if (!$slideids) {
            $position = $attempt->get_position();
            $slideids = array_slice($order, $position, 2);
        }
        $wanted = array_values(array_unique(array_map('intval', $slideids)));
        $result = [];
        foreach ($wanted as $slideid) {
            if (in_array($slideid, $order, true)) {
                $result[] = $attempt->get_slide_data($slideid);
            }
        }
        return $result;
    }

    /**
     * Image URLs of the slides in a summary, for the end screen's thumbnail strip.
     *
     * @param attempt $attempt the attempt
     * @param array $summary attempt::get_summary()
     * @return array list of: slideid, imageurl (string|null)
     */
    protected static function images_payload(attempt $attempt, array $summary): array {
        $existing = slides::get_slides((int) $attempt->get_instance()->id);
        $images = [];
        foreach ($summary['slides'] as $slide) {
            $slideid = (int) $slide['slideid'];
            if (!isset($existing[$slideid])) {
                continue;
            }
            $url = slides::get_file_url($attempt->get_context(), slides::AREA_IMAGE, $slideid);
            $images[] = ['slideid' => $slideid, 'imageurl' => $url ? $url->out(false) : null];
        }
        return $images;
    }

    /**
     * Structure of attempt::get_state().
     *
     * @return external_single_structure
     */
    protected static function state_structure(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number of the user'),
            'mode' => new external_value(PARAM_INT, '0 Practice, 1 Test'),
            'finished' => new external_value(PARAM_BOOL, 'Whether the attempt is finished'),
            'round' => new external_value(PARAM_INT, 'Current round, from 1'),
            'repeatmode' => new external_value(PARAM_INT, '0 Off, 1 All, 2 Missed only'),
            'shuffle' => new external_value(PARAM_BOOL, 'Whether slides are shuffled'),
            'feedback' => new external_value(PARAM_BOOL, 'Whether feedback is shown after each answer'),
            'position' => new external_value(PARAM_INT, 'First unfinished slide of the round, 0-based'),
            'slidecount' => new external_value(PARAM_INT, 'Slides in this round'),
            'totalslides' => new external_value(PARAM_INT, 'Slides in the activity'),
            'roundcomplete' => new external_value(PARAM_BOOL, 'Every slide of the round is finished; call finish_attempt'),
            'slides' => new external_multiple_structure(new external_single_structure([
                'slideid' => new external_value(PARAM_INT, 'Slide id'),
                'position' => new external_value(PARAM_INT, 'Position in the round, 0-based'),
                'number' => new external_value(PARAM_INT, 'Number in the round, 1-based'),
                'finished' => new external_value(PARAM_BOOL, 'Whether the slide is finished'),
                'tries' => new external_value(PARAM_INT, 'Options chosen so far'),
                'triesleft' => new external_value(
                    PARAM_INT,
                    'Tries left; null = until correct',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'correct' => new external_value(
                    PARAM_BOOL,
                    'Answered right; null while unfinished or feedback hidden',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'options' => new external_multiple_structure(new external_single_structure([
                    'optionid' => new external_value(PARAM_INT, 'Option id'),
                    'number' => new external_value(PARAM_INT, 'Number on the slide, 1-based'),
                    'state' => new external_value(PARAM_ALPHA, 'none, chosen, wrong, correct or revealed'),
                ])),
            ])),
        ]);
    }

    /**
     * Structure of a list of attempt::get_slide_data().
     *
     * @return external_multiple_structure
     */
    protected static function slides_structure(): external_multiple_structure {
        return new external_multiple_structure(self::slide_structure());
    }

    /**
     * Structure of attempt::get_slide_data().
     *
     * @return external_single_structure
     */
    protected static function slide_structure(): external_single_structure {
        return new external_single_structure([
            'slideid' => new external_value(PARAM_INT, 'Slide id'),
            'imageurl' => new external_value(PARAM_URL, 'Image URL', VALUE_REQUIRED, null, NULL_ALLOWED),
            'alttext' => new external_value(PARAM_RAW, 'Alternative text (plain text, empty when decorative)'),
            'decorative' => new external_value(PARAM_BOOL, 'Whether the image is decorative'),
            'prompthtml' => new external_value(PARAM_RAW, 'Formatted prompt, empty when none'),
            'promptlang' => new external_value(
                PARAM_RAW,
                'Language of the prompt, e.g. mi',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'promptaudiourl' => new external_value(PARAM_URL, 'Prompt audio URL', VALUE_REQUIRED, null, NULL_ALLOWED),
            'options' => new external_multiple_structure(new external_single_structure([
                'optionid' => new external_value(PARAM_INT, 'Option id'),
                'number' => new external_value(PARAM_INT, 'Number on the slide, 1-based'),
                'audiourl' => new external_value(PARAM_URL, 'Audio URL', VALUE_REQUIRED, null, NULL_ALLOWED),
                'transcript' => new external_value(
                    PARAM_RAW,
                    'Transcript (plain text), when it may be shown',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
            ])),
        ]);
    }

    /**
     * Structure of attempt::get_summary().
     *
     * @param int $allownull NULL_ALLOWED when the summary may be null
     * @return external_single_structure
     */
    protected static function summary_structure(int $allownull = NULL_NOT_ALLOWED): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number'),
            'mode' => new external_value(PARAM_INT, '0 Practice, 1 Test'),
            'finished' => new external_value(PARAM_BOOL, 'Whether the attempt is finished'),
            'score' => new external_value(PARAM_FLOAT, 'Score'),
            'maxscore' => new external_value(PARAM_FLOAT, 'Maximum score'),
            'percent' => new external_value(PARAM_FLOAT, 'Percentage (Test)', VALUE_REQUIRED, null, NULL_ALLOWED),
            'grade' => new external_value(PARAM_FLOAT, 'Grade (Test)', VALUE_REQUIRED, null, NULL_ALLOWED),
            'maxgrade' => new external_value(PARAM_INT, 'Maximum grade'),
            'slides' => new external_multiple_structure(new external_single_structure([
                'slideid' => new external_value(PARAM_INT, 'Slide id'),
                'number' => new external_value(PARAM_INT, 'Number, 1-based'),
                'finished' => new external_value(PARAM_BOOL, 'Finished'),
                'correct' => new external_value(PARAM_BOOL, 'Answered right'),
                'firsttry' => new external_value(PARAM_BOOL, 'Right on the first try'),
                'tries' => new external_value(PARAM_INT, 'Tries'),
                'score' => new external_value(PARAM_FLOAT, 'Slide score, 0..1'),
            ])),
            'slidesplayed' => new external_value(PARAM_INT, 'Slides finished'),
            'firsttrycorrect' => new external_value(PARAM_INT, 'Slides right first time'),
            'rounds' => new external_value(PARAM_INT, 'Rounds played'),
            'beststreak' => new external_value(PARAM_INT, 'Best streak of first-try answers'),
            'missedcount' => new external_value(PARAM_INT, 'Slides missed (Practice)'),
            'attemptsleft' => new external_value(
                PARAM_INT,
                'Test attempts left; null = unlimited',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'canretry' => new external_value(PARAM_BOOL, 'Whether a new attempt may be started'),
            'canreview' => new external_value(PARAM_BOOL, 'Whether the answers may be reviewed'),
        ], 'Summary', VALUE_REQUIRED, null, $allownull);
    }

    /**
     * Structure of the end screen's slide images.
     *
     * @return external_multiple_structure
     */
    protected static function images_structure(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'slideid' => new external_value(PARAM_INT, 'Slide id'),
            'imageurl' => new external_value(PARAM_URL, 'Image URL', VALUE_REQUIRED, null, NULL_ALLOWED),
        ]));
    }

    /**
     * Structure of attempt::get_review().
     *
     * @return external_multiple_structure
     */
    protected static function review_structure(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'slideid' => new external_value(PARAM_INT, 'Slide id'),
            'number' => new external_value(PARAM_INT, 'Number in the review, 1-based'),
            'round' => new external_value(PARAM_INT, 'Round'),
            'chosen' => new external_multiple_structure(new external_value(PARAM_INT, 'Option id'), 'Chosen, in try order'),
            'correctoptionid' => new external_value(PARAM_INT, 'Correct option id', VALUE_REQUIRED, null, NULL_ALLOWED),
            'correct' => new external_value(PARAM_BOOL, 'Answered right'),
            'firsttry' => new external_value(PARAM_BOOL, 'Right on the first try'),
            'tries' => new external_value(PARAM_INT, 'Tries'),
            'score' => new external_value(PARAM_FLOAT, 'Slide score, 0..1'),
            'slide' => self::slide_structure(),
        ]), 'Review', VALUE_OPTIONAL);
    }

    /**
     * Make a summary safe for the return structure (floats as floats).
     *
     * @param array $summary attempt::get_summary()
     * @return array
     */
    protected static function clean_summary(array $summary): array {
        $summary['score'] = (float) $summary['score'];
        $summary['maxscore'] = (float) $summary['maxscore'];
        $summary['percent'] = $summary['percent'] === null ? null : (float) $summary['percent'];
        $summary['grade'] = $summary['grade'] === null ? null : (float) $summary['grade'];
        foreach ($summary['slides'] as $i => $slide) {
            $summary['slides'][$i]['score'] = (float) $slide['score'];
        }
        return $summary;
    }
}
