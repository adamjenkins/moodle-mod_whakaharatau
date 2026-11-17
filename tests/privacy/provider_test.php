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

namespace mod_whakaharatau\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_whakaharatau\local\instance_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests of the privacy provider: metadata, contexts, users, export and the three delete paths.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass course */
    private $course;
    /** @var \stdClass first activity */
    private $one;
    /** @var \stdClass second activity */
    private $two;
    /** @var \stdClass student with attempts in both activities */
    private $alice;
    /** @var \stdClass student with an attempt in the first activity */
    private $bob;
    /** @var \stdClass student with no attempts */
    private $carol;

    /**
     * Two activities with attempts by Alice (both) and Bob (one); Carol has none.
     *
     * @return void
     */
    private function setup_data(): void {
        $generator = $this->getDataGenerator();
        /** @var \mod_whakaharatau_generator $modgen */
        $modgen = $generator->get_plugin_generator('mod_whakaharatau');
        $this->course = $generator->create_course(['summary' => '']);
        $this->one = $generator->create_module('whakaharatau', ['course' => $this->course->id,
            'mode' => instance_settings::MODE_TEST]);
        $this->two = $generator->create_module('whakaharatau', ['course' => $this->course->id]);
        foreach ([$this->one, $this->two] as $instance) {
            $modgen->create_slide(['whakaharatauid' => $instance->id]);
            $modgen->create_slide(['whakaharatauid' => $instance->id]);
        }
        $this->alice = $generator->create_and_enrol($this->course, 'student');
        $this->bob = $generator->create_and_enrol($this->course, 'student');
        $this->carol = $generator->create_and_enrol($this->course, 'student');

        $modgen->create_attempt(['whakaharatauid' => $this->one->id, 'userid' => $this->alice->id,
            'answers' => ['right', 'wrong']]);
        $modgen->create_attempt(['whakaharatauid' => $this->two->id, 'userid' => $this->alice->id,
            'answers' => ['wrongthenright']]);
        $modgen->create_attempt(['whakaharatauid' => $this->one->id, 'userid' => $this->bob->id,
            'answers' => ['right', 'right']]);
    }

    /**
     * Number of attempts of a user in an activity.
     *
     * @param \stdClass $instance activity
     * @param \stdClass $user user
     * @return int
     */
    private function count_attempts(\stdClass $instance, \stdClass $user): int {
        global $DB;
        return $DB->count_records('whakaharatau_attempt', ['whakaharatauid' => $instance->id, 'userid' => $user->id]);
    }

    /**
     * Responses left for a user in an activity.
     *
     * @param \stdClass $instance activity
     * @param \stdClass $user user
     * @return int
     */
    private function count_responses(\stdClass $instance, \stdClass $user): int {
        global $DB;
        return $DB->count_records_sql('SELECT COUNT(r.id) FROM {whakaharatau_response} r
                                         JOIN {whakaharatau_attempt} a ON a.id = r.attemptid
                                        WHERE a.whakaharatauid = ? AND a.userid = ?', [$instance->id, $user->id]);
    }

    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_whakaharatau'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('whakaharatau_attempt', $names);
        $this->assertContains('whakaharatau_response', $names);
        $this->assertContains('core_grades', $names);
        foreach ($collection->get_collection() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'mod_whakaharatau'),
                $item->get_summary()
            );
            foreach ($item->get_privacy_fields() as $field) {
                $this->assertTrue(get_string_manager()->string_exists($field, 'mod_whakaharatau'), $field);
            }
        }
    }

    public function test_get_contexts_and_users(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $contextone = \context_module::instance($this->one->cmid);
        $contexttwo = \context_module::instance($this->two->cmid);

        $ids = array_map('intval', provider::get_contexts_for_userid((int) $this->alice->id)->get_contextids());
        sort($ids);
        $expected = [(int) $contextone->id, (int) $contexttwo->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(
            [(int) $contextone->id],
            array_map('intval', provider::get_contexts_for_userid((int) $this->bob->id)->get_contextids())
        );
        $this->assertSame([], provider::get_contexts_for_userid((int) $this->carol->id)->get_contextids());

        $userlist = new userlist($contextone, 'mod_whakaharatau');
        provider::get_users_in_context($userlist);
        $users = array_map('intval', $userlist->get_userids());
        sort($users);
        $this->assertSame([(int) $this->alice->id, (int) $this->bob->id], $users);

        // Other context levels give nobody.
        $userlist = new userlist(\context_course::instance($this->course->id), 'mod_whakaharatau');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $contextone = \context_module::instance($this->one->cmid);
        $contexttwo = \context_module::instance($this->two->cmid);
        $this->export_context_data_for_user((int) $this->alice->id, $contextone, 'mod_whakaharatau');

        $writer = writer::with_context($contextone);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('privacy:attempts', 'mod_whakaharatau')]);
        $this->assertCount(1, $data->attempts);
        $attempt = $data->attempts[0];
        $this->assertSame(1, $attempt->attempt);
        $this->assertSame(get_string('modetest', 'mod_whakaharatau'), $attempt->mode);
        $this->assertSame(get_string('statefinished', 'mod_whakaharatau'), $attempt->state);
        $this->assertEquals(1.0, $attempt->score);
        $this->assertEquals(2.0, $attempt->maxscore);
        $this->assertCount(2, $attempt->responses);
        // Slides and options are exported by number, never by id.
        foreach ($attempt->responses as $response) {
            $this->assertContains($response->slide, [1, 2]);
            $this->assertGreaterThanOrEqual(1, $response->option);
            $this->assertLessThanOrEqual(4, $response->option);
        }
        $slidenumbers = array_map(fn($r) => $r->slide, $attempt->responses);
        sort($slidenumbers);
        $this->assertSame([1, 2], $slidenumbers);

        // Bob's data is not in Alice's export, and nothing was exported for the other activity.
        $this->assertFalse(writer::with_context($contexttwo)->has_any_data());
    }

    public function test_export_nothing_for_user_without_attempts(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $contextone = \context_module::instance($this->one->cmid);
        $this->export_context_data_for_user((int) $this->carol->id, $contextone, 'mod_whakaharatau');
        $this->assertFalse(writer::with_context($contextone)->has_any_data());
    }

    public function test_delete_data_for_all_users_in_context(): void {
        $this->resetAfterTest();
        $this->setup_data();
        provider::delete_data_for_all_users_in_context(\context_module::instance($this->one->cmid));
        $this->assertSame(0, $this->count_attempts($this->one, $this->alice));
        $this->assertSame(0, $this->count_attempts($this->one, $this->bob));
        $this->assertSame(0, $this->count_responses($this->one, $this->bob));
        $this->assertSame(1, $this->count_attempts($this->two, $this->alice));
        $this->assertGreaterThan(0, $this->count_responses($this->two, $this->alice));

        // A course context is ignored.
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->course->id));
        $this->assertSame(1, $this->count_attempts($this->two, $this->alice));
    }

    public function test_delete_data_for_user(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $contextone = \context_module::instance($this->one->cmid);
        $contextlist = new approved_contextlist($this->alice, 'mod_whakaharatau', [$contextone->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame(0, $this->count_attempts($this->one, $this->alice));
        $this->assertSame(0, $this->count_responses($this->one, $this->alice));
        $this->assertSame(1, $this->count_attempts($this->two, $this->alice), 'only the approved context');
        $this->assertSame(1, $this->count_attempts($this->one, $this->bob), 'only that user');
    }

    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $contextone = \context_module::instance($this->one->cmid);
        $userlist = new approved_userlist($contextone, 'mod_whakaharatau', [(int) $this->bob->id]);
        provider::delete_data_for_users($userlist);
        $this->assertSame(0, $this->count_attempts($this->one, $this->bob));
        $this->assertSame(0, $this->count_responses($this->one, $this->bob));
        $this->assertSame(1, $this->count_attempts($this->one, $this->alice));
        $this->assertSame(1, $this->count_attempts($this->two, $this->alice));
    }
}
