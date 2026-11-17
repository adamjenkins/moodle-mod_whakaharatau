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
 * Import slides from a ZIP or many images and audio files: upload, review, confirm.
 *
 * Step 1 (import_form) stages the upload in the teacher's own draft area and shows the review table in the
 * same request. Step 2 (step=confirm, sesskey) rebuilds the plan from that stage, applies the review choices
 * and creates the slides. Nothing is written to the activity before step 2.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_whakaharatau\form\import_form;
use mod_whakaharatau\local\importer;
use mod_whakaharatau\local\slides;
use mod_whakaharatau\output\import_review;

$id = required_param('id', PARAM_INT);
$step = optional_param('step', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('whakaharatau', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('whakaharatau', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/whakaharatau:manage', $context);

$url = new moodle_url('/mod/whakaharatau/import.php', ['id' => $cm->id]);
$editurl = new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('importslides', 'mod_whakaharatau'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->activityheader->disable();
navigation_node::override_active_url($url);

$existing = slides::count_slides((int) $instance->id);

// Step 2: confirm or cancel the reviewed import.
if ($step === 'confirm') {
    require_sesskey();
    $stageid = required_param('stage', PARAM_INT);
    if (optional_param('cancelimport', '', PARAM_RAW) !== '') {
        importer::discard_stage($stageid);
        redirect($url);
    }
    $mode = required_param('importmode', PARAM_ALPHA);
    $replace = $mode === import_form::MODE_REPLACE;
    $plan = importer::build_plan($stageid);
    if (!$plan['slides'] || required_param('planhash', PARAM_ALPHANUM) !== importer::plan_hash($plan)) {
        importer::discard_stage($stageid);
        redirect($url, get_string('importexpired', 'mod_whakaharatau'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $include = optional_param_array('include', [], PARAM_BOOL);
    $alttext = optional_param_array('alttext', [], PARAM_TEXT);
    $decorative = optional_param_array('decorative', [], PARAM_BOOL);
    $correct = optional_param_array('correct', [], PARAM_INT);
    $choices = [];
    foreach (array_keys($plan['slides']) as $index) {
        $choice = [];
        if (array_key_exists($index, $include)) {
            $choice['include'] = !empty($include[$index]);
        }
        if (array_key_exists($index, $alttext)) {
            $choice['alttext'] = $alttext[$index];
        }
        if (array_key_exists($index, $decorative)) {
            $choice['decorative'] = !empty($decorative[$index]);
        }
        if (array_key_exists($index, $correct)) {
            $choice['correct'] = (int) $correct[$index];
        }
        $choices[$index] = $choice;
    }
    $plan = importer::apply_choices($plan, $choices);
    if (!$plan['slides']) {
        importer::discard_stage($stageid);
        redirect(
            $url,
            get_string('importnothingchosen', 'mod_whakaharatau'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $created = importer::apply($context, $instance, $stageid, $plan, $replace);
    redirect(
        $editurl,
        get_string($created === 1 ? 'importdoneone' : 'importdone', 'mod_whakaharatau', $created),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Step 1: upload.
$maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes, $course->maxbytes);
$mform = new import_form($url, ['cmid' => $cm->id, 'maxbytes' => $maxbytes, 'slidecount' => $existing]);
if ($mform->is_cancelled()) {
    redirect($editurl);
}
if ($data = $mform->get_data()) {
    $staged = importer::stage((int) $data->importfiles, (int) $maxbytes);
    $plan = importer::build_plan($staged['draftitemid']);
    $review = new import_review(
        (int) $cm->id,
        $staged['draftitemid'],
        (string) $data->importmode,
        $plan,
        $staged['rejected'],
        $staged['errors'],
        $existing
    );

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importreviewheading', 'mod_whakaharatau'));
    echo $OUTPUT->render_from_template('mod_whakaharatau/import_review', $review->export_for_template($OUTPUT));
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importslides', 'mod_whakaharatau'));
$mform->display();
echo $OUTPUT->footer();
