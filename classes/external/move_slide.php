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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_whakaharatau\local\slides;
use moodle_exception;

/**
 * External function mod_whakaharatau_move_slide: move a slide so it comes straight after another
 * (drag-reorder on the Edit slides page).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_slide extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slideid' => new external_value(PARAM_INT, 'Slide to move'),
            'afterslideid' => new external_value(PARAM_INT, 'Slide it should follow, 0 for the top', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Move the slide.
     *
     * @param int $cmid course module id
     * @param int $slideid slide to move
     * @param int $afterslideid slide it should follow, 0 for the top
     * @return array ['success' => true]
     */
    public static function execute(int $cmid, int $slideid, int $afterslideid = 0): array {
        [
            'cmid' => $cmid,
            'slideid' => $slideid,
            'afterslideid' => $afterslideid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'slideid' => $slideid,
            'afterslideid' => $afterslideid,
        ]);

        $cm = get_coursemodule_from_id('whakaharatau', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/whakaharatau:manage', $context);

        // Both slides must belong to this activity: ids from the browser are never trusted.
        $slide = slides::get_slide($slideid);
        if ((int) $slide->whakaharatauid !== (int) $cm->instance) {
            throw new moodle_exception('errorslidenotinactivity', 'mod_whakaharatau');
        }
        if ($afterslideid) {
            $after = slides::get_slide($afterslideid);
            if ((int) $after->whakaharatauid !== (int) $cm->instance) {
                throw new moodle_exception('errorslidenotinactivity', 'mod_whakaharatau');
            }
        }
        slides::move_slide_after($slideid, $afterslideid);
        return ['success' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the slide was moved'),
        ]);
    }
}
