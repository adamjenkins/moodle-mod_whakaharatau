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
 * Read the state of the user's own attempt and the media of some of its slides.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_attempt_state extends player_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'slideids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Slide id'),
                'Slides to return; default the current slide and the next one',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Read the state.
     *
     * @param int $attemptid attempt id
     * @param int[] $slideids slides of the current round to return
     * @return array state and slides
     */
    public static function execute(int $attemptid, array $slideids = []): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'slideids' => $slideids]
        );
        $attempt = self::load_own_attempt($params['attemptid'], false);
        return [
            'state' => $attempt->get_state(),
            'slides' => self::slides_payload($attempt, array_slice($params['slideids'], 0, 10)),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => self::state_structure(),
            'slides' => self::slides_structure(),
        ]);
    }
}
