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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_whakaharatau\local\importer;
use mod_whakaharatau\local\slides;

/**
 * Import step 1: upload one ZIP or many images and audio files, and choose append or replace.
 *
 * Custom data: `cmid` (int), `maxbytes` (int, largest single file), `slidecount` (int, existing slides).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /** @var string Import mode: add the new slides after the existing ones. */
    const MODE_APPEND = 'append';
    /** @var string Import mode: delete the existing slides first. */
    const MODE_REPLACE = 'replace';

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        global $CFG;
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', (int) $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('static', 'importintro', '', format_text(get_string('importintro', 'mod_whakaharatau', (object) [
            'maxfiles' => importer::MAX_FILES,
            'maxslides' => importer::MAX_SLIDES,
            'maxoptions' => slides::MAX_OPTIONS,
        ]), FORMAT_MARKDOWN));

        $types = array_merge(['.zip', '.csv', '.jpeg'], array_map(
            fn($e) => '.' . $e,
            array_merge(slides::IMAGE_TYPES, slides::AUDIO_TYPES, ['oga'])
        ));
        // The FILE_* return types are defined in repository/lib.php.
        require_once($CFG->dirroot . '/repository/lib.php');
        $mform->addElement('filemanager', 'importfiles', get_string('importfiles', 'mod_whakaharatau'), null, [
            'subdirs' => 0,
            'maxfiles' => importer::MAX_FILES,
            'maxbytes' => (int) ($this->_customdata['maxbytes'] ?? 0),
            'accepted_types' => $types,
            'return_types' => FILE_INTERNAL,
        ]);
        $mform->addRule('importfiles', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('importfiles', 'importfiles', 'mod_whakaharatau');

        $choices = [
            self::MODE_APPEND => get_string('importappend', 'mod_whakaharatau'),
            self::MODE_REPLACE => get_string(
                'importreplace',
                'mod_whakaharatau',
                (int) ($this->_customdata['slidecount'] ?? 0)
            ),
        ];
        $radios = [];
        foreach ($choices as $value => $label) {
            $radios[] = $mform->createElement('radio', 'importmode', '', $label, $value);
        }
        $mform->addGroup($radios, 'importmodegroup', get_string('importmode', 'mod_whakaharatau'), ['<br>'], false);
        $mform->setType('importmode', PARAM_ALPHA);
        $mform->setDefault('importmode', self::MODE_APPEND);
        $mform->addHelpButton('importmodegroup', 'importmode', 'mod_whakaharatau');

        $this->add_action_buttons(true, get_string('importreview', 'mod_whakaharatau'));
    }

    /**
     * Validation: at least one file, and a known mode.
     *
     * @param array $data submitted data
     * @param array $files not used
     * @return array errors
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        $usercontext = \context_user::instance($USER->id);
        $draft = get_file_storage()->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            (int) ($data['importfiles'] ?? 0),
            'id',
            false
        );
        if (!$draft) {
            $errors['importfiles'] = get_string('required');
        }
        if (!in_array($data['importmode'] ?? '', [self::MODE_APPEND, self::MODE_REPLACE], true)) {
            $errors['importmodegroup'] = get_string('required');
        }
        return $errors;
    }
}
