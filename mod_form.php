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
 * Activity settings form for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_whakaharatau\form\fade_slider;
use mod_whakaharatau\local\instance_settings as settings;
use mod_whakaharatau\local\slides;

/**
 * Activity settings form.
 *
 * Element names match the admin default names in settings.php, so apply_admin_defaults() applies the
 * site defaults and their Advanced and Locked flags.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_whakaharatau_mod_form extends moodleform_mod {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $practice = settings::MODE_PRACTICE;
        $test = settings::MODE_TEST;

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Mode and play order.
        $mform->addElement('header', 'playheading', get_string('playheading', 'mod_whakaharatau'));
        $mform->setExpanded('playheading');

        $mform->addElement('select', 'mode', get_string('mode', 'mod_whakaharatau'), settings::get_mode_choices());
        $mform->addHelpButton('mode', 'mode', 'mod_whakaharatau');

        if (!empty($this->current->instance) && isset($this->current->mode) && (int) $this->current->mode === $test) {
            $mform->addElement(
                'static',
                'modeswitchwarning',
                '',
                html_writer::div(get_string('modeswitchwarning', 'mod_whakaharatau'), 'alert alert-warning')
            );
            $mform->hideIf('modeswitchwarning', 'mode', 'eq', $test);
        }

        $mform->addElement('select', 'shuffle', get_string('shuffle', 'mod_whakaharatau'), settings::get_yesno_choices());
        $mform->addHelpButton('shuffle', 'shuffle', 'mod_whakaharatau');

        $mform->addElement(
            'select',
            'repeatmode',
            get_string('repeatmode', 'mod_whakaharatau'),
            settings::get_repeat_choices()
        );
        $mform->addHelpButton('repeatmode', 'repeatmode', 'mod_whakaharatau');
        $mform->hideIf('repeatmode', 'mode', 'eq', $test);

        $mform->addElement(
            'select',
            'optionorder',
            get_string('optionorder', 'mod_whakaharatau'),
            settings::get_optionorder_choices()
        );
        $mform->addHelpButton('optionorder', 'optionorder', 'mod_whakaharatau');

        $mform->addElement(
            'select',
            'practicetries',
            get_string('triesperslide', 'mod_whakaharatau'),
            settings::get_tries_choices()
        );
        $mform->addHelpButton('practicetries', 'triesperslide', 'mod_whakaharatau');
        $mform->hideIf('practicetries', 'mode', 'eq', $test);

        $mform->addElement(
            'select',
            'testtries',
            get_string('triesperslide', 'mod_whakaharatau'),
            settings::get_tries_choices()
        );
        $mform->addHelpButton('testtries', 'triesperslide', 'mod_whakaharatau');
        $mform->hideIf('testtries', 'mode', 'eq', $practice);
        $mform->hideIf('testtries', 'feedbackmode', 'eq', settings::FEEDBACK_END);

        // Display.
        $mform->addElement('header', 'displayheading', get_string('displayheading', 'mod_whakaharatau'));

        $mform->addElement(
            'select',
            'displayinline',
            get_string('displayinline', 'mod_whakaharatau'),
            settings::get_display_choices()
        );
        $mform->addHelpButton('displayinline', 'displayinline', 'mod_whakaharatau');

        $mform->addElement(
            'select',
            'showprogress',
            get_string('showprogress', 'mod_whakaharatau'),
            settings::get_yesno_choices()
        );
        $mform->addHelpButton('showprogress', 'showprogress', 'mod_whakaharatau');

        $mform->addElement(
            'select',
            'autoplay',
            get_string('autoplay', 'mod_whakaharatau'),
            settings::get_yesno_choices()
        );
        $mform->addHelpButton('autoplay', 'autoplay', 'mod_whakaharatau');

        $mform->addElement(
            'select',
            'transcriptsbefore',
            get_string('transcriptsbefore', 'mod_whakaharatau'),
            settings::get_yesno_choices()
        );
        $mform->addHelpButton('transcriptsbefore', 'transcriptsbefore', 'mod_whakaharatau');

        $mform->addElement('select', 'fade', get_string('fade', 'mod_whakaharatau'), settings::get_yesno_choices());
        $mform->addHelpButton('fade', 'fade', 'mod_whakaharatau');
        fade_slider::add_to_form($mform, 'fadewidth');
        $mform->hideIf('fadewidth', 'fade', 'eq', 0);

        $mform->addElement(
            'filemanager',
            'cover',
            get_string('cover', 'mod_whakaharatau'),
            null,
            slides::filemanager_options(slides::AREA_COVER)
        );
        $mform->addHelpButton('cover', 'cover', 'mod_whakaharatau');

        $mform->addElement('select', 'coverfade', get_string('coverfade', 'mod_whakaharatau'), settings::get_yesno_choices());
        $mform->addHelpButton('coverfade', 'coverfade', 'mod_whakaharatau');
        fade_slider::add_to_form($mform, 'coverfadewidth');
        $mform->hideIf('coverfadewidth', 'coverfade', 'eq', 0);

        // Test scoring.
        $mform->addElement('header', 'scoringheading', get_string('scoringheading', 'mod_whakaharatau'));

        $mform->addElement('static', 'testonlynote', '', get_string('testonlynote', 'mod_whakaharatau'));
        $mform->hideIf('testonlynote', 'mode', 'eq', $test);

        $mform->addElement(
            'select',
            'feedbackmode',
            get_string('feedbackmode', 'mod_whakaharatau'),
            settings::get_feedback_choices()
        );
        $mform->addHelpButton('feedbackmode', 'feedbackmode', 'mod_whakaharatau');
        $mform->hideIf('feedbackmode', 'mode', 'eq', $practice);

        $mform->addElement(
            'select',
            'scoring',
            get_string('scoring', 'mod_whakaharatau'),
            settings::get_scoring_choices()
        );
        $mform->addHelpButton('scoring', 'scoring', 'mod_whakaharatau');
        $mform->hideIf('scoring', 'mode', 'eq', $practice);

        $mform->addElement(
            'select',
            'slidescore',
            get_string('slidescore', 'mod_whakaharatau'),
            settings::get_slidescore_choices()
        );
        $mform->addHelpButton('slidescore', 'slidescore', 'mod_whakaharatau');
        $mform->hideIf('slidescore', 'mode', 'eq', $practice);
        $mform->hideIf('slidescore', 'scoring', 'eq', settings::SCORING_COMPLETED);

        $mform->addElement(
            'select',
            'attempts',
            get_string('attemptsallowed', 'mod_whakaharatau'),
            settings::get_attempts_choices()
        );
        $mform->addHelpButton('attempts', 'attemptsallowed', 'mod_whakaharatau');
        $mform->hideIf('attempts', 'mode', 'eq', $practice);

        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_whakaharatau'),
            settings::get_grademethod_choices()
        );
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_whakaharatau');
        $mform->hideIf('grademethod', 'mode', 'eq', $practice);
        $mform->hideIf('grademethod', 'attempts', 'eq', 1);

        $mform->addElement(
            'select',
            'allowreview',
            get_string('allowreview', 'mod_whakaharatau'),
            settings::get_yesno_choices()
        );
        $mform->addHelpButton('allowreview', 'allowreview', 'mod_whakaharatau');
        $mform->hideIf('allowreview', 'mode', 'eq', $practice);

        // Grade (Test only). In Practice mode the grade element is hidden; lib.php keeps the stored value.
        $this->standard_grading_coursemodule_elements();
        foreach (['grade', 'gradecat', 'gradepass'] as $element) {
            if ($mform->elementExists($element)) {
                $mform->hideIf($element, 'mode', 'eq', $practice);
            }
        }
        // So the Grade section is not an empty heading in Practice mode (as in Test scoring above).
        $mform->addElement('static', 'gradetestonlynote', '', get_string('testonlynote', 'mod_whakaharatau'));
        $mform->hideIf('gradetestonlynote', 'mode', 'eq', $test);

        $this->standard_coursemodule_elements();
        $this->apply_admin_defaults();
        $this->add_action_buttons();
    }

    /**
     * Prepare the cover draft area and split triesperslide into the per-mode elements.
     *
     * @param array $defaultvalues form defaults (changed in place)
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        $context = !empty($this->current->instance) && $this->context instanceof context_module ? $this->context : null;
        $defaultvalues['cover'] = slides::prepare_draft($context, slides::AREA_COVER, 0);

        if (!empty($this->current->instance) && isset($defaultvalues['triesperslide'])) {
            $defaults = settings::get_defaults();
            $istest = (int) ($defaultvalues['mode'] ?? 0) === settings::MODE_TEST;
            $defaultvalues['practicetries'] = $istest ? $defaults['practicetries'] : (int) $defaultvalues['triesperslide'];
            $defaultvalues['testtries'] = $istest ? (int) $defaultvalues['triesperslide'] : $defaults['testtries'];
        }
    }

    /**
     * Keep the custom completion rule off when completion is unlocked and the box is cleared.
     *
     * @param stdClass $data submitted data (changed in place)
     * @return void
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            if (empty($data->{'completionallslides' . $suffix})) {
                $data->{'completionallslides' . $suffix} = 0;
            }
        }
    }

    /**
     * Validation: no scales in Test mode, and the cover must be an accepted image (checked by content).
     *
     * @param array $data submitted data
     * @param array $files uploaded files
     * @return array errors
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        if ((int) ($data['mode'] ?? 0) === settings::MODE_TEST && isset($data['grade']) && (int) $data['grade'] < 0) {
            $errors['grade'] = get_string('errorscalenotsupported', 'mod_whakaharatau');
        }

        foreach (['fadewidth', 'coverfadewidth'] as $name) {
            if (isset($data[$name]) && ($error = fade_slider::validate($data[$name]))) {
                $errors[$name] = $error;
            }
        }

        // Practice mode has no grade item, so a grade completion condition could never be met.
        $suffix = $this->get_suffix();
        if ((int) ($data['mode'] ?? 0) !== settings::MODE_TEST && !empty($data['completionusegrade' . $suffix])) {
            $errors['completionusegrade' . $suffix] = get_string('errorpracticegradecompletion', 'mod_whakaharatau');
        }

        if (!empty($data['cover'])) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $drafts = $fs->get_area_files($usercontext->id, 'user', 'draft', (int) $data['cover'], 'id', false);
            foreach ($drafts as $file) {
                $handle = $file->get_content_file_handle();
                $head = $handle ? (string) fread($handle, 16) : '';
                if ($handle) {
                    fclose($handle);
                }
                if (
                    $file->get_filepath() !== '/' ||
                        !slides::type_allowed(slides::AREA_COVER, slides::sniff_media_type($head))
                ) {
                    $errors['cover'] = get_string('invalidmediafile', 'mod_whakaharatau');
                }
            }
        }
        return $errors;
    }

    /**
     * Custom completion rule: every slide answered at least once.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $element = 'completionallslides' . $suffix;
        $mform->addElement('checkbox', $element, '', get_string('completionallslides', 'mod_whakaharatau'));
        $mform->addHelpButton($element, 'completionallslides', 'mod_whakaharatau');
        return [$element];
    }

    /**
     * Whether the custom completion rule is enabled.
     *
     * @param array $data submitted data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionallslides' . $this->get_suffix()]);
    }
}
