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

use context_module;
use mod_whakaharatau\local\slides;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * The slide list of the Edit slides page (template mod_whakaharatau/edit_slides).
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_slides implements \renderable, \templatable {
    /** @var stdClass whakaharatau record */
    protected $instance;
    /** @var int course module id */
    protected $cmid;
    /** @var context_module module context */
    protected $context;

    /**
     * Constructor.
     *
     * @param stdClass $instance whakaharatau record
     * @param int $cmid course module id
     * @param context_module $context module context
     */
    public function __construct(stdClass $instance, int $cmid, context_module $context) {
        $this->instance = $instance;
        $this->cmid = $cmid;
        $this->context = $context;
    }

    /**
     * Template context. Text is plain here; the template escapes it.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $slides = slides::get_slides((int) $this->instance->id);
        $options = slides::get_options_for_instance((int) $this->instance->id);
        $problems = slides::get_problems($this->context, (int) $this->instance->id);

        $items = [];
        $number = 0;
        foreach ($slides as $slide) {
            $number++;
            $slideid = (int) $slide->id;
            $imageurl = slides::get_file_url($this->context, slides::AREA_IMAGE, $slideid);
            $slideoptions = $options[$slideid] ?? [];
            $correctnumber = 0;
            $n = 0;
            foreach ($slideoptions as $option) {
                $n++;
                if (!empty($option->iscorrect) && !$correctnumber) {
                    $correctnumber = $n;
                }
            }
            $items[] = [
                'slideid' => $slideid,
                'number' => $number,
                'title' => get_string('slidenumber', 'mod_whakaharatau', $number),
                'hasimage' => $imageurl !== null,
                'imageurl' => $imageurl ? $imageurl->out(false) : '',
                'alttext' => $slide->decorative ? '' : (string) $slide->alttext,
                'decorative' => !empty($slide->decorative),
                'prompt' => shorten_text((string) $slide->prompt, 80),
                'promptlang' => (string) ($slide->promptlang ?? ''),
                'optioncount' => get_string('optioncount', 'mod_whakaharatau', count($slideoptions)),
                'correct' => $correctnumber ? get_string('correctisoption', 'mod_whakaharatau', $correctnumber) : '',
                'hasproblems' => !empty($problems[$slideid]),
                'problems' => array_map(
                    fn($code) => ['text' => get_string($code, 'mod_whakaharatau')],
                    $problems[$slideid] ?? []
                ),
                'editurl' => (new moodle_url(
                    '/mod/whakaharatau/edit.php',
                    ['id' => $this->cmid, 'action' => 'edit', 'slideid' => $slideid]
                ))->out(false),
                'deleteurl' => (new moodle_url(
                    '/mod/whakaharatau/edit.php',
                    ['id' => $this->cmid, 'action' => 'delete', 'slideid' => $slideid]
                ))->out(false),
                'movetitle' => get_string('moveslide', 'mod_whakaharatau', $number),
            ];
        }

        $playable = $items && !$problems;
        return [
            'cmid' => $this->cmid,
            'hasslides' => !empty($items),
            'slides' => $items,
            'slidecount' => count($items),
            'playable' => $playable,
            'notplayable' => !$playable,
            'problemcount' => count($problems),
            'oneproblem' => count($problems) === 1,
            'addurl' => (new moodle_url('/mod/whakaharatau/edit.php', ['id' => $this->cmid, 'action' => 'edit']))->out(false),
            'importurl' => (new moodle_url('/mod/whakaharatau/import.php', ['id' => $this->cmid]))->out(false),
            'exporturl' => (new moodle_url(
                '/mod/whakaharatau/export.php',
                ['id' => $this->cmid, 'sesskey' => sesskey()]
            ))->out(false),
            'viewurl' => (new moodle_url('/mod/whakaharatau/view.php', ['id' => $this->cmid]))->out(false),
        ];
    }
}
