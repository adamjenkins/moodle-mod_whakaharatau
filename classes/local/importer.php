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
use context_user;
use moodle_exception;
use stdClass;
use stored_file;

/**
 * Bulk import of slides from a ZIP or many loose images and audio files.
 *
 * The import runs in three steps, and nothing is written to the activity before the last:
 *
 * 1. {@see self::stage()} copies the uploaded files (unpacking ZIPs, sanitising paths, checking caps and
 *    content types) into a fresh draft area of the current user, the "stage".
 * 2. {@see self::build_plan()} reads the stage and builds a plan: from `slides.csv` when there is one
 *    ({@see self::parse_manifest()}), otherwise by file names ({@see self::group_by_filenames()}). The plan
 *    is a pure function of the stage, so the review page and the confirm request build the same plan.
 * 3. {@see self::apply()} creates the slides through {@see slides}, with the teacher's review choices,
 *    and deletes the stage.
 *
 * File-name convention (no manifest): `01.jpg` is a slide; `01-a.mp3`, `01-b.mp3` ... are its options in
 * natural order of the part after the image name and a separator (`-`, `_`, space or `.`); the correct clip
 * ends in `-correct` or `_x` before the extension (`01-b-correct.mp3`, `01-b_x.mp3`); `01-prompt.mp3` is the
 * slide's prompt audio. Files in sub-folders group only with files in the same folder.
 *
 * Manifest (`slides.csv`, first row = column names, comma, semicolon or tab separated): `image` (required),
 * `alt`, `decorative`, `prompt`, `promptlang`, `promptaudio`, `correct` (the number N of the correct `audioN`
 * column), `audio1` ... `audio6`, `transcript1` ... `transcript6`. Paths are relative to the manifest.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class importer {
    /** @var string File name of the manifest (case-insensitive). */
    const MANIFEST = 'slides.csv';
    /** @var int Most files one import may stage (after unpacking). */
    const MAX_FILES = 700;
    /** @var int Most slides one import may create. */
    const MAX_SLIDES = 200;
    /** @var int Most bytes all staged files may take together (after unpacking). */
    const MAX_TOTAL_BYTES = 536870912;
    /** @var int Largest accepted manifest. */
    const MAX_MANIFEST_BYTES = 1048576;
    /** @var string Plan source: built from slides.csv. */
    const SOURCE_MANIFEST = 'manifest';
    /** @var string Plan source: built from file names. */
    const SOURCE_FILENAMES = 'filenames';
    /** @var string Kind of a staged file: image. */
    const KIND_IMAGE = 'image';
    /** @var string Kind of a staged file: audio. */
    const KIND_AUDIO = 'audio';
    /** @var string Kind of a staged file: the manifest. */
    const KIND_MANIFEST = 'manifest';
    /** @var string[] Suffixes (before the extension) that mark the correct clip, lower case. */
    const CORRECT_MARKERS = ['-correct', '_correct', '_x'];
    /** @var string Option label that makes a clip the slide's prompt audio. */
    const PROMPT_LABEL = 'prompt';

    // Step 1: staging.

    /**
     * Copy the files of an upload draft area into a new stage draft area of the current user.
     *
     * ZIPs are unpacked (their listing is checked against the caps before anything is extracted). Every path
     * is sanitised: no empty, "." or ".." parts, no hidden files, no `__MACOSX` folders. Each file must be an
     * accepted image or audio type by content, or the manifest. The upload area is deleted afterwards.
     *
     * @param int $uploaddraftid draft item id of the import form's file manager
     * @param int $maxbytes largest accepted single file (0 = no limit beyond the caps)
     * @return array ['draftitemid' => stage draft item id, 'rejected' => [['name' => string, 'reason' => lang key]],
     *      'errors' => [lang keys of problems with the whole upload]]
     */
    public static function stage(int $uploaddraftid, int $maxbytes = 0): array {
        global $USER;
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $stageid = file_get_unused_draft_itemid();
        $state = (object) [
            'stageid' => $stageid,
            'usercontextid' => $usercontext->id,
            'maxbytes' => $maxbytes,
            'count' => 0,
            'bytes' => 0,
            'seen' => [],
            'rejected' => [],
            'errors' => [],
        ];
        $packer = get_file_packer('application/zip');

        $uploads = $fs->get_area_files($usercontext->id, 'user', 'draft', $uploaddraftid, 'filepath, filename', false);
        foreach ($uploads as $upload) {
            if (self::is_zip($upload)) {
                self::stage_zip($upload, $packer, $state);
                continue;
            }
            self::stage_file($upload, ltrim($upload->get_filepath(), '/') . $upload->get_filename(), $state);
        }
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $uploaddraftid);

        return [
            'draftitemid' => $stageid,
            'rejected' => $state->rejected,
            'errors' => array_values(array_unique($state->errors)),
        ];
    }

    /**
     * Unpack one uploaded ZIP into the stage.
     *
     * @param stored_file $zip the uploaded ZIP
     * @param \file_packer $packer zip packer
     * @param stdClass $state staging state (see stage())
     * @return void
     */
    private static function stage_zip(stored_file $zip, \file_packer $packer, stdClass $state): void {
        global $USER;
        $list = $zip->list_files($packer);
        if ($list === false) {
            $state->rejected[] = ['name' => $zip->get_filename(), 'reason' => 'importbadzip'];
            return;
        }
        // Check the claimed sizes before extracting anything (zip bombs). A file over the per-file upload limit
        // would be rejected after extraction anyway; refuse the ZIP before writing any of it.
        $files = 0;
        $bytes = 0;
        $toobig = [];
        foreach ($list as $info) {
            if (!empty($info->is_directory)) {
                continue;
            }
            $files++;
            $bytes += (int) $info->size;
            if ($state->maxbytes > 0 && (int) $info->size > $state->maxbytes) {
                $toobig[] = (string) $info->pathname;
            }
        }
        if ($toobig) {
            foreach ($toobig as $name) {
                $state->rejected[] = ['name' => clean_param($name, PARAM_PATH), 'reason' => 'importfiletoolarge'];
            }
            $state->rejected[] = ['name' => $zip->get_filename(), 'reason' => 'importzipbigfile'];
            return;
        }
        if ($state->count + $files > self::MAX_FILES) {
            $state->rejected[] = ['name' => $zip->get_filename(), 'reason' => 'importtoomanyfiles'];
            $state->errors[] = 'importerror_toomanyfiles';
            return;
        }
        if ($state->bytes + $bytes > self::MAX_TOTAL_BYTES) {
            $state->rejected[] = ['name' => $zip->get_filename(), 'reason' => 'importtoolarge'];
            $state->errors[] = 'importerror_toolarge';
            return;
        }

        $fs = get_file_storage();
        $tempid = file_get_unused_draft_itemid();
        // The packer cleans every entry name (PARAM_PATH, "../" removed) and checks real against claimed sizes.
        $result = $zip->extract_to_storage($packer, $state->usercontextid, 'user', 'draft', $tempid, '/', $USER->id);
        if ($result === false) {
            $state->rejected[] = ['name' => $zip->get_filename(), 'reason' => 'importbadzip'];
        } else {
            $extracted = $fs->get_area_files(
                $state->usercontextid,
                'user',
                'draft',
                $tempid,
                'filepath, filename',
                false
            );
            foreach ($extracted as $file) {
                self::stage_file($file, ltrim($file->get_filepath(), '/') . $file->get_filename(), $state);
            }
        }
        $fs->delete_area_files($state->usercontextid, 'user', 'draft', $tempid);
    }

    /**
     * Copy one file into the stage after the path, cap and content checks.
     *
     * @param stored_file $file source file
     * @param string $relpath its path relative to the upload or ZIP root
     * @param stdClass $state staging state (see stage())
     * @return void
     */
    private static function stage_file(stored_file $file, string $relpath, stdClass $state): void {
        $relpath = self::sanitise_path($relpath);
        if ($relpath === null) {
            // Hidden file, system folder or an unusable name: silently ignored.
            return;
        }
        if ($state->count >= self::MAX_FILES) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importtoomanyfiles'];
            $state->errors[] = 'importerror_toomanyfiles';
            return;
        }
        $size = (int) $file->get_filesize();
        if ($state->maxbytes > 0 && $size > $state->maxbytes) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importfiletoolarge'];
            return;
        }
        if ($state->bytes + $size > self::MAX_TOTAL_BYTES) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importtoolarge'];
            $state->errors[] = 'importerror_toolarge';
            return;
        }
        $kind = self::classify($file, $relpath);
        if ($kind === null) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importnotmedia'];
            return;
        }
        if ($kind === self::KIND_MANIFEST && $size > self::MAX_MANIFEST_BYTES) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importfiletoolarge'];
            return;
        }
        $key = \core_text::strtolower($relpath);
        if (isset($state->seen[$key])) {
            $state->rejected[] = ['name' => $relpath, 'reason' => 'importduplicate'];
            return;
        }
        $state->seen[$key] = true;

        $slash = strrpos($relpath, '/');
        $filepath = $slash === false ? '/' : '/' . substr($relpath, 0, $slash) . '/';
        $filename = $slash === false ? $relpath : substr($relpath, $slash + 1);
        get_file_storage()->create_file_from_storedfile([
            'contextid' => $state->usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $state->stageid,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $file);
        $state->count++;
        $state->bytes += $size;
    }

    /**
     * Sanitise a relative path from an upload or ZIP.
     *
     * @param string $path raw relative path
     * @return string|null clean "dir/sub/name.ext", or null when the file must be ignored
     */
    public static function sanitise_path(string $path): ?string {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            if ($part[0] === '.' || strcasecmp($part, '__MACOSX') === 0) {
                return null;
            }
            $clean = clean_param($part, PARAM_FILE);
            if ($clean === '' || $clean === '.' || $clean === '..') {
                return null;
            }
            $parts[] = $clean;
        }
        if (!$parts || in_array(\core_text::strtolower(end($parts)), ['thumbs.db', 'desktop.ini'], true)) {
            return null;
        }
        return implode('/', $parts);
    }

    /**
     * Whether an uploaded file is a ZIP (by content).
     *
     * @param stored_file $file file
     * @return bool
     */
    private static function is_zip(stored_file $file): bool {
        return strncmp(self::read_head($file), "PK\x03\x04", 4) === 0;
    }

    /**
     * The kind of a staged file, by content (and by name for the manifest).
     *
     * @param stored_file $file file
     * @param string $relpath sanitised relative path
     * @return string|null KIND_* constant, or null when the file is not accepted
     */
    private static function classify(stored_file $file, string $relpath): ?string {
        if (strcasecmp(basename($relpath), self::MANIFEST) === 0) {
            return self::KIND_MANIFEST;
        }
        $ext = slides::sniff_media_type(self::read_head($file));
        if (slides::type_allowed(slides::AREA_IMAGE, $ext)) {
            return self::KIND_IMAGE;
        }
        if (slides::type_allowed(slides::AREA_AUDIO, $ext)) {
            return self::KIND_AUDIO;
        }
        return null;
    }

    // Step 2: the plan.

    /**
     * The staged files of the current user, by relative path.
     *
     * @param int $stageid stage draft item id
     * @return stored_file[] relpath => file, in natural path order
     */
    public static function get_staged_files(int $stageid): array {
        global $USER;
        $usercontext = context_user::instance($USER->id);
        $files = [];
        foreach (
            get_file_storage()->get_area_files(
                $usercontext->id,
                'user',
                'draft',
                $stageid,
                'filepath, filename',
                false
            ) as $file
        ) {
            $files[ltrim($file->get_filepath(), '/') . $file->get_filename()] = $file;
        }
        uksort($files, 'strnatcasecmp');
        return $files;
    }

    /**
     * Build the import plan from the stage.
     *
     * @param int $stageid stage draft item id
     * @return array plan (see parse_manifest())
     */
    public static function build_plan(int $stageid): array {
        $paths = [];
        $manifest = null;
        foreach (self::get_staged_files($stageid) as $relpath => $file) {
            $kind = self::classify($file, $relpath);
            if ($kind === self::KIND_MANIFEST) {
                // The shallowest manifest wins.
                if ($manifest === null || substr_count($relpath, '/') < substr_count($manifest[0], '/')) {
                    $manifest = [$relpath, $file];
                }
                continue;
            }
            if ($kind !== null) {
                $paths[$relpath] = $kind;
            }
        }
        if ($manifest !== null) {
            [$relpath, $file] = $manifest;
            $dir = strpos($relpath, '/') === false ? '' : substr($relpath, 0, strrpos($relpath, '/') + 1);
            return self::parse_manifest($file->get_content(), $paths, $dir);
        }
        return self::group_by_filenames($paths);
    }

    /**
     * Build a plan from a slides.csv manifest.
     *
     * A plan is ['source' => SOURCE_*, 'slides' => [slide...], 'unmatched' => [relpath...],
     * 'warnings' => [['key' => lang key, 'a' => mixed]]], where each slide is ['image' => ?relpath,
     * 'alttext' => string, 'decorative' => 0|1, 'prompt' => string, 'promptlang' => string,
     * 'promptaudio' => ?relpath, 'options' => [['audio' => relpath, 'transcript' => string]],
     * 'correct' => ?int (0-based option index), 'warnings' => [['key', 'a']]].
     *
     * @param string $csv manifest content
     * @param array $paths relpath => KIND_IMAGE|KIND_AUDIO of the other staged files
     * @param string $basedir folder of the manifest ("" or "dir/"), which paths in it are relative to
     * @return array plan
     */
    public static function parse_manifest(string $csv, array $paths, string $basedir = ''): array {
        $plan = ['source' => self::SOURCE_MANIFEST, 'slides' => [], 'unmatched' => [], 'warnings' => []];
        // Undo the exporter's formula guard: a cell written as '=... (or '+, '-, '@) is read as =....
        $rows = array_map(
            fn($row) => array_map(fn($cell) => preg_replace("/^(\\s*)'(?=\\s*[=+\\-@])/", '$1', (string) $cell), $row),
            self::read_csv($csv)
        );
        if (!$rows) {
            $plan['warnings'][] = ['key' => 'importwarn_emptymanifest', 'a' => null];
            $plan['unmatched'] = array_keys($paths);
            return $plan;
        }
        $header = array_map(fn($h) => \core_text::strtolower(trim((string) $h)), array_shift($rows));
        $col = array_flip($header);
        if (isset($col['alttext']) && !isset($col['alt'])) {
            $col['alt'] = $col['alttext'];
        }
        if (!isset($col['image'])) {
            $plan['warnings'][] = ['key' => 'importwarn_noimagecolumn', 'a' => null];
            $plan['unmatched'] = array_keys($paths);
            return $plan;
        }
        $audiocols = [];
        foreach ($header as $index => $name) {
            if (preg_match('/^audio(\d+)$/', $name, $m)) {
                $audiocols[(int) $m[1]] = $index;
            }
        }
        ksort($audiocols);

        $used = [];
        $line = 1;
        foreach ($rows as $row) {
            $line++;
            $get = fn(string $name): string => isset($col[$name]) ? trim((string) ($row[$col[$name]] ?? '')) : '';
            $slide = self::empty_slide();
            $imageref = $get('image');
            $hasaudio = false;
            foreach ($audiocols as $n => $index) {
                if (trim((string) ($row[$index] ?? '')) !== '') {
                    $hasaudio = true;
                    break;
                }
            }
            if ($imageref === '' && !$hasaudio) {
                // Blank row.
                continue;
            }
            if (count($plan['slides']) >= self::MAX_SLIDES) {
                $plan['warnings'][] = ['key' => 'importwarn_toomanyslides', 'a' => self::MAX_SLIDES];
                break;
            }
            $slide['line'] = $line;
            if ($imageref !== '') {
                $found = self::resolve($imageref, $basedir, $paths, self::KIND_IMAGE);
                if ($found === null) {
                    $slide['warnings'][] = ['key' => 'importwarn_missingfile', 'a' => $imageref];
                } else {
                    $slide['image'] = $found;
                    $used[$found] = true;
                }
            } else {
                $slide['warnings'][] = ['key' => 'importwarn_noimage', 'a' => null];
            }
            $slide['alttext'] = $get('alt');
            $slide['decorative'] = self::truthy($get('decorative')) ? 1 : 0;
            // Empty or not understood: the activity's default for new slides.
            if ($get('fade') !== '') {
                $slide['fade'] = self::truthy($get('fade')) ? 1 : 0;
            }
            $fadewidth = rtrim($get('fadewidth'), '% ');
            if (is_numeric($fadewidth)) {
                $slide['fadewidth'] = instance_settings::clamp_fade($fadewidth);
            }
            $slide['prompt'] = $get('prompt');
            $slide['promptlang'] = clean_param($get('promptlang'), PARAM_ALPHANUMEXT);
            if (($ref = $get('promptaudio')) !== '') {
                $found = self::resolve($ref, $basedir, $paths, self::KIND_AUDIO);
                if ($found === null) {
                    $slide['warnings'][] = ['key' => 'importwarn_missingfile', 'a' => $ref];
                } else {
                    $slide['promptaudio'] = $found;
                    $used[$found] = true;
                }
            }
            $correctcol = (int) $get('correct');
            $correctset = $get('correct') !== '';
            foreach ($audiocols as $n => $index) {
                $ref = trim((string) ($row[$index] ?? ''));
                if ($ref === '') {
                    continue;
                }
                $found = self::resolve($ref, $basedir, $paths, self::KIND_AUDIO);
                if ($found === null) {
                    $slide['warnings'][] = ['key' => 'importwarn_missingfile', 'a' => $ref];
                    continue;
                }
                if (count($slide['options']) >= slides::MAX_OPTIONS) {
                    $slide['warnings'][] = ['key' => 'importwarn_toomanyoptions', 'a' => slides::MAX_OPTIONS];
                    continue;
                }
                if ($n === $correctcol) {
                    $slide['correct'] = count($slide['options']);
                }
                $slide['options'][] = ['audio' => $found, 'transcript' => $get('transcript' . $n), 'label' => $found];
                $used[$found] = true;
            }
            if ($slide['correct'] === null) {
                $slide['warnings'][] = ['key' => $correctset ? 'importwarn_badcorrect' : 'importwarn_nocorrect',
                    'a' => null];
            }
            self::check_option_count($slide);
            $plan['slides'][] = $slide;
        }
        $plan['unmatched'] = array_values(array_diff(array_keys($paths), array_keys($used)));
        return $plan;
    }

    /**
     * Build a plan by grouping files by name (see the class comment for the convention).
     *
     * @param array $paths relpath => KIND_IMAGE|KIND_AUDIO
     * @return array plan (see parse_manifest())
     */
    public static function group_by_filenames(array $paths): array {
        $plan = ['source' => self::SOURCE_FILENAMES, 'slides' => [], 'unmatched' => [], 'warnings' => []];
        $images = [];
        $audio = [];
        foreach ($paths as $relpath => $kind) {
            $stem = self::stem($relpath);
            if ($kind === self::KIND_IMAGE) {
                if (isset($images[\core_text::strtolower($stem)])) {
                    // Two images with the same name in different formats: the second is not used.
                    $plan['unmatched'][] = $relpath;
                    continue;
                }
                $images[\core_text::strtolower($stem)] = $relpath;
            } else if ($kind === self::KIND_AUDIO) {
                $audio[$relpath] = $stem;
            }
        }
        uksort($images, 'strnatcasecmp');
        // Longest image stem first, so "01-2" wins over "01" for "01-2-a.mp3".
        // Keys such as "1" become integers in PHP arrays: compare them as strings.
        $bylength = array_map('strval', array_keys($images));
        usort($bylength, fn($a, $b) => \core_text::strlen($b) <=> \core_text::strlen($a));

        $groups = [];
        foreach ($audio as $relpath => $stem) {
            $correct = false;
            $lower = \core_text::strtolower($stem);
            foreach (self::CORRECT_MARKERS as $marker) {
                if (
                    \core_text::strlen($lower) > \core_text::strlen($marker)
                        && \core_text::substr($lower, -\core_text::strlen($marker)) === $marker
                ) {
                    $correct = true;
                    $lower = \core_text::substr($lower, 0, -\core_text::strlen($marker));
                    break;
                }
            }
            $owner = null;
            $label = '';
            foreach ($bylength as $imagestem) {
                if ($lower === $imagestem) {
                    $owner = $imagestem;
                    break;
                }
                $len = \core_text::strlen($imagestem);
                if (
                    \core_text::substr($lower, 0, $len) === $imagestem
                        && in_array(\core_text::substr($lower, $len, 1), ['-', '_', ' ', '.'], true)
                ) {
                    $owner = $imagestem;
                    $label = trim(\core_text::substr($lower, $len + 1));
                    break;
                }
            }
            if ($owner === null) {
                $plan['unmatched'][] = $relpath;
                continue;
            }
            $groups[$owner][] = ['audio' => $relpath, 'label' => $label, 'correct' => $correct];
        }

        foreach ($images as $imagestem => $imagepath) {
            $imagestem = (string) $imagestem;
            if (empty($groups[$imagestem])) {
                $plan['unmatched'][] = $imagepath;
                continue;
            }
            if (count($plan['slides']) >= self::MAX_SLIDES) {
                $plan['warnings'][] = ['key' => 'importwarn_toomanyslides', 'a' => self::MAX_SLIDES];
                $plan['unmatched'][] = $imagepath;
                foreach ($groups[$imagestem] as $clip) {
                    $plan['unmatched'][] = $clip['audio'];
                }
                continue;
            }
            $slide = self::empty_slide();
            $slide['image'] = $imagepath;
            $clips = $groups[$imagestem];
            usort($clips, fn($a, $b) => strnatcasecmp($a['label'], $b['label']) ?: strnatcasecmp($a['audio'], $b['audio']));
            $marked = 0;
            foreach ($clips as $clip) {
                if ($clip['label'] === self::PROMPT_LABEL && !$clip['correct'] && $slide['promptaudio'] === null) {
                    $slide['promptaudio'] = $clip['audio'];
                    continue;
                }
                if (count($slide['options']) >= slides::MAX_OPTIONS) {
                    $slide['warnings'][] = ['key' => 'importwarn_toomanyoptions', 'a' => slides::MAX_OPTIONS];
                    $plan['unmatched'][] = $clip['audio'];
                    continue;
                }
                if ($clip['correct']) {
                    $marked++;
                    $slide['correct'] = count($slide['options']);
                }
                $slide['options'][] = ['audio' => $clip['audio'], 'transcript' => '', 'label' => $clip['audio']];
            }
            if ($marked > 1) {
                $slide['correct'] = null;
                $slide['warnings'][] = ['key' => 'importwarn_manycorrect', 'a' => null];
            } else if ($marked === 0) {
                $slide['warnings'][] = ['key' => 'importwarn_nocorrect', 'a' => null];
            }
            self::check_option_count($slide);
            $plan['slides'][] = $slide;
        }
        $plan['unmatched'] = array_values(array_unique($plan['unmatched']));
        sort($plan['unmatched'], SORT_NATURAL | SORT_FLAG_CASE);
        return $plan;
    }

    /**
     * A fingerprint of the plan's files, so a confirm request can tell that the stage is what was reviewed.
     *
     * @param array $plan plan
     * @return string sha1
     */
    public static function plan_hash(array $plan): string {
        $refs = [];
        foreach ($plan['slides'] as $slide) {
            $refs[] = [$slide['image'], $slide['promptaudio'], array_column($slide['options'], 'audio')];
        }
        return sha1(json_encode([$plan['source'], $refs]));
    }

    /**
     * Apply the teacher's review choices to a plan.
     *
     * @param array $plan plan
     * @param array $choices slide index => ['include' => bool, 'alttext' => string, 'decorative' => bool,
     *      'correct' => int (option index, -1 = not set)]; slides without an entry keep the plan's values
     * @return array the plan with excluded slides removed and the choices applied
     */
    public static function apply_choices(array $plan, array $choices): array {
        $slidesout = [];
        foreach ($plan['slides'] as $index => $slide) {
            $choice = $choices[$index] ?? null;
            if ($choice !== null) {
                if (array_key_exists('include', $choice) && empty($choice['include'])) {
                    continue;
                }
                if (array_key_exists('alttext', $choice)) {
                    $slide['alttext'] = trim((string) $choice['alttext']);
                }
                if (array_key_exists('decorative', $choice)) {
                    $slide['decorative'] = empty($choice['decorative']) ? 0 : 1;
                }
                if (array_key_exists('correct', $choice)) {
                    $correct = (int) $choice['correct'];
                    $slide['correct'] = ($correct >= 0 && $correct < count($slide['options'])) ? $correct : null;
                }
            }
            $slidesout[] = $slide;
        }
        $plan['slides'] = $slidesout;
        return $plan;
    }

    // Step 3: writing.

    /**
     * Create the planned slides in the activity and delete the stage.
     *
     * The caller has checked mod/whakaharatau:manage and the session key.
     *
     * @param context_module $context module context
     * @param stdClass $instance whakaharatau record
     * @param int $stageid stage draft item id of the current user
     * @param array $plan plan after apply_choices()
     * @param bool $replace true deletes every existing slide first; false appends
     * @return int number of slides created
     */
    public static function apply(
        context_module $context,
        stdClass $instance,
        int $stageid,
        array $plan,
        bool $replace
    ): int {
        global $DB;
        $files = self::get_staged_files($stageid);
        $created = 0;
        $transaction = $DB->start_delegated_transaction();
        // Replaced slides are deleted only after every new file is saved: file storage is not rolled back, so a
        // failed save must not leave the restored old slides without their media.
        $oldslideids = $replace ? array_map('intval', array_keys(slides::get_slides((int) $instance->id))) : [];
        // A setting the site locks is not taken from the file either.
        $lockedfade = instance_settings::is_locked('fade');
        $lockedwidth = instance_settings::is_locked('fadewidth');
        foreach ($plan['slides'] as $slide) {
            $slideid = slides::create_slide((int) $instance->id, (object) [
                'alttext' => $slide['alttext'],
                'decorative' => $slide['decorative'],
                'fade' => $lockedfade ? null : $slide['fade'],
                'fadewidth' => $lockedwidth ? null : $slide['fadewidth'],
                'prompt' => $slide['prompt'],
                'promptformat' => FORMAT_PLAIN,
                'promptlang' => $slide['promptlang'],
            ]);
            if ($slide['image'] !== null && isset($files[$slide['image']])) {
                slides::save_file_from_stored_file($context, slides::AREA_IMAGE, $slideid, $files[$slide['image']]);
            }
            if ($slide['promptaudio'] !== null && isset($files[$slide['promptaudio']])) {
                slides::save_file_from_stored_file(
                    $context,
                    slides::AREA_PROMPT,
                    $slideid,
                    $files[$slide['promptaudio']]
                );
            }
            foreach ($slide['options'] as $index => $option) {
                $optionid = slides::create_option($slideid, (object) [
                    'transcript' => $option['transcript'],
                    'iscorrect' => $index === $slide['correct'] ? 1 : 0,
                    'sortorder' => $index,
                ]);
                if (isset($files[$option['audio']])) {
                    slides::save_file_from_stored_file($context, slides::AREA_AUDIO, $optionid, $files[$option['audio']]);
                }
            }
            $created++;
        }
        if ($oldslideids) {
            slides::delete_slides($context, (int) $instance->id, $oldslideids);
        }
        $transaction->allow_commit();
        self::discard_stage($stageid);
        return $created;
    }

    /**
     * Delete a stage draft area of the current user.
     *
     * @param int $stageid stage draft item id
     * @return void
     */
    public static function discard_stage(int $stageid): void {
        global $USER;
        if ($stageid <= 0) {
            return;
        }
        get_file_storage()->delete_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $stageid);
    }

    // Helpers.

    /**
     * A plan slide with no content.
     *
     * @return array
     */
    private static function empty_slide(): array {
        return [
            'line' => 0,
            'image' => null,
            'alttext' => '',
            'decorative' => 0,
            'fade' => null,
            'fadewidth' => null,
            'prompt' => '',
            'promptlang' => '',
            'promptaudio' => null,
            'options' => [],
            'correct' => null,
            'warnings' => [],
        ];
    }

    /**
     * Warn when a planned slide has too few options.
     *
     * @param array $slide plan slide (changed in place)
     * @return void
     */
    private static function check_option_count(array &$slide): void {
        if (count($slide['options']) < slides::MIN_OPTIONS) {
            $slide['warnings'][] = ['key' => 'importwarn_toofewoptions', 'a' => slides::MIN_OPTIONS];
        }
    }

    /**
     * Find the staged file a manifest cell names: exact path, then case-insensitive, then a unique base name.
     *
     * @param string $ref the cell value
     * @param string $basedir folder of the manifest ("" or "dir/")
     * @param array $paths relpath => kind
     * @param string $kind required KIND_* of the file
     * @return string|null relpath
     */
    private static function resolve(string $ref, string $basedir, array $paths, string $kind): ?string {
        $clean = self::sanitise_path($ref);
        if ($clean === null) {
            return null;
        }
        $candidates = [$basedir . $clean, $clean];
        foreach ($candidates as $candidate) {
            if (($paths[$candidate] ?? null) === $kind) {
                return $candidate;
            }
        }
        $lowercandidates = array_map(fn($c) => \core_text::strtolower($c), $candidates);
        $byname = [];
        foreach ($paths as $relpath => $filekind) {
            if ($filekind !== $kind) {
                continue;
            }
            if (in_array(\core_text::strtolower($relpath), $lowercandidates, true)) {
                return $relpath;
            }
            if (strcasecmp(basename($relpath), basename($clean)) === 0) {
                $byname[] = $relpath;
            }
        }
        return count($byname) === 1 ? $byname[0] : null;
    }

    /**
     * Parse CSV text into rows: UTF-8 (BOM removed, Windows-1252 converted), separator detected from the
     * first line (comma, semicolon or tab).
     *
     * @param string $csv raw content
     * @return array[] rows of cells
     */
    private static function read_csv(string $csv): array {
        if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = \core_text::convert($csv, 'windows-1252', 'utf-8');
        }
        $firstline = strtok($csv, "\r\n");
        if ($firstline === false) {
            return [];
        }
        $separator = ',';
        $best = substr_count($firstline, ',');
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstline, $candidate) > $best) {
                $best = substr_count($firstline, $candidate);
                $separator = $candidate;
            }
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_SLIDES + 50) {
                break;
            }
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Whether a manifest cell means "yes".
     *
     * @param string $value cell
     * @return bool
     */
    private static function truthy(string $value): bool {
        return in_array(\core_text::strtolower(trim($value)), ['1', 'y', 'yes', 'true', 'x'], true);
    }

    /**
     * A relative path without its extension.
     *
     * @param string $relpath relative path
     * @return string
     */
    private static function stem(string $relpath): string {
        $dot = strrpos($relpath, '.');
        $slash = strrpos($relpath, '/');
        if ($dot === false || ($slash !== false && $dot < $slash)) {
            return $relpath;
        }
        return substr($relpath, 0, $dot);
    }

    /**
     * First bytes of a stored file.
     *
     * @param stored_file $file file
     * @return string up to 16 bytes
     */
    private static function read_head(stored_file $file): string {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return '';
        }
        $head = (string) fread($handle, 16);
        fclose($handle);
        return $head;
    }
}
