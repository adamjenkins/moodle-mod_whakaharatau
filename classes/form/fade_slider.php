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

namespace mod_whakaharatau\form;

use mod_whakaharatau\local\instance_settings;
use MoodleQuickForm;

/**
 * The fade width field of the activity and slide forms: a number from 0 to 50, with a slider beside it.
 *
 * The number box is the form field and works without JavaScript; amd/src/fade_slider.js adds the slider.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fade_slider {
    /**
     * Add the field to a form.
     *
     * @param MoodleQuickForm $mform form
     * @param string $name element name, also the lang key of its label and help
     * @return void
     */
    public static function add_to_form(MoodleQuickForm $mform, string $name): void {
        global $PAGE;
        $mform->addElement('text', $name, get_string($name, 'mod_whakaharatau'), [
            'size' => 4,
            'maxlength' => 3,
            'inputmode' => 'numeric',
            'data-whakaharatau-fade' => instance_settings::FADE_MAX,
        ]);
        $mform->setType($name, PARAM_RAW_TRIMMED);
        $mform->setDefault($name, instance_settings::get_defaults()[$name]);
        $mform->addHelpButton($name, $name, 'mod_whakaharatau');
        $PAGE->requires->js_call_amd('mod_whakaharatau/fade_slider', 'init', ['id_' . $name]);
    }

    /**
     * Check a submitted fade width.
     *
     * @param mixed $value submitted value
     * @return string|null error message, or null when fine
     */
    public static function validate($value): ?string {
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,2}$/', $value) || (int) $value > instance_settings::FADE_MAX) {
            return get_string('errorfadewidth', 'mod_whakaharatau', instance_settings::FADE_MAX);
        }
        return null;
    }
}
