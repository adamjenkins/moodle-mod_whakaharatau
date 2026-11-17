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

namespace mod_whakaharatau\event;

/**
 * A learner finished a Listening practice attempt or practice session.
 *
 * Created as attempt_submitted::create(['objectid' => attempt id, 'context' => module context,
 * 'relateduserid' => attempt owner, 'other' => ['whakaharatauid' => int, 'mode' => int]]).
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int whakaharatauid: the activity instance id.
 *      - int mode: 0 = Practice, 1 = Test.
 * }
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_submitted extends attempt_base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'whakaharatau_attempt';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptsubmitted', 'mod_whakaharatau');
    }

    /**
     * Non-localised description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' has finished attempt with id '$this->objectid' " .
            "for the user with id '$this->relateduserid' in the Listening practice activity with course module id " .
            "'$this->contextinstanceid'.";
    }
}
