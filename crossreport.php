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
 * Reports across the activities of a course, a course category or the site.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\form\report_filter_form;
use mod_whakaharatau\local\cross_report;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\report_filter;
use mod_whakaharatau\output\report_page;

require(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
} else {
    require_login();
    $context = $categoryid ? context_coursecat::instance($categoryid) : context_system::instance();
}

// Named results need viewcrossreports, anonymised ones exportanonymised; each activity is checked as well.
$named = has_capability('mod/whakaharatau:viewcrossreports', $context);
$anonymous = has_capability('mod/whakaharatau:exportanonymised', $context);
if (!$named && !$anonymous) {
    require_capability('mod/whakaharatau:viewcrossreports', $context);
}
$views = [];
if ($named) {
    $views[cross_report::VIEW_SUMMARY] = get_string('crossreport_viewsummary', 'mod_whakaharatau');
    $views[cross_report::VIEW_PROGRESS] = get_string('crossreport_viewprogress', 'mod_whakaharatau');
    $views[cross_report::VIEW_PREPOST] = get_string('crossreport_viewprepost', 'mod_whakaharatau');
}
if ($anonymous) {
    $views[cross_report::VIEW_ANONYMISED] = get_string('report_anonymised', 'mod_whakaharatau');
}
$view = optional_param('view', '', PARAM_ALPHA);
if (!isset($views[$view])) {
    $view = (string) array_key_first($views);
}
$anonymised = $view === cross_report::VIEW_ANONYMISED;
$grouping = optional_param('groupby', 'activity', PARAM_ALPHA);
if (!isset(cross_report::GROUPINGS[$grouping])) {
    $grouping = 'activity';
}
$pre = optional_param('pre', 0, PARAM_INT);
$post = optional_param('post', 0, PARAM_INT);

$filter = report_filter::from_request($context, $anonymised);
$scopeurl = cross_report::get_url($context);
$baseurl = new moodle_url($scopeurl, ['view' => $view]);
if ($view === cross_report::VIEW_SUMMARY || $anonymised) {
    $baseurl->param('groupby', $grouping);
} else if ($view === cross_report::VIEW_PREPOST) {
    $baseurl->params(['pre' => $pre, 'post' => $post]);
}
$url = new moodle_url($baseurl, $filter->get_params());
$title = get_string('crossreport', 'mod_whakaharatau');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($title);
$PAGE->set_heading($context->contextlevel == CONTEXT_SYSTEM ? format_string($SITE->fullname) :
    $context->get_context_name(false));

$filterform = report_filter_form::handle($baseurl, $context, $filter, $anonymised);
$report = new cross_report($context, $filter, $anonymised);
$summary = null;
switch ($view) {
    case cross_report::VIEW_PROGRESS:
        $table = $report->progress();
        break;
    case cross_report::VIEW_PREPOST:
        [$table, $summary] = $report->prepost($pre, $post);
        break;
    default:
        $table = $report->summary($grouping, $anonymised);
}
$sort = report_page::prepare($table, $title . '-' . $views[$view]);

$groupings = array_map(fn($key) => get_string($key, 'mod_whakaharatau'), cross_report::GROUPINGS);
$viewurl = new moodle_url($scopeurl, $filter->get_params());
$nav = [
    'scopes' => cross_report::get_scopes($context),
    'views' => report_page::links($viewurl, 'view', $views, $view),
];
if ($view === cross_report::VIEW_SUMMARY || $anonymised) {
    $nav['groupings'] = report_page::links($url, 'groupby', $groupings, $grouping);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 2);
echo $OUTPUT->render_from_template('mod_whakaharatau/report_nav', $nav);
$filterform->display();
$notes = [
    cross_report::VIEW_SUMMARY => 'crossreport_notesummary',
    cross_report::VIEW_PROGRESS => 'crossreport_noteprogress',
    cross_report::VIEW_PREPOST => 'crossreport_noteprepost',
    cross_report::VIEW_ANONYMISED => 'crossreport_noteanonymised',
];
echo html_writer::tag(
    'p',
    get_string($notes[$view], 'mod_whakaharatau', instance_settings::get_anonymous_minimum()),
    ['class' => 'text-muted']
);
$emptytext = get_string('report_nomatch', 'mod_whakaharatau');
if ($view === cross_report::VIEW_PREPOST) {
    $options = [];
    foreach ($report->get_activities() as $activity) {
        $options[(int) $activity->id] = get_string('crossreport_activityincourse', 'mod_whakaharatau', (object) [
            'activity' => $activity->activityname, 'course' => $activity->coursename]);
    }
    $choices = function (int $selected) use ($options): array {
        $list = [];
        foreach ($options as $id => $label) {
            $list[] = ['value' => $id, 'label' => $label, 'selected' => $id === $selected];
        }
        return $list;
    };
    $hidden = [];
    foreach ($url->params() as $name => $value) {
        if ($name !== 'pre' && $name !== 'post') {
            $hidden[] = ['name' => $name, 'value' => $value];
        }
    }
    $data = [
        'formaction' => $url->out_omit_querystring(),
        'hidden' => $hidden,
        'preoptions' => $choices($pre),
        'postoptions' => $choices($post),
        'hassummary' => $summary !== null,
    ];
    if ($summary) {
        $number = fn(float $value) => format_float($value, 1, true, true);
        $data['summary'] = get_string('crossreport_prepostsummary', 'mod_whakaharatau', (object) [
            'learners' => $summary->learners,
            'pre' => $number($summary->pre),
            'post' => $number($summary->post),
            'change' => ($summary->change > 0 ? '+' : '') . $number($summary->change),
            'better' => $summary->better,
            'same' => $summary->same,
            'worse' => $summary->worse,
        ]);
    }
    echo $OUTPUT->render_from_template('mod_whakaharatau/crossreport_prepost', $data);
    if ($pre && $post && $pre === $post) {
        $emptytext = get_string('crossreport_sameactivity', 'mod_whakaharatau');
    } else if (!$pre || !$post) {
        $emptytext = get_string('crossreport_choosetwo', 'mod_whakaharatau');
    }
}
echo report_page::render($table, $url, $sort, ['emptytext' => $emptytext]);
echo $OUTPUT->footer();
