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

    if ($oldversion < 2026112401) {
        // Versions of a slide's content. Every answer so far is version 1: earlier changes were not recorded. The rows
        // of whakaharatau_version are made when they are first needed.
        foreach (['whakaharatau_slide' => 'fadewidth', 'whakaharatau_response' => 'tries'] as $tablename => $previous) {
            $table = new xmldb_table($tablename);
            $field = new xmldb_field('version', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '1', $previous);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('whakaharatau_version');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('whakaharatauid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slideid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('promptlang', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('alttext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('decorative', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('options', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('whakaharatauid', XMLDB_KEY_FOREIGN, ['whakaharatauid'], 'whakaharatau', ['id']);
        $table->add_index(
            'whakaharatauid-slideid-version',
            XMLDB_INDEX_UNIQUE,
            ['whakaharatauid', 'slideid', 'version']
        );
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026112401, 'whakaharatau');
    }

    return true;
}
