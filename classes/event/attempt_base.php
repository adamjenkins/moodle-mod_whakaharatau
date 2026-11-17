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
 * Shared parts of the attempt events (attempt_started, attempt_submitted).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class attempt_base extends \core\event\base {
    /**
     * URL of the activity.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/whakaharatau/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Check the event data.
     *
     * @return void
     * @throws \coding_exception when required data is missing
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['whakaharatauid'])) {
            throw new \coding_exception('The \'whakaharatauid\' value must be set in other.');
        }
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }

    /**
     * Mapping of the object id for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'whakaharatau_attempt', 'restore' => 'whakaharatau_attempt'];
    }

    /**
     * Mapping of the values in 'other' for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'whakaharatauid' => ['db' => 'whakaharatau', 'restore' => 'whakaharatau'],
            'mode' => \core\event\base::NOT_MAPPED,
        ];
    }
}
