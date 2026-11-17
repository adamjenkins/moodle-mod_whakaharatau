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
 * Download the activity's slides as a ZIP with slides.csv and media, the format the import page reads.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use mod_whakaharatau\local\exporter;
use mod_whakaharatau\local\slides;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('whakaharatau', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('whakaharatau', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/whakaharatau:manage', $context);

$PAGE->set_url('/mod/whakaharatau/export.php', ['id' => $cm->id]);
$PAGE->set_context($context);

if (!slides::count_slides((int) $instance->id)) {
    redirect(
        new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]),
        get_string('exportnothing', 'mod_whakaharatau'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

\core\session\manager::write_close();
$path = exporter::export_to_file($context, $instance);
send_temp_file($path, exporter::get_filename($instance, $context));
