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

use mod_whakaharatau\form\import_form;
use mod_whakaharatau\local\importer;
use moodle_url;
use renderer_base;

/**
 * Import step 2: the review table (template mod_whakaharatau/import_review).
 *
 * Thumbnails and clips are served from the teacher's own stage draft area (draftfile.php). The form posts
 * back to import.php with step=confirm; nothing is written to the activity before that.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_review implements \renderable, \templatable {
    /** @var int course module id */
    protected $cmid;
    /** @var int stage draft item id */
    protected $stageid;
    /** @var string import mode (import_form::MODE_*) */
    protected $mode;
    /** @var array import plan */
    protected $plan;
    /** @var array files rejected while staging: [['name', 'reason']] */
    protected $rejected;
    /** @var string[] lang keys of problems with the whole upload */
    protected $errors;
    /** @var int number of existing slides */
    protected $existing;

    /**
     * Constructor.
     *
     * @param int $cmid course module id
     * @param int $stageid stage draft item id
     * @param string $mode import_form::MODE_APPEND or MODE_REPLACE
     * @param array $plan plan from importer::build_plan()
     * @param array $rejected rejected files from importer::stage()
     * @param string[] $errors upload problems from importer::stage()
     * @param int $existing number of slides the activity has now
     */
    public function __construct(
        int $cmid,
        int $stageid,
        string $mode,
        array $plan,
        array $rejected,
        array $errors,
        int $existing
    ) {
        $this->cmid = $cmid;
        $this->stageid = $stageid;
        $this->mode = $mode;
        $this->plan = $plan;
        $this->rejected = $rejected;
        $this->errors = $errors;
        $this->existing = $existing;
    }

    /**
     * Template context. Text is plain; the template escapes it.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $files = importer::get_staged_files($this->stageid);
        $url = function (?string $relpath) use ($files): string {
            if ($relpath === null || !isset($files[$relpath])) {
                return '';
            }
            $file = $files[$relpath];
            return moodle_url::make_draftfile_url($this->stageid, $file->get_filepath(), $file->get_filename())->out(false);
        };

        $slides = [];
        foreach ($this->plan['slides'] as $index => $slide) {
            $number = $index + 1;
            $options = [];
            foreach ($slide['options'] as $o => $option) {
                $options[] = [
                    'index' => $o,
                    'slideindex' => $index,
                    'number' => $o + 1,
                    'name' => basename($option['audio']),
                    'audiourl' => $url($option['audio']),
                    'transcript' => $option['transcript'],
                    'checked' => $slide['correct'] === $o,
                    'playlabel' => get_string(
                        'importplayclip',
                        'mod_whakaharatau',
                        (object) ['slide' => $number, 'name' => basename($option['audio'])]
                    ),
                ];
            }
            $slides[] = [
                'index' => $index,
                'number' => $number,
                'title' => get_string('slidenumber', 'mod_whakaharatau', $number),
                'hasimage' => $slide['image'] !== null,
                'imageurl' => $url($slide['image']),
                'imagename' => $slide['image'] !== null ? basename($slide['image']) : '',
                'alttext' => $slide['alttext'],
                'missingalt' => !$slide['decorative'] && trim($slide['alttext']) === '',
                'decorative' => !empty($slide['decorative']),
                'prompt' => $slide['prompt'],
                'promptlang' => $slide['promptlang'],
                'haspromptaudio' => $slide['promptaudio'] !== null,
                'promptaudiourl' => $url($slide['promptaudio']),
                'promptaudioname' => $slide['promptaudio'] !== null ? basename($slide['promptaudio']) : '',
                'options' => $options,
                'hasoptions' => !empty($options),
                'nocorrect' => $slide['correct'] === null,
                'warnings' => array_map(
                    fn($w) => ['text' => get_string($w['key'], 'mod_whakaharatau', $w['a'])],
                    $slide['warnings']
                ),
                'haswarnings' => !empty($slide['warnings']),
            ];
        }

        $replace = $this->mode === import_form::MODE_REPLACE;
        $limits = (object) ['files' => importer::MAX_FILES, 'size' => display_size(importer::MAX_TOTAL_BYTES)];
        return [
            'cmid' => $this->cmid,
            'actionurl' => (new moodle_url('/mod/whakaharatau/import.php'))->out(false),
            'sesskey' => sesskey(),
            'stageid' => $this->stageid,
            'mode' => $this->mode,
            'planhash' => importer::plan_hash($this->plan),
            'source' => get_string($this->plan['source'] === importer::SOURCE_MANIFEST ? 'importsourcemanifest'
                : 'importsourcefilenames', 'mod_whakaharatau'),
            'modetext' => $replace ? get_string('importreplacewarning', 'mod_whakaharatau', $this->existing)
                : get_string('importappendnote', 'mod_whakaharatau', $this->existing),
            'replace' => $replace && $this->existing > 0,
            'hasslides' => !empty($slides),
            'slides' => $slides,
            'slidecount' => count($slides),
            'planwarnings' => array_map(
                fn($w) => ['text' => get_string($w['key'], 'mod_whakaharatau', $w['a'])],
                $this->plan['warnings']
            ),
            'errors' => array_map(fn($key) => ['text' => get_string($key, 'mod_whakaharatau', $limits)], $this->errors),
            'unmatched' => array_map(fn($name) => ['name' => $name], $this->plan['unmatched']),
            'hasunmatched' => !empty($this->plan['unmatched']),
            'rejected' => array_map(
                fn($r) => ['name' => $r['name'], 'reason' => get_string($r['reason'], 'mod_whakaharatau')],
                $this->rejected
            ),
            'hasrejected' => !empty($this->rejected),
        ];
    }
}
