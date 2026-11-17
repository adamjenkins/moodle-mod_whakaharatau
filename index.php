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
 * List of the Listening practice activities in a course.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);
$coursecontext = context_course::instance($course->id);

$event = \mod_whakaharatau\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strplural = get_string('modulenameplural', 'mod_whakaharatau');
$PAGE->set_url('/mod/whakaharatau/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . $strplural);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add($strplural);

echo $OUTPUT->header();
echo $OUTPUT->heading($strplural);

$instances = get_all_instances_in_course('whakaharatau', $course);
if (!$instances) {
    notice(get_string('thereareno', 'moodle', $strplural), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);
$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
$table->head = [];
$table->align = [];
if ($usesections) {
    $table->head[] = get_string('sectionname', 'format_' . $course->format);
    $table->align[] = 'left';
}
$table->head[] = get_string('name');
$table->head[] = get_string('mode', 'mod_whakaharatau');
$table->head[] = get_string('indexslides', 'mod_whakaharatau');
$table->align = array_merge($table->align, ['left', 'left', 'right']);

$modinfo = get_fast_modinfo($course);
$currentsection = '';
foreach ($instances as $instance) {
    $cm = $modinfo->get_cm($instance->coursemodule);
    if (!$cm->uservisible) {
        continue;
    }
    $row = [];
    if ($usesections) {
        $sectionname = '';
        if ($instance->section !== $currentsection) {
            $sectionname = get_section_name($course, $instance->section);
            $currentsection = $instance->section;
        }
        $row[] = s($sectionname);
    }
    $class = $instance->visible ? '' : 'dimmed';
    $row[] = html_writer::link(
        new moodle_url('/mod/whakaharatau/view.php', ['id' => $cm->id]),
        format_string($instance->name, true, ['context' => $cm->context]),
        ['class' => $class]
    );
    $row[] = s(get_string(instance_settings::is_test($instance) ? 'modetest' : 'modepractice', 'mod_whakaharatau'));
    $row[] = slides::count_slides((int) $instance->id);
    $table->data[] = $row;
}

echo html_writer::table($table);
echo $OUTPUT->footer();
