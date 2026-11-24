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
 * Restore structure step for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores whakaharatau.xml: the instance, slides, options and (with user data) attempts and responses.
 *
 * The attempts' frozen slideorder (comma list of slide ids) and optionorder (JSON {slideid: [optionid, ...]})
 * and every response's slideid/optionid are rewritten to the new ids.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_whakaharatau_activity_structure_step extends restore_activity_structure_step {
    /**
     * The paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('whakaharatau', '/activity/whakaharatau');
        $paths[] = new restore_path_element('whakaharatau_slide', '/activity/whakaharatau/slides/slide');
        $paths[] = new restore_path_element('whakaharatau_option', '/activity/whakaharatau/slides/slide/options/option');
        if ($userinfo) {
            $paths[] = new restore_path_element('whakaharatau_attempt', '/activity/whakaharatau/userattempts/attempt');
            $paths[] = new restore_path_element(
                'whakaharatau_response',
                '/activity/whakaharatau/userattempts/attempt/responses/response'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the instance.
     *
     * @param array $data element data
     * @return void
     */
    protected function process_whakaharatau($data) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
        $data = (object) $data;
        // A backup from before the fade settings: the fade every picture had then, whatever the site default is now.
        foreach (['fade', 'coverfade'] as $field) {
            $data->$field = $data->$field ?? 1;
            $data->{$field . 'width'} = $data->{$field . 'width'} ?? \mod_whakaharatau\local\instance_settings::FADE_WIDTH;
        }
        // A backup file is input: clamp the settings as the activity form does. The backed-up row is passed as the
        // current record too, so values the form would keep (such as a Practice activity's maximum grade) are kept.
        whakaharatau_normalise_instance_data($data, clone $data);
        $data->course = $this->get_courseid();
        $data->timecreated = $data->timecreated ?? time();
        $data->timemodified = time();
        $newid = $DB->insert_record('whakaharatau', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore one slide.
     *
     * @param array $data element data
     * @return void
     */
    protected function process_whakaharatau_slide($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->whakaharatauid = $this->get_new_parentid('whakaharatau');
        \mod_whakaharatau\local\slides::clean_slide_fields($data);
        $newid = $DB->insert_record('whakaharatau_slide', $data);
        $this->set_mapping('whakaharatau_slide', $oldid, $newid, true);
    }

    /**
     * Restore one option.
     *
     * @param array $data element data
     * @return void
     */
    protected function process_whakaharatau_option($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->slideid = $this->get_new_parentid('whakaharatau_slide');
        \mod_whakaharatau\local\slides::clean_option_fields($data);
        $newid = $DB->insert_record('whakaharatau_option', $data);
        $this->set_mapping('whakaharatau_option', $oldid, $newid, true);
    }

    /**
     * Restore one attempt, remapping its frozen slide and option orders.
     *
     * @param array $data element data
     * @return void
     */
    protected function process_whakaharatau_attempt($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->whakaharatauid = $this->get_new_parentid('whakaharatau');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $this->apply_date_offset($data->timefinish);
        $data->slideorder = $this->map_slideorder((string) ($data->slideorder ?? ''));
        $data->optionorder = $this->map_optionorder((string) ($data->optionorder ?? ''));
        $newid = $DB->insert_record('whakaharatau_attempt', $data);
        $this->set_mapping('whakaharatau_attempt', $oldid, $newid);
    }

    /**
     * Restore one response. Responses to slides or options that were not in the backup (deleted before it was
     * made) are dropped: every reader skips them anyway.
     *
     * @param array $data element data
     * @return void
     */
    protected function process_whakaharatau_response($data) {
        global $DB;
        $data = (object) $data;
        $data->attemptid = $this->get_new_parentid('whakaharatau_attempt');
        if (!$data->attemptid) {
            return;
        }
        $data->slideid = $this->get_mappingid('whakaharatau_slide', $data->slideid);
        $data->optionid = $this->get_mappingid('whakaharatau_option', $data->optionid);
        if (!$data->slideid || !$data->optionid) {
            return;
        }
        $data->timeanswered = $this->apply_date_offset($data->timeanswered);
        $DB->insert_record('whakaharatau_response', $data);
    }

    /**
     * Map a comma-separated list of old slide ids to new ids, dropping slides that were not restored.
     *
     * @param string $slideorder old list
     * @return string new list
     */
    protected function map_slideorder(string $slideorder): string {
        $new = [];
        foreach (explode(',', $slideorder) as $oldid) {
            $oldid = (int) trim($oldid);
            if ($oldid && ($newid = $this->get_mappingid('whakaharatau_slide', $oldid))) {
                $new[] = (int) $newid;
            }
        }
        return implode(',', $new);
    }

    /**
     * Map the JSON object {slideid: [optionid, ...]} to new slide and option ids, dropping unknown ids.
     *
     * @param string $optionorder old JSON
     * @return string new JSON ('' when empty or unreadable)
     */
    protected function map_optionorder(string $optionorder): string {
        if ($optionorder === '') {
            return '';
        }
        $decoded = json_decode($optionorder, true);
        if (!is_array($decoded)) {
            return '';
        }
        $new = [];
        foreach ($decoded as $oldslideid => $optionids) {
            $newslideid = $this->get_mappingid('whakaharatau_slide', (int) $oldslideid);
            if (!$newslideid || !is_array($optionids)) {
                continue;
            }
            $ids = [];
            foreach ($optionids as $oldoptionid) {
                $newoptionid = $this->get_mappingid('whakaharatau_option', (int) $oldoptionid);
                if ($newoptionid) {
                    $ids[] = (int) $newoptionid;
                }
            }
            $new[(string) $newslideid] = $ids;
        }
        return json_encode((object) $new);
    }

    /**
     * Restore the files once every element is in place.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_whakaharatau', 'intro', null);
        $this->add_related_files('mod_whakaharatau', 'cover', null);
        $this->add_related_files('mod_whakaharatau', 'slideimage', 'whakaharatau_slide');
        $this->add_related_files('mod_whakaharatau', 'slideprompt', 'whakaharatau_slide');
        $this->add_related_files('mod_whakaharatau', 'optionaudio', 'whakaharatau_option');
    }
}
