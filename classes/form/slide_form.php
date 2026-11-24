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

use context_module;
use context_user;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;
use mod_whakaharatau\local\versions;
use moodle_exception;
use stdClass;

/**
 * Add or edit one slide: image, alt text or decorative, prompt (text, language, audio) and 2-6 options.
 *
 * Option slots are numbered fields (`optionid_N`, `audio_N`, `transcript_N`); the correct one is the
 * radio group `correct` (value = slot index), so exactly one option is correct. Deleting a slot's audio
 * removes that option when the form is saved. "Add another option" is a no-submit button that re-displays
 * the form with one more slot.
 *
 * Custom data: `cmid` (int), `slide` (stdClass|null, the slide being edited), `optioncount` (int, slots to
 * show at first), `langs` (array code => name, extra prompt languages to offer).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slide_form extends \moodleform {
    /** @var string[] Prompt languages always offered (code => lang key in this plugin). */
    const PROMPT_LANGS = ['mi' => 'promptlang_mi', 'en' => 'promptlang_en'];

    /** @var int Number of option slots shown. */
    protected $slots = 0;

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $slide = $this->_customdata['slide'] ?? null;

        // Slot count: the initial value, or the posted one (+1 when "Add another option" was pressed).
        // The button is read as PARAM_RAW: PARAM_INT would clean its label to 0.
        $count = optional_param('optioncount', (int) ($this->_customdata['optioncount'] ?? 4), PARAM_INT);
        if (optional_param('addoption', '', PARAM_RAW) !== '') {
            $count++;
        }
        $this->slots = max(slides::MIN_OPTIONS, min(slides::MAX_OPTIONS, $count));

        $mform->addElement('hidden', 'id', (int) $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'slideid', $slide ? (int) $slide->id : 0);
        $mform->setType('slideid', PARAM_INT);
        $mform->addElement('hidden', 'optioncount');
        $mform->setType('optioncount', PARAM_INT);
        $mform->setConstant('optioncount', $this->slots);

        // Picture.
        $mform->addElement('header', 'picturehdr', get_string('slidepicture', 'mod_whakaharatau'));
        $mform->setExpanded('picturehdr', true);
        $mform->addElement(
            'filemanager',
            'image',
            get_string('slideimage', 'mod_whakaharatau'),
            null,
            slides::filemanager_options(slides::AREA_IMAGE)
        );
        $mform->addRule('image', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('image', 'slideimage', 'mod_whakaharatau');

        $mform->addElement('text', 'alttext', get_string('alttext', 'mod_whakaharatau'), ['size' => 60]);
        $mform->setType('alttext', PARAM_TEXT);
        $mform->addHelpButton('alttext', 'alttext', 'mod_whakaharatau');
        $mform->addElement(
            'advcheckbox',
            'decorative',
            get_string('decorative', 'mod_whakaharatau'),
            get_string('decorative_label', 'mod_whakaharatau')
        );
        $mform->addHelpButton('decorative', 'decorative', 'mod_whakaharatau');
        $mform->hideIf('alttext', 'decorative', 'checked');
        $mform->addElement(
            'advcheckbox',
            'fade',
            get_string('slidefade', 'mod_whakaharatau'),
            get_string('slidefade_label', 'mod_whakaharatau')
        );
        $mform->addHelpButton('fade', 'slidefade', 'mod_whakaharatau');
        fade_slider::add_to_form($mform, 'fadewidth');
        $mform->hideIf('fadewidth', 'fade', 'notchecked');
        foreach (['fade', 'fadewidth'] as $name) {
            if (instance_settings::is_locked($name)) {
                $mform->hardFreeze($name);
            }
        }

        // Prompt.
        $mform->addElement('header', 'prompthdr', get_string('slideprompt', 'mod_whakaharatau'));
        $mform->setExpanded('prompthdr', !empty($slide->prompt));
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'mod_whakaharatau'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addHelpButton('prompt', 'prompt', 'mod_whakaharatau');
        $mform->addElement(
            'select',
            'promptlang',
            get_string('promptlang', 'mod_whakaharatau'),
            $this->get_lang_choices()
        );
        $mform->setType('promptlang', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('promptlang', 'promptlang', 'mod_whakaharatau');
        $mform->addElement(
            'filemanager',
            'promptaudio',
            get_string('promptaudio', 'mod_whakaharatau'),
            null,
            slides::filemanager_options(slides::AREA_PROMPT)
        );
        $mform->addHelpButton('promptaudio', 'promptaudio', 'mod_whakaharatau');

        // Options.
        for ($i = 0; $i < $this->slots; $i++) {
            $n = $i + 1;
            $mform->addElement('header', 'optionhdr_' . $i, get_string('optionn', 'mod_whakaharatau', $n));
            $mform->setExpanded('optionhdr_' . $i, true);
            $mform->addElement('hidden', 'optionid_' . $i, 0);
            $mform->setType('optionid_' . $i, PARAM_INT);
            $mform->addElement(
                'filemanager',
                'audio_' . $i,
                get_string('optionaudion', 'mod_whakaharatau', $n),
                null,
                slides::filemanager_options(slides::AREA_AUDIO)
            );
            $mform->addHelpButton('audio_' . $i, 'optionaudio', 'mod_whakaharatau');
            $mform->addElement(
                'text',
                'transcript_' . $i,
                get_string('transcriptn', 'mod_whakaharatau', $n),
                ['size' => 60]
            );
            $mform->setType('transcript_' . $i, PARAM_TEXT);
            $mform->addHelpButton('transcript_' . $i, 'transcript', 'mod_whakaharatau');
        }
        if ($this->slots < slides::MAX_OPTIONS) {
            $mform->addElement('submit', 'addoption', get_string('addoption', 'mod_whakaharatau'));
            $mform->registerNoSubmitButton('addoption');
        }

        // Correct option: one radio group, so exactly one is chosen.
        $mform->addElement('header', 'correcthdr', get_string('correctoption', 'mod_whakaharatau'));
        $mform->setExpanded('correcthdr', true);
        $radios = [];
        for ($i = 0; $i < $this->slots; $i++) {
            $radios[] = $mform->createElement(
                'radio',
                'correct',
                '',
                get_string('optionn', 'mod_whakaharatau', $i + 1),
                $i
            );
        }
        $mform->addGroup($radios, 'correctgroup', get_string('correctoption', 'mod_whakaharatau'), [' '], false);
        $mform->setType('correct', PARAM_INT);
        $mform->addHelpButton('correctgroup', 'correctoption', 'mod_whakaharatau');

        $this->add_action_buttons(true, get_string('saveslide', 'mod_whakaharatau'));
    }

    /**
     * Number of option slots shown.
     *
     * @return int
     */
    public function get_slot_count(): int {
        return $this->slots;
    }

    /**
     * Prompt language choices: none, the built-in ones, and any extra passed in (e.g. a stored value).
     *
     * @return array code => label (plain text)
     */
    protected function get_lang_choices(): array {
        $choices = ['' => get_string('promptlang_none', 'mod_whakaharatau')];
        foreach (self::PROMPT_LANGS as $code => $key) {
            $choices[$code] = get_string($key, 'mod_whakaharatau');
        }
        foreach ($this->_customdata['langs'] ?? [] as $code => $label) {
            if (!isset($choices[$code])) {
                $choices[$code] = $label;
            }
        }
        return $choices;
    }

    /**
     * Validation: image present and accepted, alt text unless decorative, at least two options with audio,
     * every file of an accepted type (by content), and the correct option has audio.
     *
     * @param array $data submitted data
     * @param array $files not used (files are read from the draft areas)
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $imageerror = self::check_draft((int) ($data['image'] ?? 0), slides::AREA_IMAGE, true);
        if ($imageerror) {
            $errors['image'] = $imageerror;
        }
        if (empty($data['decorative']) && trim((string) ($data['alttext'] ?? '')) === '') {
            $errors['alttext'] = get_string('alttextrequired', 'mod_whakaharatau');
        }
        if (isset($data['fadewidth']) && ($error = fade_slider::validate($data['fadewidth']))) {
            $errors['fadewidth'] = $error;
        }
        if ($error = self::check_draft((int) ($data['promptaudio'] ?? 0), slides::AREA_PROMPT, false)) {
            $errors['promptaudio'] = $error;
        }

        $withaudio = [];
        $slots = max(slides::MIN_OPTIONS, min(slides::MAX_OPTIONS, (int) ($data['optioncount'] ?? 0)));
        for ($i = 0; $i < $slots; $i++) {
            $draftid = (int) ($data['audio_' . $i] ?? 0);
            if ($error = self::check_draft($draftid, slides::AREA_AUDIO, false)) {
                $errors['audio_' . $i] = $error;
                $withaudio[] = $i;
            } else if (self::draft_has_file($draftid)) {
                $withaudio[] = $i;
            }
        }
        if (count($withaudio) < slides::MIN_OPTIONS) {
            $errors['audio_' . (count($withaudio) ? 1 : 0)] = get_string(
                'needtwooptions',
                'mod_whakaharatau',
                slides::MIN_OPTIONS
            );
        }
        if (!isset($data['correct']) || !in_array((int) $data['correct'], $withaudio, true)) {
            $errors['correctgroup'] = get_string('correctrequired', 'mod_whakaharatau');
        }
        return $errors;
    }

    /**
     * Check a file manager's draft area: at most one file, at "/", of a type accepted for the area by content.
     *
     * @param int $draftid draft item id of the current user
     * @param string $filearea slides::AREA_* the file is for
     * @param bool $required whether an empty area is an error
     * @return string|null error message, or null when fine
     */
    public static function check_draft(int $draftid, string $filearea, bool $required): ?string {
        $files = self::files_in_draft($draftid);
        if (!$files) {
            return $required ? get_string('required') : null;
        }
        if (count($files) > 1 || $files[0]->get_filepath() !== '/') {
            return get_string('invalidmediafile', 'mod_whakaharatau');
        }
        $handle = $files[0]->get_content_file_handle();
        $head = $handle ? (string) fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }
        if (!slides::type_allowed($filearea, slides::sniff_media_type($head))) {
            return get_string('invalidmediafile', 'mod_whakaharatau');
        }
        return null;
    }

    /**
     * Whether a draft area of the current user holds a file.
     *
     * @param int $draftid draft item id
     * @return bool
     */
    public static function draft_has_file(int $draftid): bool {
        return (bool) self::files_in_draft($draftid);
    }

    /**
     * Files (not folders) of a draft area of the current user.
     *
     * @param int $draftid draft item id
     * @return \stored_file[] list
     */
    protected static function files_in_draft(int $draftid): array {
        global $USER;
        if ($draftid <= 0) {
            return [];
        }
        $usercontext = context_user::instance($USER->id);
        return array_values(get_file_storage()->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftid,
            'id',
            false
        ));
    }

    /**
     * Form data for editing an existing slide (or a new one when $slide is null).
     *
     * @param context_module $context module context
     * @param int $cmid course module id
     * @param stdClass|null $slide slide record, or null for a new slide
     * @param stdClass|null $instance whakaharatau record, for a new slide's default fade
     * @return stdClass data for set_data()
     */
    public static function prepare_data(
        context_module $context,
        int $cmid,
        ?stdClass $slide,
        ?stdClass $instance = null
    ): stdClass {
        $defaults = instance_settings::get_defaults();
        $data = (object) [
            'id' => $cmid,
            'action' => 'edit',
            'slideid' => 0,
            'alttext' => '',
            'decorative' => 0,
            'fade' => (int) ($instance->fade ?? $defaults['fade']),
            'fadewidth' => (int) ($instance->fadewidth ?? $defaults['fadewidth']),
            'prompt' => '',
            'promptlang' => '',
        ];
        if (!$slide) {
            return $data;
        }
        $data->slideid = (int) $slide->id;
        $data->alttext = (string) $slide->alttext;
        $data->decorative = (int) $slide->decorative;
        $data->fade = (int) $slide->fade;
        $data->fadewidth = (int) $slide->fadewidth;
        $data->prompt = (string) $slide->prompt;
        $data->promptlang = (string) ($slide->promptlang ?? '');
        $data->image = slides::prepare_draft($context, slides::AREA_IMAGE, (int) $slide->id);
        $data->promptaudio = slides::prepare_draft($context, slides::AREA_PROMPT, (int) $slide->id);
        $i = 0;
        foreach (slides::get_options((int) $slide->id) as $option) {
            $data->{'optionid_' . $i} = (int) $option->id;
            $data->{'audio_' . $i} = slides::prepare_draft($context, slides::AREA_AUDIO, (int) $option->id);
            $data->{'transcript_' . $i} = (string) $option->transcript;
            if (!empty($option->iscorrect)) {
                $data->correct = $i;
            }
            $i++;
        }
        return $data;
    }

    /**
     * Save a submitted slide: create or update it, its files and its options.
     *
     * The caller has checked mod/whakaharatau:manage and that the form validated (which checks the session key).
     * Option ids posted back are trusted only when they belong to this slide.
     *
     * @param context_module $context module context
     * @param stdClass $instance whakaharatau record
     * @param stdClass $data get_data() result
     * @return int slide id
     */
    public static function save(context_module $context, stdClass $instance, stdClass $data): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();

        $fields = (object) [
            'alttext' => trim((string) ($data->alttext ?? '')),
            'decorative' => empty($data->decorative) ? 0 : 1,
            'prompt' => trim((string) ($data->prompt ?? '')),
            'promptformat' => FORMAT_PLAIN,
            'promptlang' => (string) ($data->promptlang ?? ''),
        ];
        // Left out when the site locks it: a new slide takes the activity's default, an old one keeps its own.
        foreach (['fade', 'fadewidth'] as $name) {
            if (isset($data->$name) && !instance_settings::is_locked($name)) {
                $fields->$name = (int) $data->$name;
            }
        }
        $slideid = (int) ($data->slideid ?? 0);
        if ($slideid) {
            $slide = slides::get_slide($slideid);
            if ((int) $slide->whakaharatauid !== (int) $instance->id) {
                throw new moodle_exception('errorslidenotinactivity', 'mod_whakaharatau');
            }
            $fields->id = $slideid;
            versions::ensure($context, $slideid);
            slides::update_slide($fields);
        } else {
            $slideid = slides::create_slide((int) $instance->id, $fields);
        }
        slides::save_file_from_draft($context, slides::AREA_IMAGE, $slideid, (int) ($data->image ?? 0));
        slides::save_file_from_draft($context, slides::AREA_PROMPT, $slideid, (int) ($data->promptaudio ?? 0));

        $existing = slides::get_options($slideid);
        $slots = max(slides::MIN_OPTIONS, min(slides::MAX_OPTIONS, (int) ($data->optioncount ?? 0)));
        $correct = isset($data->correct) ? (int) $data->correct : -1;
        $kept = [];
        $correctid = null;
        $sortorder = 0;
        for ($i = 0; $i < $slots; $i++) {
            $draftid = (int) ($data->{'audio_' . $i} ?? 0);
            $optionid = (int) ($data->{'optionid_' . $i} ?? 0);
            if ($optionid && (!isset($existing[$optionid]) || isset($kept[$optionid]))) {
                $optionid = 0;
            }
            if (!self::draft_has_file($draftid)) {
                // An emptied slot removes its option (deleted below, as it is not kept).
                continue;
            }
            $transcript = trim((string) ($data->{'transcript_' . $i} ?? ''));
            if ($optionid) {
                slides::update_option((object) ['id' => $optionid, 'transcript' => $transcript, 'sortorder' => $sortorder]);
            } else {
                $optionid = slides::create_option($slideid, (object) ['transcript' => $transcript, 'iscorrect' => 0,
                    'sortorder' => $sortorder]);
            }
            slides::save_file_from_draft($context, slides::AREA_AUDIO, $optionid, $draftid);
            $kept[$optionid] = true;
            if ($i === $correct) {
                $correctid = $optionid;
            }
            $sortorder++;
        }
        foreach ($existing as $option) {
            if (!isset($kept[(int) $option->id])) {
                slides::delete_option($context, (int) $option->id);
            }
        }
        if ($correctid) {
            slides::set_correct_option($slideid, $correctid);
        } else {
            $DB->set_field('whakaharatau_option', 'iscorrect', 0, ['slideid' => $slideid]);
        }

        versions::sync($context, $slideid);
        $transaction->allow_commit();
        return $slideid;
    }
}
