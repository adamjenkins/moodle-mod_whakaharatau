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
 * Library of interface functions for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;

/**
 * Which Moodle features the module supports.
 *
 * Purpose: MOD_PURPOSE_INTERACTIVECONTENT, because the default (Practice) mode is ungraded listening
 * practice, like H5P content; the graded Test mode is covered by the secondary purpose ASSESSMENT.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed true/false/null or a purpose constant
 */
function whakaharatau_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_GROUPS => true,
        FEATURE_GROUPINGS => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_INTERACTIVECONTENT,
        FEATURE_MOD_OTHERPURPOSE => MOD_PURPOSE_ASSESSMENT,
        default => null,
    };
}

/**
 * Bring submitted form data into the shape of the whakaharatau table.
 *
 * The form has two tries elements (practicetries, testtries) so each mode has its own default; only the
 * one for the chosen mode is stored in triesperslide.
 *
 * @param stdClass $data form data (changed in place)
 * @param stdClass|null $current the existing record when updating
 * @return void
 */
function whakaharatau_normalise_instance_data(stdClass $data, ?stdClass $current = null): void {
    $defaults = instance_settings::get_defaults();
    $data->mode = (int) ($data->mode ?? $current->mode ?? $defaults['mode']);
    $istest = $data->mode === instance_settings::MODE_TEST;
    if ($istest && isset($data->testtries)) {
        $data->triesperslide = (int) $data->testtries;
    } else if (!$istest && isset($data->practicetries)) {
        $data->triesperslide = (int) $data->practicetries;
    } else if (!isset($data->triesperslide)) {
        $data->triesperslide = $current->triesperslide ?? $defaults[$istest ? 'testtries' : 'practicetries'];
    }
    // A field may be missing (frozen, locked by a site setting, or set by a web service or generator): keep the
    // stored value, else the site default. (Elements hidden by hideIf are still posted.)
    foreach (
        ['shuffle', 'repeatmode', 'optionorder', 'showprogress', 'displayinline', 'feedbackmode', 'slidescore',
            'attempts', 'grademethod', 'allowreview', 'scoring', 'autoplay', 'transcriptsbefore',
            'fade', 'fadewidth', 'coverfade', 'coverfadewidth'] as $field
    ) {
        if (!isset($data->$field)) {
            $data->$field = $current->$field ?? $defaults[$field];
        }
        $data->$field = (int) $data->$field;
    }
    foreach (['fade', 'coverfade'] as $field) {
        $data->$field = $data->$field ? 1 : 0;
        $data->{$field . 'width'} = instance_settings::clamp_fade($data->{$field . 'width'});
    }
    // In Practice mode the form hides (and so disables) the grade element, which then exports 0:
    // keep the stored maximum grade so switching back to Test restores it.
    if (!isset($data->grade) || !$istest) {
        $data->grade = $current->grade ?? 100;
    }
    $data->grade = max(0, (int) $data->grade);
    $data->completionallslides = empty($data->completionallslides) ? 0 : 1;
    if ($istest) {
        $data->repeatmode = instance_settings::REPEAT_OFF;
    }
}

/**
 * Save the start-screen cover image from the form's draft area.
 *
 * @param stdClass $data form data with coursemodule and cover (draft item id)
 * @return void
 */
function whakaharatau_save_cover(stdClass $data): void {
    if (!isset($data->cover) || empty($data->coursemodule)) {
        return;
    }
    $context = context_module::instance($data->coursemodule);
    try {
        slides::save_file_from_draft($context, slides::AREA_COVER, 0, (int) $data->cover);
    } catch (moodle_exception $e) {
        // Not an accepted image: the form's validation should have stopped it; leave no file.
        get_file_storage()->delete_area_files($context->id, slides::COMPONENT, slides::AREA_COVER, 0);
    }
}

/**
 * Add a new instance.
 *
 * @param stdClass $data form data
 * @param mod_whakaharatau_mod_form|null $mform the form
 * @return int new instance id
 */
function whakaharatau_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    whakaharatau_normalise_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('whakaharatau', $data);
    whakaharatau_save_cover($data);
    whakaharatau_grade_item_update($data);
    if (!empty($data->completionexpected)) {
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'whakaharatau',
            $data->id,
            $data->completionexpected
        );
    }
    return (int) $data->id;
}

/**
 * Update an instance. Switching to Practice deletes the grade item (the form warns first);
 * switching to Test creates it and pushes grades from earlier Test attempts.
 *
 * @param stdClass $data form data
 * @param mod_whakaharatau_mod_form|null $mform the form
 * @return bool
 */
function whakaharatau_update_instance(stdClass $data, $mform = null): bool {
    global $DB;
    $data->id = $data->instance;
    $current = $DB->get_record('whakaharatau', ['id' => $data->id], '*', MUST_EXIST);
    whakaharatau_normalise_instance_data($data, $current);
    $data->timemodified = time();
    $DB->update_record('whakaharatau', $data);
    whakaharatau_save_cover($data);

    $instance = $DB->get_record('whakaharatau', ['id' => $data->id], '*', MUST_EXIST);
    $instance->cmidnumber = $data->cmidnumber ?? null;
    if (instance_settings::is_test($instance)) {
        whakaharatau_grade_item_update($instance);
        whakaharatau_update_grades($instance, 0, false);
    } else {
        whakaharatau_grade_item_delete($instance);
    }
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'whakaharatau',
        $data->id,
        !empty($data->completionexpected) ? $data->completionexpected : null
    );
    return true;
}

/**
 * Delete an instance with its slides, options, attempts, responses, files and grade item.
 *
 * @param int $id instance id
 * @return bool
 */
function whakaharatau_delete_instance($id): bool {
    global $DB;
    $instance = $DB->get_record('whakaharatau', ['id' => $id]);
    if (!$instance) {
        return false;
    }
    $cm = get_coursemodule_from_instance('whakaharatau', $id, 0, false, IGNORE_MISSING);
    $context = $cm ? context_module::instance($cm->id, IGNORE_MISSING) : null;

    attempt::delete_all_attempts((int) $id);
    slides::delete_all_slides($context ?: null, (int) $id);
    if ($context) {
        get_file_storage()->delete_area_files($context->id, slides::COMPONENT, slides::AREA_COVER);
    }
    whakaharatau_grade_item_delete($instance);
    if ($cm) {
        \core_completion\api::update_completion_date_event($cm->id, 'whakaharatau', $id, null);
    }
    $DB->delete_records('whakaharatau', ['id' => $id]);
    return true;
}

/**
 * Create or update the grade item. In Practice mode there is no grade item: it is deleted instead.
 *
 * @param stdClass $instance whakaharatau record (cmidnumber optional)
 * @param array|stdClass|string|null $grades grade objects, 'reset' to reset, or null
 * @return int GRADE_UPDATE_OK etc.
 */
function whakaharatau_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (!instance_settings::is_test($instance)) {
        return whakaharatau_grade_item_delete($instance);
    }
    $params = ['itemname' => $instance->name];
    if (isset($instance->cmidnumber)) {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ((int) $instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = (int) $instance->grade;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update(
        'mod/whakaharatau',
        $instance->course,
        'mod',
        'whakaharatau',
        $instance->id,
        0,
        $grades,
        $params
    );
}

/**
 * Delete the grade item.
 *
 * @param stdClass $instance whakaharatau record
 * @return int GRADE_UPDATE_OK etc.
 */
function whakaharatau_grade_item_delete(stdClass $instance): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/whakaharatau',
        $instance->course,
        'mod',
        'whakaharatau',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Users' grades from their finished Test attempts.
 *
 * @param stdClass $instance whakaharatau record
 * @param int $userid one user, or 0 for all
 * @return array userid => grade object (userid, rawgrade, dategraded)
 */
function whakaharatau_get_user_grades(stdClass $instance, int $userid = 0): array {
    return attempt::get_user_grades($instance, $userid);
}

/**
 * Push grades to the gradebook (Test mode only).
 *
 * @param stdClass $instance whakaharatau record
 * @param int $userid one user, or 0 for all
 * @param bool $nullifnone write a null grade when the user has none
 * @return void
 */
function whakaharatau_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (!instance_settings::is_test($instance)) {
        whakaharatau_grade_item_delete($instance);
        return;
    }
    if ((int) $instance->grade <= 0) {
        whakaharatau_grade_item_update($instance);
        return;
    }
    $grades = whakaharatau_get_user_grades($instance, $userid);
    if ($grades) {
        whakaharatau_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        whakaharatau_grade_item_update($instance, (object) ['userid' => $userid, 'rawgrade' => null]);
    } else {
        whakaharatau_grade_item_update($instance);
    }
}

/**
 * Whether a scale is used by an instance. Scales are not supported (the form refuses them).
 *
 * @param int $instanceid instance id
 * @param int $scaleid scale id
 * @return bool
 */
function whakaharatau_scale_used($instanceid, $scaleid): bool {
    return false;
}

/**
 * Whether a scale is used by any instance. Scales are not supported (the form refuses them).
 *
 * @param int $scaleid scale id
 * @return bool
 */
function whakaharatau_scale_used_anywhere($scaleid): bool {
    return false;
}

/**
 * Serve the plugin's files: slideimage, slideprompt, optionaudio, cover.
 *
 * URL: pluginfile.php/{contextid}/mod_whakaharatau/{area}/{itemid}/{revision}/{filename}; the revision
 * segment only defeats the browser cache and is ignored here. Byte ranges are served by send_stored_file()
 * (core readfile_accel), so audio can seek.
 *
 * @param stdClass $course course
 * @param stdClass $cm course module
 * @param context $context context
 * @param string $filearea file area
 * @param array $args remaining path arguments
 * @param bool $forcedownload whether to force download
 * @param array $options additional options
 * @return bool false when the file is not found
 */
function whakaharatau_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    if (
        !in_array(
            $filearea,
            [slides::AREA_IMAGE, slides::AREA_PROMPT, slides::AREA_AUDIO, slides::AREA_COVER],
            true
        )
    ) {
        return false;
    }
    require_course_login($course, true, $cm);
    require_capability('mod/whakaharatau:view', $context);

    if (count($args) < 2) {
        return false;
    }
    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $file = get_file_storage()->get_file($context->id, slides::COMPONENT, $filearea, $itemid, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Only media of the accepted types is shown inline; anything else is downloaded.
    $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
    $types = slides::area_kind($filearea) === 'image' ? slides::IMAGE_TYPES : slides::AUDIO_TYPES;
    if (!in_array($ext, $types, true)) {
        $forcedownload = true;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * File areas for the file browser.
 *
 * @param stdClass $course course
 * @param stdClass $cm course module
 * @param stdClass $context context
 * @return array area => name
 */
function whakaharatau_get_file_areas($course, $cm, $context): array {
    return [
        slides::AREA_IMAGE => get_string('area_slideimage', 'mod_whakaharatau'),
        slides::AREA_PROMPT => get_string('area_slideprompt', 'mod_whakaharatau'),
        slides::AREA_AUDIO => get_string('area_optionaudio', 'mod_whakaharatau'),
        slides::AREA_COVER => get_string('area_cover', 'mod_whakaharatau'),
    ];
}

/**
 * Cached course-module info: description, custom completion rule and the inline-display flag.
 *
 * @param stdClass $coursemodule course module record
 * @return cached_cm_info|false
 */
function whakaharatau_get_coursemodule_info($coursemodule) {
    global $DB;
    $instance = $DB->get_record(
        'whakaharatau',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, mode, displayinline, completionallslides'
    );
    if (!$instance) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('whakaharatau', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionallslides'] = (int) $instance->completionallslides;
    }
    $info->customdata['displayinline'] = (int) $instance->displayinline;
    $info->customdata['mode'] = (int) $instance->mode;
    return $info;
}

/**
 * Put the player inline on the course page when the teacher chose it.
 *
 * The renderer method \mod_whakaharatau\output\renderer::render_inline_player(cm_info) returns the
 * player's HTML (already formatted) and queues its JS. Not done in AJAX or web-service requests, where
 * queued JS would never run (the activity link remains).
 *
 * @param cm_info $cm course module
 * @return void
 */
function whakaharatau_cm_info_view(cm_info $cm): void {
    global $PAGE;
    if (empty($cm->customdata['displayinline']) || !$cm->uservisible) {
        return;
    }
    if ((defined('AJAX_SCRIPT') && AJAX_SCRIPT) || (defined('WS_SERVER') && WS_SERVER)) {
        return;
    }
    if (!has_capability('mod/whakaharatau:view', $cm->context)) {
        return;
    }
    $intro = $cm->showdescription ? $cm->get_formatted_content(['overflowdiv' => true, 'noclean' => true]) : '';
    /** @var \mod_whakaharatau\output\renderer $renderer */
    $renderer = $PAGE->get_renderer('mod_whakaharatau');
    $cm->set_content($intro . $renderer->render_inline_player($cm), true);
    $cm->set_extra_classes('whakaharatau-inline');
}

/**
 * Descriptions of the active custom completion rules (still read by core_completion\manager on 5.2/5.3).
 *
 * @param cm_info|stdClass $cm course module with customdata['customcompletionrules']
 * @return string[]
 */
function whakaharatau_get_completion_active_rule_descriptions($cm): array {
    if (empty($cm->customdata['customcompletionrules']) || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }
    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $value) {
        if ($key === 'completionallslides' && !empty($value)) {
            $descriptions[] = get_string('completionallslides_desc', 'mod_whakaharatau');
        }
    }
    return $descriptions;
}

/**
 * Add the "Delete all attempts" option to the course reset form.
 *
 * @param MoodleQuickForm $mform the reset form
 * @return void
 */
function whakaharatau_reset_course_form_definition(&$mform): void {
    $mform->addElement('header', 'whakaharatauheader', get_string('modulenameplural', 'mod_whakaharatau'));
    $mform->addElement(
        'advcheckbox',
        'reset_whakaharatau_attempts',
        get_string('resetattempts', 'mod_whakaharatau')
    );
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course course
 * @return array
 */
function whakaharatau_reset_course_form_defaults($course): array {
    return ['reset_whakaharatau_attempts' => 1];
}

/**
 * Reset the Test grades of every instance in a course.
 *
 * @param int $courseid course id
 * @param string $type unused
 * @return void
 */
function whakaharatau_reset_gradebook($courseid, $type = ''): void {
    global $DB;
    $sql = "SELECT w.*, cm.idnumber AS cmidnumber
              FROM {whakaharatau} w
              JOIN {course_modules} cm ON cm.instance = w.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'whakaharatau'
             WHERE w.course = ?";
    foreach ($DB->get_records_sql($sql, [$courseid]) as $instance) {
        if (instance_settings::is_test($instance)) {
            whakaharatau_grade_item_update($instance, 'reset');
        }
    }
}

/**
 * Course reset: delete attempts (and their grades).
 *
 * @param stdClass $data reset form data
 * @return array status rows
 */
function whakaharatau_reset_userdata($data): array {
    global $DB;
    $status = [];
    if (!empty($data->reset_whakaharatau_attempts)) {
        foreach ($DB->get_fieldset_select('whakaharatau', 'id', 'course = ?', [$data->courseid]) as $id) {
            attempt::delete_all_attempts((int) $id);
        }
        if (empty($data->reset_gradebook_grades)) {
            whakaharatau_reset_gradebook($data->courseid);
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_whakaharatau'),
            'item' => get_string('resetattempts', 'mod_whakaharatau'),
            'error' => false,
        ];
    }
    return $status;
}

/**
 * Add the Edit slides, Import slides, Export slides and Report links to the activity's settings navigation.
 *
 * @param settings_navigation $settings settings navigation
 * @param navigation_node $node the module's node
 * @return void
 */
function whakaharatau_extend_settings_navigation(settings_navigation $settings, navigation_node $node): void {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = $cm->context;
    if (has_capability('mod/whakaharatau:manage', $context)) {
        $node->add(
            get_string('editslides', 'mod_whakaharatau'),
            new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_whakaharatau_edit'
        );
        $node->add(
            get_string('importslides', 'mod_whakaharatau'),
            new moodle_url('/mod/whakaharatau/import.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_whakaharatau_import'
        );
        $node->add(
            get_string('exportslides', 'mod_whakaharatau'),
            new moodle_url('/mod/whakaharatau/export.php', ['id' => $cm->id, 'sesskey' => sesskey()]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_whakaharatau_export'
        );
    }
    if (has_capability('mod/whakaharatau:viewreports', $context)) {
        $node->add(
            get_string('report', 'mod_whakaharatau'),
            new moodle_url('/mod/whakaharatau/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_whakaharatau_report'
        );
    }
}
