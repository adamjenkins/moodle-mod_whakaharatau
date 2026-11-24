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
 * Upgrade steps for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin from an older version.
 *
 * @param int $oldversion the version being upgraded from
 * @return bool
 */
function xmldb_whakaharatau_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026112400) {
        // Picture fade per slide, its default for new slides, and the cover's own fade. The column defaults are the
        // fade every picture had before, so existing activities look the same.
        $fields = [
            'whakaharatau' => [
                ['fade', '1', '1', 'transcriptsbefore'],
                ['fadewidth', '2', '15', 'fade'],
                ['coverfade', '1', '1', 'fadewidth'],
                ['coverfadewidth', '2', '15', 'coverfade'],
            ],
            'whakaharatau_slide' => [
                ['fade', '1', '1', 'decorative'],
                ['fadewidth', '2', '15', 'fade'],
            ],
        ];
        foreach ($fields as $tablename => $tablefields) {
            $table = new xmldb_table($tablename);
            foreach ($tablefields as [$name, $length, $default, $previous]) {
                $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, $length, null, XMLDB_NOTNULL, null, $default, $previous);
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        upgrade_mod_savepoint(true, 2026112400, 'whakaharatau');
    }

    return true;
}
