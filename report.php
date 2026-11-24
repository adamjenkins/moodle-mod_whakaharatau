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
 * Teacher report: attempts (with deleting), learners, individual answers, questions and versions of changed questions.
 * Every table can be sorted, filtered and downloaded.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\form\report_filter_form;
use mod_whakaharatau\local\activity_tables;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\page_access;
use mod_whakaharatau\local\report;
use mod_whakaharatau\local\report_filter;
use mod_whakaharatau\output\report_page;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');

$id = required_param('id', PARAM_INT);
$view = optional_param('mode', report::VIEW_ATTEMPTS, PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'whakaharatau');
require_login($course, false, $cm);
[$instance, $context] = page_access::require_page(page_access::REPORT, $cm);

$views = [
    report::VIEW_ATTEMPTS => get_string('report_viewattempts', 'mod_whakaharatau'),
    report::VIEW_LEARNERS => get_string('report_viewlearners', 'mod_whakaharatau'),
    report::VIEW_ANSWERS => get_string('report_viewanswers', 'mod_whakaharatau'),
    report::VIEW_SLIDES => get_string('report_viewslides', 'mod_whakaharatau'),
    report::VIEW_VERSIONS => get_string('report_viewversions', 'mod_whakaharatau'),
];
if (!isset($views[$view])) {
    $view = report::VIEW_ATTEMPTS;
}
$attemptmode = optional_param('attemptmode', (int) $instance->mode, PARAM_INT);
$attemptmode = $attemptmode === instance_settings::MODE_TEST ? instance_settings::MODE_TEST :
    instance_settings::MODE_PRACTICE;

$filter = report_filter::from_request($context);
$baseurl = new moodle_url('/mod/whakaharatau/report.php', ['id' => $cm->id, 'mode' => $view, 'attemptmode' => $attemptmode]);
$url = new moodle_url($baseurl, $filter->get_params());
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

$filterform = report_filter_form::handle($baseurl, $context, $filter);

$tables = new activity_tables($instance, $cm, $context, $report, $filter);
switch ($view) {
    case report::VIEW_LEARNERS:
        $table = $tables->learners(new moodle_url($url, ['mode' => report::VIEW_ANSWERS]));
        break;
    case report::VIEW_ANSWERS:
        $table = $tables->answers();
        break;
    case report::VIEW_SLIDES:
        $table = $tables->questions();
        break;
    case report::VIEW_VERSIONS:
        $table = $tables->versions();
        break;
    default:
        $table = $tables->attempts($report, (int) $course->id);
}
$sort = report_page::prepare($table, format_string($instance->name, true, ['context' => $context, 'escape' => false]) .
    '-' . $views[$view]);

$nav = [
    'views' => report_page::links($url, 'mode', $views, $view),
    'modes' => report_page::links($url, 'attemptmode', [
        instance_settings::MODE_TEST => get_string('report_modetest', 'mod_whakaharatau'),
        instance_settings::MODE_PRACTICE => get_string('report_modepractice', 'mod_whakaharatau'),
    ], $attemptmode),
];

// Text above the table, and what an empty table says.
$istest = $attemptmode === instance_settings::MODE_TEST;
$notes = [
    report::VIEW_LEARNERS => $istest ? 'report_notelearners' : 'report_notefirstpractice',
    report::VIEW_SLIDES => $istest ? 'report_notefirsttest' : 'report_notefirstpractice',
    report::VIEW_VERSIONS => $istest ? 'report_noteversionstest' : 'report_noteversionspractice',
];
$empty = [
    report::VIEW_SLIDES => 'report_noslides',
    report::VIEW_VERSIONS => 'report_noversions',
];
$extra = [
    'emptytext' => get_string(
        $report->can_see_any() ? $empty[$view] ?? ($filter->is_active() ? 'report_nomatch' : 'report_noattempts')
            : 'report_notingroup',
        'mod_whakaharatau'
    ),
];
if ($view === report::VIEW_ATTEMPTS && $candelete && $table->count_rows()) {
    $extra += [
        'select' => true,
        'formaction' => (new moodle_url('/mod/whakaharatau/report.php'))->out(false),
        'cmid' => (int) $cm->id,
        'sesskey' => sesskey(),
        'mode' => $view,
        'attemptmode' => $attemptmode,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'mod_whakaharatau'), 2);
groups_print_activity_menu($cm, $url);
echo $OUTPUT->render_from_template('mod_whakaharatau/report_nav', $nav);
$filterform->display();
if (isset($notes[$view])) {
    echo html_writer::tag('p', get_string($notes[$view], 'mod_whakaharatau'), ['class' => 'text-muted']);
}
echo report_page::render($table, $url, $sort, $extra);
echo $OUTPUT->footer();
