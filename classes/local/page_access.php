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

namespace mod_whakaharatau\local;

use cm_info;
use coding_exception;
use context_module;

/**
 * Access to the teacher pages (Edit slides, Import, Export, Report) and the report's delete action.
 *
 * Every page loads its course module from the URL id and calls require_login() itself, then checks its
 * capabilities here, so the rule "which page needs which capability" is in one place and is tested
 * (tests/local/page_access_test.php).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_access {
    /** @var string Edit slides page (edit.php). */
    const EDIT = 'edit';
    /** @var string Import slides page (import.php). */
    const IMPORT = 'import';
    /** @var string Export slides (export.php). */
    const EXPORT = 'export';
    /** @var string Report page (report.php). */
    const REPORT = 'report';
    /** @var string Deleting attempts from the report. */
    const DELETE_ATTEMPTS = 'deleteattempts';

    /** @var string Anonymised export (anonymised.php). */
    const ANONYMISED = 'anonymised';

    /** @var string[][] page => the capabilities it needs, all of them. */
    const CAPABILITIES = [
        self::EDIT => ['mod/whakaharatau:manage'],
        self::IMPORT => ['mod/whakaharatau:manage'],
        self::EXPORT => ['mod/whakaharatau:manage'],
        self::REPORT => ['mod/whakaharatau:viewreports'],
        self::ANONYMISED => ['mod/whakaharatau:exportanonymised'],
        self::DELETE_ATTEMPTS => ['mod/whakaharatau:viewreports', 'mod/whakaharatau:deleteattempts'],
    ];

    /**
     * After the page's require_login(): require the page's capabilities and load its activity.
     *
     * @param string $page one of the class constants
     * @param cm_info $cm the course module loaded from the URL id
     * @return array [instance record, module context]
     * @throws \required_capability_exception when the user lacks a capability
     */
    public static function require_page(string $page, cm_info $cm): array {
        global $DB;
        $context = context_module::instance($cm->id);
        self::require_capabilities($page, $context);
        $instance = $DB->get_record('whakaharatau', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$instance, $context];
    }

    /**
     * Require the capabilities of a page or action in a module context.
     *
     * @param string $page one of the class constants
     * @param context_module $context module context
     * @return void
     * @throws \required_capability_exception when the user lacks a capability
     */
    public static function require_capabilities(string $page, context_module $context): void {
        if (!isset(self::CAPABILITIES[$page])) {
            throw new coding_exception('Unknown mod_whakaharatau page: ' . $page);
        }
        foreach (self::CAPABILITIES[$page] as $capability) {
            require_capability($capability, $context);
        }
    }
}
