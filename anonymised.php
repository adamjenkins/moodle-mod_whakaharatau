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
 * Anonymised question report: no learners, and no results from too few of them.
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

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'whakaharatau');
require_login($course, false, $cm);
[$instance, $context] = page_access::require_page(page_access::ANONYMISED, $cm);

$attemptmode = optional_param('attemptmode', (int) $instance->mode, PARAM_INT);
$attemptmode = $attemptmode === instance_settings::MODE_TEST ? instance_settings::MODE_TEST :
    instance_settings::MODE_PRACTICE;
$filter = report_filter::from_request($context, true);
$baseurl = new moodle_url('/mod/whakaharatau/anonymised.php', ['id' => $cm->id, 'attemptmode' => $attemptmode]);
$url = new moodle_url($baseurl, $filter->get_params());
$title = get_string('report_anonymised', 'mod_whakaharatau');
$PAGE->set_url($url);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($instance->name) . ': ' . $title);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);

$filterform = report_filter_form::handle($baseurl, $context, $filter, true);
// Everyone the viewer's groups allow: choosing one group at a time would narrow the results down to a few learners.
$groupid = groups_get_activity_groupmode($cm) == SEPARATEGROUPS
    && !has_capability('moodle/site:accessallgroups', $context) ? groups_get_activity_group($cm, true) : false;
$report = new report($instance, $cm, $context, $attemptmode, $groupid);
$tables = new activity_tables($instance, $cm, $context, $report, $filter);
$table = $tables->anonymised();
$sort = report_page::prepare($table, format_string($instance->name, true, ['context' => $context, 'escape' => false]) .
    '-' . $title);

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 2);
echo $OUTPUT->render_from_template('mod_whakaharatau/report_nav', [
    'modes' => report_page::links($url, 'attemptmode', [
        instance_settings::MODE_TEST => get_string('report_modetest', 'mod_whakaharatau'),
        instance_settings::MODE_PRACTICE => get_string('report_modepractice', 'mod_whakaharatau'),
    ], $attemptmode),
]);
$filterform->display();
echo html_writer::tag(
    'p',
    get_string('report_noteanonymised', 'mod_whakaharatau', instance_settings::get_anonymous_minimum()),
    ['class' => 'text-muted']
);
echo report_page::render($table, $url, $sort, ['emptytext' => get_string('report_noslides', 'mod_whakaharatau')]);
echo $OUTPUT->footer();
