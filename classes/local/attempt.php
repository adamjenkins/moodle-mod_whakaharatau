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

use context_module;
use moodle_exception;
use stdClass;

/**
 * One Test attempt or one Practice session, with server-side marking.
 *
 * The browser never learns which option is correct before the slide is finished (and, with
 * "feedback only at the end", before the attempt is finished and review is allowed). Callers
 * check capabilities and that the attempt belongs to the current user; this class enforces the
 * activity's rules.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt {
    /** @var string The attempt can still be answered. */
    const STATE_INPROGRESS = 'inprogress';
    /** @var string The attempt is over and scored. */
    const STATE_FINISHED = 'finished';

    /** @var string Option state: not chosen (or result hidden and not chosen). */
    const OPTION_NONE = 'none';
    /** @var string Option state: chosen, result hidden (feedback only at the end). */
    const OPTION_CHOSEN = 'chosen';
    /** @var string Option state: chosen and wrong. */
    const OPTION_WRONG = 'wrong';
    /** @var string Option state: chosen and correct. */
    const OPTION_CORRECT = 'correct';
    /** @var string Option state: not chosen, shown as the answer because the tries ran out. */
    const OPTION_REVEALED = 'revealed';

    /** @var stdClass whakaharatau_attempt record */
    protected $record;
    /** @var stdClass whakaharatau record */
    protected $instance;
    /** @var \cm_info|stdClass course module */
    protected $cm;
    /** @var context_module module context */
    protected $context;
    /** @var array|null cache: round => slideid => response records (in try order) */
    protected $responses = null;

    /**
     * Constructor.
     *
     * @param stdClass $record whakaharatau_attempt record
     * @param stdClass $instance whakaharatau record
     * @param \cm_info|stdClass $cm course module
     * @param context_module $context module context
     */
    public function __construct(stdClass $record, stdClass $instance, $cm, context_module $context) {
        $this->record = $record;
        $this->instance = $instance;
        $this->cm = $cm;
        $this->context = $context;
    }

    // Loading.

    /**
     * Load an attempt by id with its instance, course module and context.
     *
     * @param int $attemptid attempt id
     * @return self
     */
    public static function get_by_id(int $attemptid): self {
        global $DB;
        $record = $DB->get_record('whakaharatau_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $instance = $DB->get_record('whakaharatau', ['id' => $record->whakaharatauid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('whakaharatau', $instance->id, $instance->course, false, MUST_EXIST);
        return new self($record, $instance, $cm, context_module::instance($cm->id));
    }

    /**
     * The user's unfinished attempt, if any.
     *
     * @param stdClass $instance whakaharatau record
     * @param \cm_info|stdClass $cm course module
     * @param int $userid user id
     * @return self|null
     */
    public static function get_inprogress(stdClass $instance, $cm, int $userid): ?self {
        global $DB;
        $records = $DB->get_records('whakaharatau_attempt', ['whakaharatauid' => $instance->id, 'userid' => $userid,
            'state' => self::STATE_INPROGRESS], 'attempt DESC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        return new self(reset($records), $instance, $cm, context_module::instance($cm->id));
    }

    /**
     * The user's most recent finished attempt, optionally of one mode.
     *
     * @param stdClass $instance whakaharatau record
     * @param \cm_info|stdClass $cm course module
     * @param int $userid user id
     * @param int|null $mode instance_settings::MODE_* or null for any
     * @return self|null
     */
    public static function get_latest_finished(stdClass $instance, $cm, int $userid, ?int $mode = null): ?self {
        global $DB;
        $params = ['whakaharatauid' => $instance->id, 'userid' => $userid, 'state' => self::STATE_FINISHED];
        if ($mode !== null) {
            $params['mode'] = $mode;
        }
        $records = $DB->get_records('whakaharatau_attempt', $params, 'attempt DESC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        return new self(reset($records), $instance, $cm, context_module::instance($cm->id));
    }

    /**
     * Attempt records of a user, oldest first.
     *
     * @param int $whakaharatauid instance id
     * @param int $userid user id
     * @param int|null $mode instance_settings::MODE_* or null for any
     * @param bool $finishedonly only finished attempts
     * @return stdClass[] keyed by id
     */
    public static function get_attempts(
        int $whakaharatauid,
        int $userid,
        ?int $mode = null,
        bool $finishedonly = false
    ): array {
        global $DB;
        $params = ['whakaharatauid' => $whakaharatauid, 'userid' => $userid];
        if ($mode !== null) {
            $params['mode'] = $mode;
        }
        if ($finishedonly) {
            $params['state'] = self::STATE_FINISHED;
        }
        return $DB->get_records('whakaharatau_attempt', $params, 'attempt ASC');
    }

    // Attempt limits.

    /**
     * Test attempts used by a user (finished and in progress).
     *
     * @param int $whakaharatauid instance id
     * @param int $userid user id
     * @return int
     */
    public static function count_test_attempts(int $whakaharatauid, int $userid): int {
        global $DB;
        return $DB->count_records('whakaharatau_attempt', ['whakaharatauid' => $whakaharatauid, 'userid' => $userid,
            'mode' => instance_settings::MODE_TEST]);
    }

    /**
     * Test attempts the user may still start (an attempt in progress counts as used).
     *
     * @param stdClass $instance whakaharatau record
     * @param int $userid user id
     * @return int|null null = unlimited (or Practice mode)
     */
    public static function attempts_left(stdClass $instance, int $userid): ?int {
        if (!instance_settings::is_test($instance) || empty($instance->attempts)) {
            return null;
        }
        return max(0, (int) $instance->attempts - self::count_test_attempts((int) $instance->id, $userid));
    }

    /**
     * Whether the user may start a NEW attempt now (resuming one is always allowed).
     *
     * @param stdClass $instance whakaharatau record
     * @param int $userid user id
     * @return bool
     */
    public static function can_start_new(stdClass $instance, int $userid): bool {
        $left = self::attempts_left($instance, $userid);
        return $left === null || $left > 0;
    }

    // Starting.

    /**
     * Start a new attempt, or resume the unfinished one.
     *
     * - An unfinished attempt of the current mode is resumed unless $forcenew.
     * - With $forcenew ("Start again", "Go again") the unfinished attempt is finished (and scored) first.
     * - An unfinished attempt of the other mode (the teacher switched modes) is finished first.
     * - $missedfrom (Practice only) starts a session whose first round holds the slides missed in that
     *   finished session of the same user; the new session then repeats "Missed only".
     *
     * Triggers \mod_whakaharatau\event\attempt_started for a new attempt.
     *
     * @param stdClass $instance whakaharatau record
     * @param \cm_info|stdClass $cm course module
     * @param int $userid user id
     * @param bool $forcenew finish any unfinished attempt and start a new one
     * @param int $missedfrom id of a finished Practice session to take the missed slides from, or 0
     * @return self
     * @throws moodle_exception notplayable, nomoreattempts, nomissedslides
     */
    public static function start(
        stdClass $instance,
        $cm,
        int $userid,
        bool $forcenew = false,
        int $missedfrom = 0
    ): self {
        global $DB;
        $context = context_module::instance($cm->id);
        if (!slides::is_playable($instance, $context)) {
            throw new moodle_exception('notplayable', 'mod_whakaharatau');
        }

        $existing = self::get_inprogress($instance, $cm, $userid);
        if ($existing) {
            if (!$forcenew && !$missedfrom && (int) $existing->record->mode === (int) $instance->mode) {
                return $existing;
            }
            // Finish it under the attempt's lock, so an answer being stored for it at the same moment cannot
            // write the old "in progress" state back over the finished one.
            $lock = self::lock($existing->get_id());
            try {
                self::get_by_id($existing->get_id())->finish();
            } finally {
                $lock->release();
            }
        }
        if (!self::can_start_new($instance, $userid)) {
            throw new moodle_exception('nomoreattempts', 'mod_whakaharatau');
        }

        $repeat = instance_settings::effective_repeat($instance);
        $slideids = array_map('intval', array_keys(slides::get_slides((int) $instance->id)));
        if ($missedfrom) {
            if (instance_settings::is_test($instance)) {
                throw new moodle_exception('nomissedslides', 'mod_whakaharatau');
            }
            $source = self::get_by_id($missedfrom);
            if (
                (int) $source->record->userid !== $userid || (int) $source->record->whakaharatauid !== (int) $instance->id
                    || !$source->is_finished()
            ) {
                throw new moodle_exception('nomissedslides', 'mod_whakaharatau');
            }
            $slideids = array_values(array_intersect($source->get_missed_slideids(), $slideids));
            if (!$slideids) {
                throw new moodle_exception('nomissedslides', 'mod_whakaharatau');
            }
            $repeat = instance_settings::REPEAT_MISSED;
        }

        $now = time();
        $maxattempt = (int) $DB->get_field_sql('SELECT MAX(attempt) FROM {whakaharatau_attempt}
                                                 WHERE whakaharatauid = ? AND userid = ?', [$instance->id, $userid]);
        $record = (object) [
            'whakaharatauid' => (int) $instance->id,
            'userid' => $userid,
            'mode' => (int) $instance->mode,
            'attempt' => $maxattempt + 1,
            'state' => self::STATE_INPROGRESS,
            'shuffle' => empty($instance->shuffle) ? 0 : 1,
            'repeatmode' => $repeat,
            'round' => 1,
            'slideorder' => '',
            'optionorder' => '',
            'currentslide' => 0,
            'score' => null,
            'maxscore' => null,
            'currentstreak' => 0,
            'beststreak' => 0,
            'timestart' => $now,
            'timefinish' => 0,
            'timemodified' => $now,
        ];
        $attempt = new self($record, $instance, $cm, $context);
        $attempt->build_round($slideids);
        $record->id = $DB->insert_record('whakaharatau_attempt', $record);

        $event = \mod_whakaharatau\event\attempt_started::create([
            'objectid' => $record->id,
            'context' => $context,
            'relateduserid' => $userid,
            'other' => ['whakaharatauid' => (int) $instance->id, 'mode' => (int) $record->mode],
        ]);
        $event->add_record_snapshot('whakaharatau_attempt', $record);
        $event->trigger();

        return $attempt;
    }

    /**
     * Freeze the slide order and option orders of a new round into the record (not saved here).
     *
     * @param int[] $slideids slides of the round, in teacher order
     * @return void
     */
    protected function build_round(array $slideids): void {
        $slideids = array_values(array_map('intval', $slideids));
        if (!empty($this->record->shuffle)) {
            shuffle($slideids);
        }
        $options = slides::get_options_for_instance((int) $this->instance->id);
        $random = (int) $this->instance->optionorder === instance_settings::OPTIONORDER_RANDOM;
        $optionorder = [];
        foreach ($slideids as $slideid) {
            $ids = array_map('intval', array_keys($options[$slideid] ?? []));
            if ($random) {
                shuffle($ids);
            }
            $optionorder[(string) $slideid] = $ids;
        }
        $this->record->slideorder = implode(',', $slideids);
        $this->record->optionorder = json_encode($optionorder);
        $this->record->currentslide = 0;
    }

    // Accessors.

    /**
     * The attempt record.
     *
     * @return stdClass
     */
    public function get_record(): stdClass {
        return $this->record;
    }

    /**
     * Attempt id.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) $this->record->id;
    }

    /**
     * Owner of the attempt.
     *
     * @return int
     */
    public function get_userid(): int {
        return (int) $this->record->userid;
    }

    /**
     * The activity record.
     *
     * @return stdClass
     */
    public function get_instance(): stdClass {
        return $this->instance;
    }

    /**
     * The course module.
     *
     * @return \cm_info|stdClass
     */
    public function get_cm() {
        return $this->cm;
    }

    /**
     * The module context.
     *
     * @return context_module
     */
    public function get_context(): context_module {
        return $this->context;
    }

    /**
     * Whether the attempt is over.
     *
     * @return bool
     */
    public function is_finished(): bool {
        return $this->record->state === self::STATE_FINISHED;
    }

    /**
     * Take the lock that every write to one attempt holds (answering, finishing), so two requests for the same
     * attempt run one after the other. Reload the attempt after taking it: the other request may have changed it.
     *
     * @param int $attemptid attempt id
     * @return \core\lock\lock release it in a finally block
     * @throws moodle_exception locktimeout
     */
    public static function lock(int $attemptid): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('mod_whakaharatau')->get_lock('attempt' . $attemptid, 5);
        if (!$lock) {
            throw new moodle_exception('locktimeout');
        }
        return $lock;
    }

    /**
     * Whether the stored attempt is finished, read from the database rather than this object's copy: a copy loaded
     * before another request finished the attempt still says "in progress".
     *
     * @return bool
     */
    protected function is_finished_in_db(): bool {
        global $DB;
        return $DB->get_field('whakaharatau_attempt', 'state', ['id' => $this->get_id()]) === self::STATE_FINISHED;
    }

    /**
     * Whether this attempt is a Test attempt (by the mode frozen at its start).
     *
     * @return bool
     */
    public function is_test(): bool {
        return (int) $this->record->mode === instance_settings::MODE_TEST;
    }

    /**
     * Current round number (from 1).
     *
     * @return int
     */
    public function get_round(): int {
        return (int) $this->record->round;
    }

    /**
     * Slide ids of the current round in play order; slides deleted since are left out.
     *
     * @return int[]
     */
    public function get_slideorder(): array {
        if ((string) $this->record->slideorder === '') {
            return [];
        }
        $ids = array_map('intval', explode(',', $this->record->slideorder));
        $existing = slides::get_slides((int) $this->instance->id);
        return array_values(array_filter($ids, fn($id) => isset($existing[$id])));
    }

    /**
     * Option ids of a slide in display order for the current round; options deleted since are left out and
     * options added since are appended.
     *
     * @param int $slideid slide id
     * @return int[]
     */
    public function get_optionorder(int $slideid): array {
        $orders = json_decode((string) $this->record->optionorder, true) ?: [];
        $frozen = array_map('intval', $orders[(string) $slideid] ?? []);
        $current = array_map('intval', array_keys(slides::get_options($slideid)));
        $kept = array_values(array_intersect($frozen, $current));
        return array_merge($kept, array_values(array_diff($current, $kept)));
    }

    /**
     * Tries allowed per slide (0 = until correct).
     *
     * @return int
     */
    protected function max_tries(): int {
        return instance_settings::effective_tries($this->instance);
    }

    /**
     * Whether answer feedback is shown during the attempt.
     *
     * @return bool
     */
    public function shows_feedback(): bool {
        return instance_settings::effective_feedback($this->instance) === instance_settings::FEEDBACK_EACH;
    }

    // Responses and slide status.

    /**
     * Responses grouped by round and slide, each list in try order.
     *
     * @return array round => slideid => stdClass[]
     */
    protected function get_responses(): array {
        global $DB;
        if ($this->responses === null) {
            $this->responses = [];
            if (!empty($this->record->id)) {
                $rows = $DB->get_records('whakaharatau_response', ['attemptid' => $this->record->id], 'id ASC');
                foreach ($rows as $row) {
                    $this->responses[(int) $row->round][(int) $row->slideid][] = $row;
                }
            }
        }
        return $this->responses;
    }

    /**
     * Status of one slide in a round, computed from its responses.
     *
     * @param int $slideid slide id
     * @param int|null $round round, default the current one
     * @return array keys: tries (int), chosen (int[] option ids in try order), correct (bool: answered right
     *      in this round), firsttry (bool: right on the first try), finished (bool), triesleft (int|null,
     *      null = until correct)
     */
    public function get_slide_status(int $slideid, ?int $round = null): array {
        $round = $round ?? $this->get_round();
        $rows = $this->get_responses()[$round][$slideid] ?? [];
        $tries = count($rows);
        $correct = false;
        foreach ($rows as $row) {
            if (!empty($row->correct)) {
                $correct = true;
            }
        }
        $max = $this->max_tries();
        return [
            'tries' => $tries,
            'chosen' => array_map(fn($r) => (int) $r->optionid, $rows),
            'correct' => $correct,
            'firsttry' => $tries > 0 && !empty($rows[0]->correct),
            'finished' => $correct || ($max > 0 && $tries >= $max),
            'triesleft' => $max > 0 ? max(0, $max - $tries) : null,
        ];
    }

    /**
     * Position (0-based) of the first unfinished slide of the current round; equals the slide count when
     * every slide of the round is finished.
     *
     * @return int
     */
    public function get_position(): int {
        foreach ($this->get_slideorder() as $i => $slideid) {
            if (!$this->get_slide_status($slideid)['finished']) {
                return $i;
            }
        }
        return count($this->get_slideorder());
    }

    /**
     * Score of one slide in a round (0..1) under the activity's scoring rules.
     *
     * @param int $slideid slide id
     * @param int $round round
     * @return float
     */
    public function get_slide_score(int $slideid, int $round): float {
        $status = $this->get_slide_status($slideid, $round);
        if ((int) $this->instance->scoring === instance_settings::SCORING_COMPLETED) {
            return $status['finished'] ? 1.0 : 0.0;
        }
        if (!$status['correct']) {
            return 0.0;
        }
        if ($status['firsttry']) {
            return 1.0;
        }
        if ((int) $this->instance->slidescore !== instance_settings::SLIDESCORE_PARTIAL) {
            return 0.0;
        }
        $n = count(slides::get_options($slideid));
        if ($n < 2) {
            return 0.0;
        }
        // Right on try k of n options: (n - k) / (n - 1), so the last possible try scores 0.
        return max(0.0, ($n - $status['tries']) / ($n - 1));
    }

    // State for the player.

    /**
     * The attempt's state for the player. Never contains the correct option of an unfinished slide.
     *
     * @return array keys: attemptid, attempt, mode, finished, round, repeatmode, shuffle, feedback (bool),
     *      position (0-based first unfinished slide of the round), slidecount (slides in this round),
     *      totalslides (slides in the activity), roundcomplete (bool: every slide of the round is finished
     *      but the attempt is not; the player should call finish_attempt), slides (list of:
     *      slideid, position, number (1-based), finished, tries, triesleft (int|null), correct (bool|null:
     *      null while unfinished or when feedback is hidden), options (list of: optionid, number (1-based),
     *      state (one of the OPTION_* constants)))
     */
    public function get_state(): array {
        $feedback = $this->shows_feedback();
        $slidestates = [];
        $order = $this->get_slideorder();
        foreach ($order as $i => $slideid) {
            $status = $this->get_slide_status($slideid);
            $correctid = $status['finished'] && $feedback ? slides::get_correct_optionid($slideid) : null;
            $options = [];
            foreach ($this->get_optionorder($slideid) as $j => $optionid) {
                $options[] = [
                    'optionid' => $optionid,
                    'number' => $j + 1,
                    'state' => $this->option_state($optionid, $status, $feedback, $correctid),
                ];
            }
            $slidestates[] = [
                'slideid' => $slideid,
                'position' => $i,
                'number' => $i + 1,
                'finished' => $status['finished'],
                'tries' => $status['tries'],
                'triesleft' => $status['triesleft'],
                'correct' => ($status['finished'] && $feedback) ? $status['correct'] : null,
                'options' => $options,
            ];
        }
        $position = $this->get_position();
        return [
            'attemptid' => $this->get_id(),
            'attempt' => (int) $this->record->attempt,
            'mode' => (int) $this->record->mode,
            'finished' => $this->is_finished(),
            'round' => $this->get_round(),
            'repeatmode' => (int) $this->record->repeatmode,
            'shuffle' => !empty($this->record->shuffle),
            'feedback' => $feedback,
            'position' => $position,
            'slidecount' => count($order),
            'totalslides' => slides::count_slides((int) $this->instance->id),
            'roundcomplete' => !$this->is_finished() && $position >= count($order),
            'slides' => $slidestates,
        ];
    }

    /**
     * Display state of one option.
     *
     * @param int $optionid option id
     * @param array $status result of get_slide_status()
     * @param bool $feedback whether feedback is shown
     * @param int|null $correctid correct option id, known only for finished slides with feedback
     * @return string OPTION_* constant
     */
    protected function option_state(int $optionid, array $status, bool $feedback, ?int $correctid): string {
        $chosen = in_array($optionid, $status['chosen'], true);
        if (!$feedback) {
            return $chosen ? self::OPTION_CHOSEN : self::OPTION_NONE;
        }
        if ($chosen) {
            // A chosen option is correct only if it is the correct one; chosen wrong rows stay wrong.
            $last = $status['chosen'][array_key_last($status['chosen'])];
            return ($status['correct'] && $optionid === $last) ? self::OPTION_CORRECT : self::OPTION_WRONG;
        }
        if ($correctid !== null && $optionid === $correctid && !$status['correct']) {
            return self::OPTION_REVEALED;
        }
        return self::OPTION_NONE;
    }

    /**
     * Media and text of a slide for the player, in this round's option order. Never says which option is
     * correct. Transcripts are included only when the teacher shows them before answering, or once the
     * slide is finished (or the attempt is finished).
     *
     * @param int $slideid slide id (must belong to this activity)
     * @return array keys: slideid, imageurl (string|null), alttext (plain text), decorative (bool),
     *      prompthtml (formatted HTML, '' when empty), promptlang (string|null), promptaudiourl (string|null),
     *      options (list of: optionid, number (1-based), audiourl (string|null), transcript (plain text|null))
     */
    public function get_slide_data(int $slideid): array {
        $slide = slides::get_slide($slideid);
        if ((int) $slide->whakaharatauid !== (int) $this->instance->id) {
            throw new moodle_exception('errorwrongslide', 'mod_whakaharatau');
        }
        $showtranscripts = $this->transcripts_visible($slideid);
        $alloptions = slides::get_options($slideid);
        $options = [];
        foreach ($this->get_optionorder($slideid) as $j => $optionid) {
            $option = $alloptions[$optionid];
            $url = slides::get_file_url($this->context, slides::AREA_AUDIO, $optionid);
            $transcript = trim((string) $option->transcript);
            $options[] = [
                'optionid' => $optionid,
                'number' => $j + 1,
                'audiourl' => $url ? $url->out(false) : null,
                'transcript' => ($showtranscripts && $transcript !== '') ? $transcript : null,
            ];
        }
        $imageurl = slides::get_file_url($this->context, slides::AREA_IMAGE, $slideid);
        $prompturl = slides::get_file_url($this->context, slides::AREA_PROMPT, $slideid);
        $prompt = trim((string) $slide->prompt);
        return [
            'slideid' => $slideid,
            'imageurl' => $imageurl ? $imageurl->out(false) : null,
            'alttext' => empty($slide->decorative) ? (string) $slide->alttext : '',
            'decorative' => !empty($slide->decorative),
            'prompthtml' => $prompt === '' ? '' : format_text(
                $prompt,
                (int) $slide->promptformat,
                ['context' => $this->context]
            ),
            'promptlang' => $slide->promptlang ?: null,
            'promptaudiourl' => $prompturl ? $prompturl->out(false) : null,
            'options' => $options,
        ];
    }

    /**
     * Whether the learner may see a slide's transcripts now.
     *
     * @param int $slideid slide id
     * @param int|null $round round to judge "slide finished" by, default the current one
     * @return bool true if transcripts are shown before answering, the slide is finished in that round, or
     *      the attempt is finished
     */
    private function transcripts_visible(int $slideid, ?int $round = null): bool {
        return !empty($this->instance->transcriptsbefore) || $this->is_finished()
            || $this->get_slide_status($slideid, $round)['finished'];
    }

    /**
     * The transcripts of a slide's options that the learner may see now.
     *
     * Used straight after an answer. When that answer finished the round and a new round was built, the slide
     * is unfinished again in the new round, so the caller passes the round the answer was given in.
     *
     * @param int $slideid slide id
     * @param int|null $round round to judge "slide finished" by, default the current one
     * @return array list of ['optionid' => int, 'transcript' => string], only non-empty visible transcripts
     * @throws moodle_exception errorwrongslide if the slide is not in this activity
     */
    public function get_visible_transcripts(int $slideid, ?int $round = null): array {
        $slide = slides::get_slide($slideid);
        if ((int) $slide->whakaharatauid !== (int) $this->instance->id) {
            throw new moodle_exception('errorwrongslide', 'mod_whakaharatau');
        }
        if (!$this->transcripts_visible($slideid, $round)) {
            return [];
        }
        $list = [];
        foreach (slides::get_options($slideid) as $option) {
            $transcript = trim((string) $option->transcript);
            if ($transcript !== '') {
                $list[] = ['optionid' => (int) $option->id, 'transcript' => $transcript];
            }
        }
        return $list;
    }

    // Answering.

    /**
     * Choose an option on the current slide. The server marks it.
     *
     * Only the first unfinished slide of the current round can be answered, each option at most once.
     * When the slide finishes, the streak is updated; when the round finishes, the next round is built
     * (Repeat All / Missed only) or the attempt is finished (Repeat Off, or nothing missed).
     *
     * @param int $slideid slide id
     * @param int $optionid option id
     * @return array keys: slideid, optionid, feedback (bool), correct (bool|null: null when feedback is
     *      hidden), slidefinished (bool), triesleft (int|null), correctoptionid (int|null: only when the slide
     *      is finished and feedback is shown), roundfinished (bool), newround (int|null: number of the round
     *      just built), attemptfinished (bool), summary (array|null: get_summary() when the attempt finished)
     * @throws moodle_exception attemptfinished, notcurrentslide, errorwrongslide, optionalreadychosen
     */
    public function submit_answer(int $slideid, int $optionid): array {
        global $DB;
        if ($this->is_finished() || $this->is_finished_in_db()) {
            throw new moodle_exception('attemptfinished', 'mod_whakaharatau');
        }
        $order = $this->get_slideorder();
        $position = $this->get_position();
        if (!isset($order[$position]) || $order[$position] !== $slideid) {
            throw new moodle_exception('notcurrentslide', 'mod_whakaharatau');
        }
        $option = slides::get_option($optionid, IGNORE_MISSING);
        if (!$option || (int) $option->slideid !== $slideid) {
            throw new moodle_exception('errorwrongslide', 'mod_whakaharatau');
        }
        $status = $this->get_slide_status($slideid);
        if (in_array($optionid, $status['chosen'], true)) {
            throw new moodle_exception('optionalreadychosen', 'mod_whakaharatau');
        }

        $now = time();
        $iscorrect = !empty($option->iscorrect);
        $response = (object) [
            'attemptid' => $this->get_id(),
            'round' => $this->get_round(),
            'slideid' => $slideid,
            'optionid' => $optionid,
            'correct' => $iscorrect ? 1 : 0,
            'tries' => $status['tries'] + 1,
            'timeanswered' => $now,
        ];
        $response->id = $DB->insert_record('whakaharatau_response', $response);
        $this->responses = null;
        $status = $this->get_slide_status($slideid);

        $roundfinished = false;
        $newround = null;
        $attemptfinished = false;
        $summary = null;
        if ($status['finished']) {
            if ($status['firsttry']) {
                $this->record->currentstreak = (int) $this->record->currentstreak + 1;
                $this->record->beststreak = max((int) $this->record->beststreak, (int) $this->record->currentstreak);
            } else {
                $this->record->currentstreak = 0;
            }
            $this->record->currentslide = $this->get_position();
            if ($this->record->currentslide >= count($order)) {
                $roundfinished = true;
                $newround = $this->advance_round($order);
            }
        }
        $this->record->timemodified = $now;
        $DB->update_record('whakaharatau_attempt', $this->record);
        if ($roundfinished && $newround === null) {
            $summary = $this->finish();
            $attemptfinished = true;
        }
        $this->update_completion();

        $feedback = $this->shows_feedback();
        return [
            'slideid' => $slideid,
            'optionid' => $optionid,
            'feedback' => $feedback,
            'correct' => $feedback ? $iscorrect : null,
            'slidefinished' => $status['finished'],
            'triesleft' => $status['triesleft'],
            'correctoptionid' => ($status['finished'] && $feedback) ? slides::get_correct_optionid($slideid) : null,
            'roundfinished' => $roundfinished,
            'newround' => $newround,
            'attemptfinished' => $attemptfinished,
            'summary' => $summary,
        ];
    }

    /**
     * Build the next round in the record (not saved here), per the frozen repeat mode.
     *
     * @param int[] $order slide ids of the round just finished
     * @return int|null the new round number, or null when the attempt should finish
     */
    protected function advance_round(array $order): ?int {
        switch ((int) $this->record->repeatmode) {
            case instance_settings::REPEAT_ALL:
                $next = array_map('intval', array_keys(slides::get_slides((int) $this->instance->id)));
                break;
            case instance_settings::REPEAT_MISSED:
                $next = [];
                foreach ($order as $slideid) {
                    if (!$this->get_slide_status($slideid)['firsttry']) {
                        $next[] = $slideid;
                    }
                }
                break;
            default:
                $next = [];
        }
        if (!$next || $this->is_test()) {
            return null;
        }
        // Keep the teacher's order as the base; build_round() shuffles if Shuffle is on.
        $teacherorder = array_map('intval', array_keys(slides::get_slides((int) $this->instance->id)));
        $next = array_values(array_intersect($teacherorder, $next));
        $this->record->round = $this->get_round() + 1;
        $this->build_round($next);
        return (int) $this->record->round;
    }

    // Finishing, summary, review.

    /**
     * Finish and score the attempt. Idempotent: a finished attempt just returns its summary.
     *
     * Test attempts push the grade (whakaharatau_update_grades). Triggers
     * \mod_whakaharatau\event\attempt_submitted.
     *
     * @return array get_summary()
     */
    public function finish(): array {
        global $CFG, $DB;
        if (!$this->is_finished() && $this->is_finished_in_db()) {
            // Another request finished it after this copy was loaded: use the stored result, do not score it again.
            $this->record = $DB->get_record('whakaharatau_attempt', ['id' => $this->get_id()], '*', MUST_EXIST);
            $this->responses = null;
        }
        if ($this->is_finished()) {
            return $this->get_summary();
        }
        [$score, $maxscore] = $this->compute_score();
        $now = time();
        $this->record->state = self::STATE_FINISHED;
        $this->record->score = $score;
        $this->record->maxscore = $maxscore;
        $this->record->timefinish = $now;
        $this->record->timemodified = $now;
        $DB->update_record('whakaharatau_attempt', $this->record);

        $event = \mod_whakaharatau\event\attempt_submitted::create([
            'objectid' => $this->get_id(),
            'context' => $this->context,
            'relateduserid' => $this->get_userid(),
            'other' => ['whakaharatauid' => (int) $this->instance->id, 'mode' => (int) $this->record->mode],
        ]);
        $event->add_record_snapshot('whakaharatau_attempt', $this->record);
        $event->trigger();

        if ($this->is_test() && instance_settings::is_test($this->instance)) {
            require_once($CFG->dirroot . '/mod/whakaharatau/lib.php');
            whakaharatau_update_grades($this->instance, $this->get_userid());
        }
        $this->update_completion();
        return $this->get_summary();
    }

    /**
     * Raw score and maximum of the attempt.
     *
     * Test: round 1, one point per slide (see get_slide_score()); unanswered slides score 0.
     * Practice: slides right first time / slides finished, over all rounds.
     *
     * @return float[] [score, maxscore]
     */
    protected function compute_score(): array {
        if ($this->is_test()) {
            $slideids = $this->round_slideids(1);
            $score = 0.0;
            foreach ($slideids as $slideid) {
                $score += $this->get_slide_score($slideid, 1);
            }
            return [round($score, 5), (float) count($slideids)];
        }
        $played = 0;
        $firsttry = 0;
        foreach ($this->get_responses() as $round => $byslide) {
            foreach (array_keys($byslide) as $slideid) {
                $status = $this->get_slide_status((int) $slideid, (int) $round);
                if ($status['finished']) {
                    $played++;
                    $firsttry += $status['firsttry'] ? 1 : 0;
                }
            }
        }
        return [(float) $firsttry, (float) $played];
    }

    /**
     * Slide ids of a round: the frozen order for the current round, else the slides answered in it.
     *
     * @param int $round round
     * @return int[]
     */
    protected function round_slideids(int $round): array {
        if ($round === $this->get_round()) {
            return $this->get_slideorder();
        }
        return array_map('intval', array_keys($this->get_responses()[$round] ?? []));
    }

    /**
     * Slides missed in this session: for each slide, its latest finished appearance was not right first time.
     *
     * @return int[] slide ids in teacher order
     */
    public function get_missed_slideids(): array {
        $latest = [];
        $responses = $this->get_responses();
        ksort($responses);
        foreach ($responses as $round => $byslide) {
            foreach (array_keys($byslide) as $slideid) {
                $status = $this->get_slide_status((int) $slideid, (int) $round);
                if ($status['finished']) {
                    $latest[(int) $slideid] = $status['firsttry'];
                }
            }
        }
        $missed = [];
        foreach (array_keys(slides::get_slides((int) $this->instance->id)) as $slideid) {
            if (isset($latest[(int) $slideid]) && !$latest[(int) $slideid]) {
                $missed[] = (int) $slideid;
            }
        }
        return $missed;
    }

    /**
     * Data for the end screen (also usable while the attempt is in progress, e.g. the Repeat All exit X).
     *
     * @return array keys: attemptid, attempt, mode, finished, score (float), maxscore (float), percent
     *      (float|null, Test only), grade (float|null, Test only, out of maxgrade), maxgrade (int),
     *      slides (list of: slideid, number, finished, correct, firsttry, tries, score; Test: round 1 in play
     *      order; Practice: the latest appearance of each slide played, teacher order), slidesplayed (int),
     *      firsttrycorrect (int), rounds (int), beststreak (int), missedcount (int, Practice),
     *      attemptsleft (int|null), canretry (bool), canreview (bool)
     */
    public function get_summary(): array {
        if ($this->is_finished() && $this->record->maxscore !== null) {
            $score = (float) $this->record->score;
            $maxscore = (float) $this->record->maxscore;
        } else {
            [$score, $maxscore] = $this->compute_score();
        }

        $slidelist = [];
        $played = 0;
        $firsttrycount = 0;
        $rounds = 0;
        foreach ($this->get_responses() as $byslide) {
            if ($byslide) {
                $rounds++;
            }
        }
        $responses = $this->get_responses();
        ksort($responses);
        foreach ($responses as $round => $byslide) {
            foreach (array_keys($byslide) as $slideid) {
                $status = $this->get_slide_status((int) $slideid, (int) $round);
                if ($status['finished']) {
                    $played++;
                    $firsttrycount += $status['firsttry'] ? 1 : 0;
                }
            }
        }

        if ($this->is_test()) {
            foreach ($this->round_slideids(1) as $i => $slideid) {
                $status = $this->get_slide_status($slideid, 1);
                $slidelist[] = [
                    'slideid' => $slideid,
                    'number' => $i + 1,
                    'finished' => $status['finished'],
                    'correct' => $status['correct'],
                    'firsttry' => $status['firsttry'],
                    'tries' => $status['tries'],
                    'score' => $this->get_slide_score($slideid, 1),
                ];
            }
        } else {
            $latest = [];
            foreach ($responses as $round => $byslide) {
                foreach (array_keys($byslide) as $slideid) {
                    $latest[(int) $slideid] = (int) $round;
                }
            }
            $number = 0;
            foreach (array_keys(slides::get_slides((int) $this->instance->id)) as $slideid) {
                if (!isset($latest[(int) $slideid])) {
                    continue;
                }
                $status = $this->get_slide_status((int) $slideid, $latest[(int) $slideid]);
                $slidelist[] = [
                    'slideid' => (int) $slideid,
                    'number' => ++$number,
                    'finished' => $status['finished'],
                    'correct' => $status['correct'],
                    'firsttry' => $status['firsttry'],
                    'tries' => $status['tries'],
                    'score' => $status['firsttry'] ? 1.0 : 0.0,
                ];
            }
        }

        $istest = $this->is_test();
        $percent = ($istest && $maxscore > 0) ? round($score / $maxscore * 100, 2) : null;
        $maxgrade = (int) $this->instance->grade;
        $left = self::attempts_left($this->instance, $this->get_userid());
        return [
            'attemptid' => $this->get_id(),
            'attempt' => (int) $this->record->attempt,
            'mode' => (int) $this->record->mode,
            'finished' => $this->is_finished(),
            'score' => $score,
            'maxscore' => $maxscore,
            'percent' => $percent,
            'grade' => ($istest && $maxscore > 0 && $maxgrade > 0) ? round($score / $maxscore * $maxgrade, 5) : null,
            'maxgrade' => $maxgrade,
            'slides' => $slidelist,
            'slidesplayed' => $played,
            'firsttrycorrect' => $firsttrycount,
            'rounds' => $rounds,
            'beststreak' => (int) $this->record->beststreak,
            'missedcount' => $istest ? 0 : count($this->get_missed_slideids()),
            'attemptsleft' => $left,
            'canretry' => $this->is_finished() && self::can_start_new($this->instance, $this->get_userid()),
            'canreview' => $this->can_review(),
        ];
    }

    /**
     * Whether the answers of this attempt may be reviewed by its owner.
     *
     * @return bool
     */
    public function can_review(): bool {
        if (!$this->is_finished()) {
            return false;
        }
        return !$this->is_test() || !empty($this->instance->allowreview);
    }

    /**
     * Answers of a finished attempt with the correct options, for the review screen.
     *
     * Callers decide who may see it: the owner when can_review(), or a user with mod/whakaharatau:viewreports.
     *
     * @return array list of: slideid, number, round, chosen (int[] option ids in try order), correctoptionid
     *      (int|null; null for a Test slide never answered while the learner may still start an attempt),
     *      correct (bool), firsttry (bool), tries (int), score (float), slide (get_slide_data())
     * @throws moodle_exception attemptnotfinished
     */
    public function get_review(): array {
        if (!$this->is_finished()) {
            throw new moodle_exception('attemptnotfinished', 'mod_whakaharatau');
        }
        $existing = slides::get_slides((int) $this->instance->id);
        $review = [];
        $responses = $this->get_responses();
        ksort($responses);
        $number = 0;
        // A Test can be finished early: the key of a slide the learner never answered stays hidden while
        // they may still start another attempt, so finishing at once cannot be used to read the answers.
        $nomoreattempts = $this->is_test() && self::attempts_left($this->instance, $this->get_userid()) === 0;
        // A Test reviews every slide of its single round, answered or not; Practice reviews what was answered.
        $rounds = $this->is_test() ? [1 => $this->round_slideids(1)] : array_map('array_keys', $responses);
        foreach ($rounds as $round => $ids) {
            foreach ($ids as $slideid) {
                $slideid = (int) $slideid;
                if (!isset($existing[$slideid])) {
                    continue;
                }
                $status = $this->get_slide_status($slideid, (int) $round);
                $reveal = !$this->is_test() || $status['tries'] > 0 || $nomoreattempts;
                $review[] = [
                    'slideid' => $slideid,
                    'number' => ++$number,
                    'round' => (int) $round,
                    'chosen' => $status['chosen'],
                    'correctoptionid' => $reveal ? slides::get_correct_optionid($slideid) : null,
                    'correct' => $status['correct'],
                    'firsttry' => $status['firsttry'],
                    'tries' => $status['tries'],
                    'score' => $this->is_test() ? $this->get_slide_score($slideid, (int) $round) :
                        ($status['firsttry'] ? 1.0 : 0.0),
                    'slide' => $this->get_slide_data($slideid),
                ];
            }
        }
        return $review;
    }

    /**
     * Ask completion to re-check the custom rule for the owner.
     *
     * @return void
     */
    protected function update_completion(): void {
        global $CFG;
        if (empty($this->instance->completionallslides)) {
            return;
        }
        require_once($CFG->libdir . '/completionlib.php');
        $course = get_course($this->cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($this->cm)) {
            $completion->update_state($this->cm, COMPLETION_COMPLETE, $this->get_userid());
        }
    }

    // Grades.

    /**
     * Grades of users from their finished Test attempts, aggregated by the grading method.
     *
     * @param stdClass $instance whakaharatau record
     * @param int $userid one user, or 0 for all
     * @return array userid => (object) [userid, rawgrade (0..instance->grade), dategraded]
     */
    public static function get_user_grades(stdClass $instance, int $userid = 0): array {
        global $DB;
        $params = ['wid' => $instance->id, 'mode' => instance_settings::MODE_TEST, 'state' => self::STATE_FINISHED];
        $where = 'whakaharatauid = :wid AND mode = :mode AND state = :state AND maxscore > 0';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $rows = $DB->get_records_select(
            'whakaharatau_attempt',
            $where,
            $params,
            'userid ASC, attempt ASC',
            'id, userid, attempt, score, maxscore, timefinish'
        );
        $byuser = [];
        foreach ($rows as $row) {
            $byuser[(int) $row->userid][] = $row;
        }
        $grades = [];
        foreach ($byuser as $uid => $attempts) {
            $fraction = self::aggregate($attempts, (int) $instance->grademethod);
            $grades[$uid] = (object) [
                'userid' => $uid,
                'rawgrade' => round($fraction * (float) $instance->grade, 5),
                'dategraded' => (int) max(array_map(fn($a) => (int) $a->timefinish, $attempts)),
            ];
        }
        return $grades;
    }

    /**
     * Aggregate attempt fractions by a grading method.
     *
     * @param stdClass[] $attempts attempt rows (score, maxscore) in attempt order, none empty
     * @param int $method instance_settings::GRADE_*
     * @return float 0..1
     */
    public static function aggregate(array $attempts, int $method): float {
        $fractions = array_map(fn($a) => (float) $a->score / (float) $a->maxscore, array_values($attempts));
        if (!$fractions) {
            return 0.0;
        }
        switch ($method) {
            case instance_settings::GRADE_AVERAGE:
                return array_sum($fractions) / count($fractions);
            case instance_settings::GRADE_FIRST:
                return $fractions[0];
            case instance_settings::GRADE_LAST:
                return $fractions[count($fractions) - 1];
            default:
                return max($fractions);
        }
    }

    // Deleting and completion queries.

    /**
     * Delete one attempt and its responses. The caller updates grades.
     *
     * @param int $attemptid attempt id
     * @return void
     */
    public static function delete_attempt(int $attemptid): void {
        global $DB;
        $DB->delete_records('whakaharatau_response', ['attemptid' => $attemptid]);
        $DB->delete_records('whakaharatau_attempt', ['id' => $attemptid]);
    }

    /**
     * Delete all attempts of an activity (optionally of one user) and their responses. The caller updates grades.
     *
     * @param int $whakaharatauid instance id
     * @param int $userid one user, or 0 for all
     * @return void
     */
    public static function delete_all_attempts(int $whakaharatauid, int $userid = 0): void {
        global $DB;
        $params = ['whakaharatauid' => $whakaharatauid];
        if ($userid) {
            $params['userid'] = $userid;
        }
        $ids = $DB->get_fieldset_select(
            'whakaharatau_attempt',
            'id',
            'whakaharatauid = :whakaharatauid' . ($userid ? ' AND userid = :userid' : ''),
            $params
        );
        if ($ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('whakaharatau_response', "attemptid $insql", $inparams);
            $DB->delete_records_select('whakaharatau_attempt', "id $insql", $inparams);
        }
    }

    /**
     * Whether the user has answered every slide of the activity at least once (any attempt, any mode).
     * Used by the custom completion rule completionallslides.
     *
     * @param int $whakaharatauid instance id
     * @param int $userid user id
     * @return bool false when the activity has no slides
     */
    public static function has_answered_all_slides(int $whakaharatauid, int $userid): bool {
        global $DB;
        $total = slides::count_slides($whakaharatauid);
        if (!$total) {
            return false;
        }
        $sql = "SELECT COUNT(DISTINCT r.slideid)
                  FROM {whakaharatau_response} r
                  JOIN {whakaharatau_attempt} a ON a.id = r.attemptid
                  JOIN {whakaharatau_slide} s ON s.id = r.slideid AND s.whakaharatauid = a.whakaharatauid
                 WHERE a.whakaharatauid = :wid AND a.userid = :userid";
        $answered = (int) $DB->count_records_sql($sql, ['wid' => $whakaharatauid, 'userid' => $userid]);
        return $answered >= $total;
    }
}
