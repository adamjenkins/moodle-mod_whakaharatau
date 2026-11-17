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
use core_external\external_single_structure;
use core_external\external_value;
use mod_whakaharatau\local\attempt;

/**
 * Finish the user's own attempt (idempotent) and return its summary, and its review when allowed.
 *
 * Used by the end screen, by the exit X in Practice with Repeat All (the session summary), by
 * "Review answers", and to show the result of an attempt finished earlier.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_attempt extends player_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'includereview' => new external_value(
                PARAM_BOOL,
                'Include the review when it is allowed',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Finish the attempt.
     *
     * @param int $attemptid attempt id
     * @param bool $includereview include the answers with the correct options (only when the attempt allows review)
     * @return array summary, images, canreview and (when requested and allowed) review
     */
    public static function execute(int $attemptid, bool $includereview = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'includereview' => $includereview]
        );
        // Finishing changes the attempt; looking at a finished one's summary does not.
        $attempt = self::load_own_attempt($params['attemptid'], false);
        if (!$attempt->is_finished()) {
            self::require_can_attempt($attempt->get_context());
            // The same lock as submit_answer, and a reload inside it, so an answer stored at the same moment
            // cannot reopen the attempt after it is finished (and after its review has been shown).
            $lock = attempt::lock($attempt->get_id());
            try {
                $attempt = self::load_own_attempt($params['attemptid'], false);
                $summary = $attempt->finish();
            } finally {
                $lock->release();
            }
        } else {
            $summary = $attempt->finish();
        }
        $result = [
            'summary' => self::clean_summary($summary),
            'images' => self::images_payload($attempt, $summary),
        ];
        if ($params['includereview'] && $attempt->can_review()) {
            $review = $attempt->get_review();
            foreach ($review as $i => $item) {
                $review[$i]['score'] = (float) $item['score'];
            }
            $result['review'] = $review;
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'summary' => self::summary_structure(),
            'images' => self::images_structure(),
            'review' => self::review_structure(),
        ]);
    }
}
