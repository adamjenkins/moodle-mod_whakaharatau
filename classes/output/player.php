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

namespace mod_whakaharatau\output;

use cm_info;
use context_module;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use mod_whakaharatau\local\attempt;
use mod_whakaharatau\local\instance_settings;
use mod_whakaharatau\local\slides;
use moodle_url;
use stdClass;

/**
 * The player shell with its start screen, for view.php and for the course page.
 *
 * The JavaScript (mod_whakaharatau/player) takes over from the start screen. Every DOM id is scoped by the
 * course module id, so several players can share a course page.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class player implements renderable, templatable {
    /** @var stdClass whakaharatau record */
    protected $instance;
    /** @var cm_info course module */
    protected $cm;
    /** @var bool whether the player is shown inline on the course page */
    protected $inline;

    /**
     * Constructor.
     *
     * @param stdClass $instance whakaharatau record
     * @param cm_info $cm course module
     * @param bool $inline shown on the course page (true) or on view.php (false)
     */
    public function __construct(stdClass $instance, cm_info $cm, bool $inline) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->inline = $inline;
    }

    /**
     * Data for the template mod_whakaharatau/player.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $USER;
        $instance = $this->instance;
        $cm = $this->cm;
        $context = context_module::instance($cm->id);
        $userid = (int) $USER->id;
        $istest = instance_settings::is_test($instance);

        $canmanage = has_capability('mod/whakaharatau:manage', $context);
        $canattempt = !isguestuser() && isloggedin()
            && has_any_capability(['mod/whakaharatau:attempt', 'mod/whakaharatau:manage'], $context);
        $playable = slides::is_playable($instance, $context);
        $slidecount = slides::count_slides((int) $instance->id);

        $inprogress = null;
        $latest = null;
        $canstartnew = false;
        if ($canattempt) {
            $inprogress = attempt::get_inprogress($instance, $cm, $userid);
            if ($inprogress && (int) $inprogress->get_record()->mode !== (int) $instance->mode) {
                // An attempt of the other mode (the teacher switched modes) is finished when the learner starts.
                $inprogress = null;
            }
            $latest = attempt::get_latest_finished($instance, $cm, $userid, (int) $instance->mode);
            $canstartnew = attempt::can_start_new($instance, $userid);
        }
        $hasinprogress = $inprogress !== null;

        // Why someone who can view cannot take part: not logged in, a guest or not enrolled (they can enrol or log
        // in), or enrolled in a role without the attempt capability, such as a non-editing teacher.
        $cannotattempttext = '';
        if (!$canattempt && $playable) {
            $outsider = !isloggedin() || isguestuser() || !is_enrolled($context->get_course_context(), $USER, '', true);
            $cannotattempttext = get_string($outsider ? 'cannotattempt' : 'cannotattemptrole', 'mod_whakaharatau');
        }

        $courseurl = new moodle_url('/course/view.php', ['id' => $cm->course], 'module-' . $cm->id);
        $data = [
            'cmid' => (int) $cm->id,
            'idprefix' => 'mod-whakaharatau-' . (int) $cm->id,
            'inline' => $this->inline,
            'inlineflag' => $this->inline ? 1 : 0,
            'name' => format_string($instance->name, true, ['context' => $context]),
            'introhtml' => '',
            'cover' => $this->get_cover_url($context),
            'factstext' => get_string('startfacts', 'mod_whakaharatau', (object) [
                'slides' => get_string(
                    $slidecount === 1 ? 'startslidecountone' : 'startslidecount',
                    'mod_whakaharatau',
                    $slidecount
                ),
                'mode' => $this->get_mode_text($userid, $canattempt),
            ]),
            'playmodetext' => instance_settings::get_playmode_description($instance),
            'previoustext' => $canattempt ? $this->get_previous_text($userid) : '',
            'courseurl' => $courseurl->out(false),
            'showprogress' => empty($instance->showprogress) ? 0 : 1,
            'autoplay' => empty($instance->autoplay) ? 0 : 1,
            'mode' => (int) $instance->mode,
            'repeatmode' => instance_settings::effective_repeat($instance),
            'istest' => $istest,
            'playable' => $playable,
            'canattempt' => $canattempt,
            'hasinprogress' => $hasinprogress ? 1 : 0,
            'canstartnew' => $canstartnew ? 1 : 0,
            'attemptsleft' => $canattempt ? (attempt::attempts_left($instance, $userid) ?? -1) : -1,
            'showstart' => $canattempt && $playable && !$hasinprogress && $canstartnew,
            'showcontinue' => $canattempt && $playable && $hasinprogress,
            'showstartagain' => $canattempt && $playable && $hasinprogress && $canstartnew,
            'noattemptsleft' => $canattempt && $playable && !$hasinprogress && !$canstartnew,
            'cannotattempt' => !$canattempt && $playable,
            'cannotattempttext' => $cannotattempttext,
            'notplayable' => !$playable,
            'editurl' => $canmanage ? (new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]))->out(false) : '',
            'lastattemptid' => $latest ? $latest->get_id() : 0,
        ];
        if (!$this->inline && trim((string) $instance->intro) !== '') {
            $data['introhtml'] = format_module_intro('whakaharatau', $instance, $cm->id);
        }
        return $data;
    }

    /**
     * URL of the start screen's image: the cover image, else the first slide's image.
     *
     * @param context_module $context module context
     * @return string empty when there is none
     */
    protected function get_cover_url(context_module $context): string {
        $url = slides::get_file_url($context, slides::AREA_COVER, 0);
        if (!$url) {
            $first = slides::get_slides((int) $this->instance->id);
            if ($first) {
                $url = slides::get_file_url($context, slides::AREA_IMAGE, (int) reset($first)->id);
            }
        }
        return $url ? $url->out(false) : '';
    }

    /**
     * "Practice", "Test" or "Test, 2 attempts left" (the count only for someone who can take the test).
     *
     * @param int $userid user id
     * @param bool $canattempt whether the user can attempt the activity
     * @return string plain text
     */
    protected function get_mode_text(int $userid, bool $canattempt): string {
        if (!instance_settings::is_test($this->instance)) {
            return get_string('modepractice', 'mod_whakaharatau');
        }
        $left = $canattempt ? attempt::attempts_left($this->instance, $userid) : null;
        if ($left === null) {
            return get_string('modetest', 'mod_whakaharatau');
        }
        if ($left === 1) {
            return get_string('starttestattemptleft', 'mod_whakaharatau');
        }
        return get_string('starttestattemptsleft', 'mod_whakaharatau', $left);
    }

    /**
     * The best Test score so far, or the result of the last Practice session.
     *
     * @param int $userid user id
     * @return string plain text, empty when there was no earlier attempt
     */
    protected function get_previous_text(int $userid): string {
        $instance = $this->instance;
        if (instance_settings::is_test($instance)) {
            $best = null;
            foreach (attempt::get_attempts((int) $instance->id, $userid, instance_settings::MODE_TEST, true) as $record) {
                if ((float) $record->maxscore > 0) {
                    $percent = (float) $record->score / (float) $record->maxscore * 100;
                    $best = $best === null ? $percent : max($best, $percent);
                }
            }
            return $best === null ? '' : get_string('startbestscore', 'mod_whakaharatau', format_float($best, 0));
        }
        $latest = attempt::get_latest_finished($instance, $this->cm, $userid, instance_settings::MODE_PRACTICE);
        if (!$latest) {
            return '';
        }
        $record = $latest->get_record();
        return get_string('startlastsession', 'mod_whakaharatau', (object) [
            'right' => (int) round((float) $record->score),
            'played' => (int) round((float) $record->maxscore),
        ]);
    }
}
