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
 * Edit slides: list, add, edit, delete and reorder the slides of an activity.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_whakaharatau\form\slide_form;
use mod_whakaharatau\local\slides;
use mod_whakaharatau\output\edit_slides;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$slideid = optional_param('slideid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('whakaharatau', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('whakaharatau', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/whakaharatau:manage', $context);

$listurl = new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]);
$pageparams = ['id' => $cm->id];
if ($action !== '') {
    $pageparams['action'] = $action;
}
if ($slideid) {
    $pageparams['slideid'] = $slideid;
}
$PAGE->set_url('/mod/whakaharatau/edit.php', $pageparams);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('editslides', 'mod_whakaharatau'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->activityheader->disable();
navigation_node::override_active_url($listurl);

// A slide named in the URL must belong to this activity.
$slide = null;
if ($slideid) {
    $slide = slides::get_slide($slideid, IGNORE_MISSING);
    if (!$slide || (int) $slide->whakaharatauid !== (int) $instance->id) {
        throw new moodle_exception('errorslidenotinactivity', 'mod_whakaharatau');
    }
}

$slidenumber = 0;
if ($slide) {
    $slidenumber = array_search(
        (int) $slide->id,
        array_map('intval', array_keys(slides::get_slides((int) $instance->id))),
        true
    ) + 1;
}

if ($action === 'delete' && $slide) {
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        slides::delete_slide($context, (int) $slide->id);
        redirect($listurl, get_string('slidedeleted', 'mod_whakaharatau'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deleteslide', 'mod_whakaharatau', $slidenumber));
    $confirmurl = new moodle_url($listurl, ['action' => 'delete', 'slideid' => $slide->id, 'confirm' => 1,
        'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(get_string('deleteslideconfirm', 'mod_whakaharatau', $slidenumber), $confirmurl, $listurl);
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'edit') {
    $langs = [];
    if ($slide && !empty($slide->promptlang) && !isset(slide_form::PROMPT_LANGS[$slide->promptlang])) {
        $langs[$slide->promptlang] = $slide->promptlang;
    }
    $optioncount = $slide ? max(slides::MIN_OPTIONS, count(slides::get_options((int) $slide->id))) : 4;
    $formurl = new moodle_url($listurl, ['action' => 'edit']);
    $mform = new slide_form($formurl, [
        'cmid' => $cm->id,
        'slide' => $slide,
        'optioncount' => $optioncount,
        'langs' => $langs,
    ]);
    if ($mform->is_cancelled()) {
        redirect($listurl);
    }
    if ($data = $mform->get_data()) {
        slide_form::save($context, $instance, $data);
        redirect($listurl, get_string('slidesaved', 'mod_whakaharatau'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if (!$mform->is_submitted()) {
        $mform->set_data(slide_form::prepare_data($context, (int) $cm->id, $slide));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($slide ? get_string('editslide', 'mod_whakaharatau', $slidenumber)
        : get_string('addslide', 'mod_whakaharatau'));
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// The slide list.
$PAGE->requires->js_call_amd('mod_whakaharatau/edit_slides', 'init', [(int) $cm->id]);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editslides', 'mod_whakaharatau'));
$list = new edit_slides($instance, (int) $cm->id, $context);
echo $OUTPUT->render_from_template('mod_whakaharatau/edit_slides', $list->export_for_template($OUTPUT));
echo $OUTPUT->footer();
