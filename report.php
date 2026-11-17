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
 * Teacher report: attempts per learner (Test) or practice sessions (Practice), per-slide difficulty,
 * download and deleting attempts.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\page_access;
use mod_whakaharatau\local\report;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');

$id = required_param('id', PARAM_INT);
$view = optional_param('mode', report::VIEW_ATTEMPTS, PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'whakaharatau');
require_login($course, false, $cm);
[$instance, $context] = page_access::require_page(page_access::REPORT, $cm);

if ($view !== report::VIEW_SLIDES) {
    $view = report::VIEW_ATTEMPTS;
}
$attemptmode = optional_param('attemptmode', (int) $instance->mode, PARAM_INT);
$attemptmode = $attemptmode === instance_settings::MODE_TEST ? instance_settings::MODE_TEST :
    instance_settings::MODE_PRACTICE;

$url = new moodle_url('/mod/whakaharatau/report.php', ['id' => $cm->id, 'mode' => $view, 'attemptmode' => $attemptmode]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('report', 'mod_whakaharatau'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);

$groupid = groups_get_activity_group($cm, true);
$report = new report($instance, $cm, $context, $attemptmode, $groupid);
$candelete = has_capability('mod/whakaharatau:deleteattempts', $context);

// Deleting attempts: the table form posts the ids, a confirmation page follows, then the delete.
if ($action === 'delete') {
    page_access::require_capabilities(page_access::DELETE_ATTEMPTS, $context);
    require_sesskey();
    $attemptids = optional_param_array('attemptid', [], PARAM_INT);
    if (!$attemptids) {
        $sequence = optional_param('attemptids', '', PARAM_SEQUENCE);
        $attemptids = $sequence === '' ? [] : explode(',', $sequence);
    }
    $attemptids = array_values(array_unique(array_filter(array_map('intval', $attemptids))));
    if (!$attemptids) {
        redirect($url);
    }
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        $deleted = $report->delete_attempts($attemptids);
        $notice = $attemptmode === instance_settings::MODE_TEST ? 'attemptsdeletedtest' : 'attemptsdeletedpractice';
        redirect(
            $url,
            get_string($notice, 'mod_whakaharatau', $deleted),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $confirmurl = new moodle_url($url, [
        'action' => 'delete',
        'attemptids' => implode(',', $attemptids),
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    echo $OUTPUT->header();
    // The tab decides what was selected: Test attempts or practice sessions; one or several.
    $confirmkey = 'deleteconfirm' . ($attemptmode === instance_settings::MODE_TEST ? 'test' : 'practice')
        . (count($attemptids) === 1 ? 'one' : '');
    echo $OUTPUT->confirm(
        get_string($confirmkey, 'mod_whakaharatau', count($attemptids)),
        new single_button($confirmurl, get_string('delete'), 'post', single_button::BUTTON_DANGER),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

// Download through \core\dataformat.
if ($download !== '') {
    $filename = clean_filename(format_string($instance->name, true, ['context' => $context]) . '-' .
        get_string($view === report::VIEW_SLIDES ? 'report_viewslides' : 'report_viewattempts', 'mod_whakaharatau'));
    if ($view === report::VIEW_SLIDES) {
        $columns = report::get_slide_columns();
        $rows = array_map(fn($row) => report::format_slide_row($row), $report->get_slide_stats());
    } else {
        $columns = $report->get_attempt_columns();
        $rows = array_map(fn($row) => $report->format_attempt_row($row), array_values($report->get_attempts()));
    }
    \core\dataformat::download_data($filename, $download, $columns, new ArrayIterator($rows));
    exit;
}

/** @var core_renderer $output */
$output = $PAGE->get_renderer('core');

// The view switch: Learners / Slides, and Test attempts / Practice sessions.
$nav = ['views' => [], 'modes' => []];
foreach ([report::VIEW_ATTEMPTS => 'report_viewattempts', report::VIEW_SLIDES => 'report_viewslides'] as $key => $str) {
    $nav['views'][] = [
        'url' => (new moodle_url($url, ['mode' => $key]))->out(false),
        'label' => get_string($str, 'mod_whakaharatau'),
        'active' => $key === $view,
    ];
}
$modelabels = [
    instance_settings::MODE_TEST => 'report_modetest',
    instance_settings::MODE_PRACTICE => 'report_modepractice',
];
foreach ($modelabels as $key => $str) {
    $nav['modes'][] = [
        'url' => (new moodle_url($url, ['attemptmode' => $key]))->out(false),
        'label' => get_string($str, 'mod_whakaharatau'),
        'active' => $key === $attemptmode,
    ];
}

$templatecontext = [
    'cmid' => (int) $cm->id,
    'cansee' => $report->can_see_any(),
    'istest' => $attemptmode === instance_settings::MODE_TEST,
];

if ($view === report::VIEW_SLIDES) {
    $rows = [];
    foreach ($report->get_slide_stats() as $row) {
        $cells = report::format_slide_row($row);
        $rows[] = $cells + [
            'imageurl' => $row['imageurl'] ? $row['imageurl']->out(false) : '',
            'decorative' => $row['decorative'],
            'hasdata' => $row['appearances'] > 0,
            'percentvalue' => $row['percent'] === null ? 0 : $row['percent'],
        ];
    }
    $templatecontext += ['rows' => $rows, 'hasrows' => !empty($rows)];
    $template = 'mod_whakaharatau/report_slides';
} else {
    $rows = [];
    $lastuser = 0;
    foreach ($report->get_attempts() as $row) {
        $cells = $report->format_attempt_row($row);
        $rows[] = $cells + [
            'attemptid' => (int) $row->id,
            'profileurl' => (new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $course->id]))->out(false),
            'firstofuser' => (int) $row->userid !== $lastuser,
        ];
        $lastuser = (int) $row->userid;
    }
    $templatecontext += [
        'rows' => $rows,
        'hasrows' => !empty($rows),
        'candelete' => $candelete && !empty($rows),
        'formaction' => (new moodle_url('/mod/whakaharatau/report.php'))->out(false),
        'sesskey' => sesskey(),
        'mode' => $view,
        'attemptmode' => $attemptmode,
    ];
    $template = 'mod_whakaharatau/report_attempts';
}

echo $output->header();
echo $output->heading(get_string('report', 'mod_whakaharatau'), 2);
groups_print_activity_menu($cm, $url);
echo $output->render_from_template('mod_whakaharatau/report_nav', $nav);
echo $output->render_from_template($template, $templatecontext);
if (!empty($rows) && $report->can_see_any()) {
    echo $output->download_dataformat_selector(
        get_string('report_download', 'mod_whakaharatau'),
        new moodle_url('/mod/whakaharatau/report.php'),
        'download',
        ['id' => $cm->id, 'mode' => $view, 'attemptmode' => $attemptmode]
    );
}
echo $output->footer();
