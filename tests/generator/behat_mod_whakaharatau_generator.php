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
 * Behat data generator for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for mod_whakaharatau.
 *
 * Usage:
 *     And the following "mod_whakaharatau > slides" exist:
 *       | activity | alttext | options | correctoption | transcripts   |
 *       | L1       | A dog   | 3       | 2             | kurī,ngeru,manu |
 *
 * "activity" is the activity's idnumber or name. "correctoption" is 1-based (0 = no correct option).
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_whakaharatau_generator extends behat_generator_base {
    /**
     * Entities this generator can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'slides' => [
                'singular' => 'slide',
                'datagenerator' => 'slide',
                'required' => ['activity'],
                'switchids' => ['activity' => 'whakaharatauid'],
            ],
        ];
    }

    /**
     * Instance id of an activity from its idnumber or name.
     *
     * @param string $identifier idnumber or name
     * @return int whakaharatau id
     */
    protected function get_activity_id(string $identifier): int {
        $cm = $this->get_cm_by_activity_name('whakaharatau', $identifier);
        return (int) $cm->instance;
    }

    /**
     * Turn the Behat columns into create_slide() fields.
     *
     * @param array $data row data
     * @return array
     */
    protected function preprocess_slide(array $data): array {
        if (array_key_exists('correctoption', $data)) {
            $data['correct'] = (int) $data['correctoption'] - 1;
            unset($data['correctoption']);
        }
        return $data;
    }
}
