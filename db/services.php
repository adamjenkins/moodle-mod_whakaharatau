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
 * External functions of mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_whakaharatau_start_attempt' => [
        'classname' => \mod_whakaharatau\external\start_attempt::class,
        'description' => 'Start a new attempt or practice session, or resume the unfinished one.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/whakaharatau:view, mod/whakaharatau:attempt',
    ],
    'mod_whakaharatau_get_attempt_state' => [
        'classname' => \mod_whakaharatau\external\get_attempt_state::class,
        'description' => 'Get the state of your own attempt and the media of some of its slides.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/whakaharatau:view',
    ],
    'mod_whakaharatau_submit_answer' => [
        'classname' => \mod_whakaharatau\external\submit_answer::class,
        'description' => 'Choose an option on the current slide; the server marks it.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/whakaharatau:view, mod/whakaharatau:attempt',
    ],
    'mod_whakaharatau_finish_attempt' => [
        'classname' => \mod_whakaharatau\external\finish_attempt::class,
        'description' => 'Finish your own attempt and get its summary, and its review when allowed.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/whakaharatau:view, mod/whakaharatau:attempt',
    ],
    'mod_whakaharatau_move_slide' => [
        'classname' => \mod_whakaharatau\external\move_slide::class,
        'description' => 'Move a slide so it comes straight after another slide (Edit slides drag-reorder).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/whakaharatau:manage',
    ],
];
