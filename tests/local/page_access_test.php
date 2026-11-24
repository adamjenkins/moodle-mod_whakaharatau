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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests of who may open the teacher pages (Edit slides, Import, Export, Report) and delete attempts.
 *
 * The pages edit.php, import.php, export.php and report.php call require_login() and then
 * page_access::require_page(), so these tests are the pages' capability rules. Moodle's Behat treats any error
 * page as a failed step, so refusals are tested here.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(page_access::class)]
final class page_access_test extends \advanced_testcase {
    /**
     * Who may open what: role => page => allowed.
     *
     * @return array
     */
    public static function access_provider(): array {
        $cases = [];
        $expected = [
            'student' => [page_access::EDIT => false, page_access::IMPORT => false, page_access::EXPORT => false,
                page_access::REPORT => false, page_access::DELETE_ATTEMPTS => false, page_access::ANONYMISED => false],
            'teacher' => [page_access::EDIT => false, page_access::IMPORT => false, page_access::EXPORT => false,
                page_access::REPORT => true, page_access::DELETE_ATTEMPTS => false, page_access::ANONYMISED => false],
            'editingteacher' => [page_access::EDIT => true, page_access::IMPORT => true, page_access::EXPORT => true,
                page_access::REPORT => true, page_access::DELETE_ATTEMPTS => true, page_access::ANONYMISED => true],
        ];
        foreach ($expected as $role => $pages) {
            foreach ($pages as $page => $allowed) {
                $cases["$role $page"] = [$role, $page, $allowed];
            }
        }
        return $cases;
    }

    /**
     * A role is let into a page, or refused with required_capability_exception.
     *
     * @param string $role role archetype shortname
     * @param string $page page_access constant
     * @param bool $allowed whether the role may open it
     * @return void
     */
    #[DataProvider('access_provider')]
    public function test_page_access_by_role(string $role, string $page, bool $allowed): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, $role));

        if (!$allowed) {
            $this->expectException(\required_capability_exception::class);
        }
        if ($page === page_access::DELETE_ATTEMPTS) {
            page_access::require_capabilities($page, \context_module::instance($created->cmid));
        } else {
            $cm = get_fast_modinfo($course)->get_cm($created->cmid);
            [$instance, $context] = page_access::require_page($page, $cm);
            $this->assertSame((int) $created->id, (int) $instance->id);
            $this->assertSame(\context_module::instance($created->cmid)->id, $context->id);
        }
    }

    /**
     * Every page constant has capabilities, and an unknown page is a coding error, not an open door.
     *
     * @return void
     */
    public function test_unknown_page_is_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $created = $this->getDataGenerator()->create_module('whakaharatau', ['course' => $course->id]);
        $this->setAdminUser();
        foreach (page_access::CAPABILITIES as $page => $capabilities) {
            $this->assertNotEmpty($capabilities, $page);
        }
        $this->expectException(\coding_exception::class);
        page_access::require_capabilities('nosuchpage', \context_module::instance($created->cmid));
    }
}
