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

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Choose an option on the current slide. The server marks it; this is the only place an answer is marked.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_answer extends player_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'slideid' => new external_value(PARAM_INT, 'Slide id'),
            'optionid' => new external_value(PARAM_INT, 'Option id'),
        ]);
    }

    /**
     * Submit the answer.
     *
     * @param int $attemptid attempt id
     * @param int $slideid slide id (must be the current slide)
     * @param int $optionid option id
     * @return array the result of attempt::submit_answer() plus the new state and slides: the answered slide
     *      (refreshed, e.g. with transcripts once finished), the slide now current and the one after it
     */
    public static function execute(int $attemptid, int $slideid, int $optionid): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'slideid' => $slideid, 'optionid' => $optionid]
        );
        $attempt = self::load_own_attempt($params['attemptid'], true);

        // Two answers sent at once must not both pass the "already chosen" and tries checks: hold a lock on the
        // attempt and reload it inside the lock, so the checks see any answer stored by the other request.
        $lock = \core\lock\lock_config::get_lock_factory('mod_whakaharatau')
            ->get_lock('attempt' . $attempt->get_id(), 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $attempt = self::load_own_attempt($params['attemptid'], true);
            $result = $attempt->submit_answer((int) $params['slideid'], (int) $params['optionid']);
        } finally {
            $lock->release();
        }
        $state = $attempt->get_state();

        $slides = [];
        // The answered slide, refreshed. It is no longer in the order when a new round dropped it.
        if (in_array((int) $params['slideid'], $attempt->get_slideorder(), true)) {
            $slides[] = $attempt->get_slide_data((int) $params['slideid']);
        }
        if (!$attempt->is_finished()) {
            foreach (self::slides_payload($attempt) as $slide) {
                if ((int) $slide['slideid'] !== (int) $params['slideid']) {
                    $slides[] = $slide;
                }
            }
        }

        // Transcripts of the answered slide, judged by the round it was answered in: when the answer built a
        // new round, the slide is unfinished again there, and the refreshed slide data hides them.
        $answeredround = $result['newround'] === null ? null : (int) $result['newround'] - 1;
        $result['transcripts'] = $attempt->get_visible_transcripts((int) $params['slideid'], $answeredround);

        $result['summary'] = $result['summary'] === null ? null : self::clean_summary($result['summary']);
        $result['state'] = $state;
        $result['slides'] = $slides;
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'slideid' => new external_value(PARAM_INT, 'Slide answered'),
            'optionid' => new external_value(PARAM_INT, 'Option chosen'),
            'feedback' => new external_value(PARAM_BOOL, 'Whether feedback is shown during the attempt'),
            'correct' => new external_value(
                PARAM_BOOL,
                'Whether it was right; null when feedback is hidden',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'slidefinished' => new external_value(PARAM_BOOL, 'Whether the slide is now finished'),
            'triesleft' => new external_value(
                PARAM_INT,
                'Tries left; null = until correct',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'correctoptionid' => new external_value(
                PARAM_INT,
                'The correct option, only when the slide is finished and feedback is shown',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'roundfinished' => new external_value(PARAM_BOOL, 'Whether the round is finished'),
            'newround' => new external_value(
                PARAM_INT,
                'Number of the round just built',
                VALUE_REQUIRED,
                null,
                NULL_ALLOWED
            ),
            'attemptfinished' => new external_value(PARAM_BOOL, 'Whether the attempt is finished'),
            'summary' => self::summary_structure(NULL_ALLOWED),
            'state' => self::state_structure(),
            'slides' => self::slides_structure(),
            'transcripts' => new external_multiple_structure(new external_single_structure([
                'optionid' => new external_value(PARAM_INT, 'Option'),
                'transcript' => new external_value(PARAM_RAW, 'Its transcript (plain text)'),
            ]), 'Transcripts of the answered slide the learner may now see'),
        ]);
    }
}
