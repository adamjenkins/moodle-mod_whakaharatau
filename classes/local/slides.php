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
use moodle_url;
use stdClass;
use stored_file;

/**
 * Slides and options: storage, ordering, media files and validation.
 *
 * Every method is static and stateless. Callers check capabilities (mod/whakaharatau:manage
 * for writes); this class only enforces data rules.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class slides {
    /** @var string Component name for file storage. */
    const COMPONENT = 'mod_whakaharatau';
    /** @var string File area of the slide image (itemid = slide id). */
    const AREA_IMAGE = 'slideimage';
    /** @var string File area of the slide's prompt audio (itemid = slide id). */
    const AREA_PROMPT = 'slideprompt';
    /** @var string File area of an option's audio clip (itemid = option id). */
    const AREA_AUDIO = 'optionaudio';
    /** @var string File area of the start-screen cover image (itemid = 0). */
    const AREA_COVER = 'cover';

    /** @var int Fewest options a playable slide may have. */
    const MIN_OPTIONS = 2;
    /** @var int Most options a slide may have. */
    const MAX_OPTIONS = 6;

    /** @var string[] Image extensions accepted (checked by content). SVG is never accepted. */
    const IMAGE_TYPES = ['jpg', 'png', 'gif', 'webp'];
    /** @var string[] Audio extensions accepted (checked by content). */
    const AUDIO_TYPES = ['mp3', 'm4a', 'ogg', 'wav'];

    /** @var string Validation problem: the slide has no image. */
    const PROBLEM_NOIMAGE = 'problem_noimage';
    /** @var string Validation problem: no alt text and not decorative. */
    const PROBLEM_NOALT = 'problem_noalttext';
    /** @var string Validation problem: fewer than MIN_OPTIONS options. */
    const PROBLEM_TOOFEW = 'problem_toofewoptions';
    /** @var string Validation problem: more than MAX_OPTIONS options. */
    const PROBLEM_TOOMANY = 'problem_toomanyoptions';
    /** @var string Validation problem: no option is marked correct. */
    const PROBLEM_NOCORRECT = 'problem_nocorrect';
    /** @var string Validation problem: more than one option is marked correct. */
    const PROBLEM_MANYCORRECT = 'problem_manycorrect';
    /** @var string Validation problem: an option has no audio file. */
    const PROBLEM_NOAUDIO = 'problem_noaudio';

    // Reading.

    /**
     * All slides of an activity in teacher order.
     *
     * @param int $whakaharatauid instance id
     * @return stdClass[] slide records keyed by id
     */
    public static function get_slides(int $whakaharatauid): array {
        global $DB;
        return $DB->get_records('whakaharatau_slide', ['whakaharatauid' => $whakaharatauid], 'sortorder ASC, id ASC');
    }

    /**
     * One slide.
     *
     * @param int $slideid slide id
     * @param int $strictness MUST_EXIST or IGNORE_MISSING
     * @return stdClass|false slide record
     */
    public static function get_slide(int $slideid, int $strictness = MUST_EXIST) {
        global $DB;
        return $DB->get_record('whakaharatau_slide', ['id' => $slideid], '*', $strictness);
    }

    /**
     * Number of slides in an activity.
     *
     * @param int $whakaharatauid instance id
     * @return int
     */
    public static function count_slides(int $whakaharatauid): int {
        global $DB;
        return $DB->count_records('whakaharatau_slide', ['whakaharatauid' => $whakaharatauid]);
    }

    /**
     * Options of one slide in teacher order.
     *
     * @param int $slideid slide id
     * @return stdClass[] option records keyed by id
     */
    public static function get_options(int $slideid): array {
        global $DB;
        return $DB->get_records('whakaharatau_option', ['slideid' => $slideid], 'sortorder ASC, id ASC');
    }

    /**
     * One option.
     *
     * @param int $optionid option id
     * @param int $strictness MUST_EXIST or IGNORE_MISSING
     * @return stdClass|false option record
     */
    public static function get_option(int $optionid, int $strictness = MUST_EXIST) {
        global $DB;
        return $DB->get_record('whakaharatau_option', ['id' => $optionid], '*', $strictness);
    }

    /**
     * All options of an activity, grouped by slide, each group in teacher order.
     *
     * @param int $whakaharatauid instance id
     * @return array slideid => [optionid => option record]
     */
    public static function get_options_for_instance(int $whakaharatauid): array {
        global $DB;
        $sql = "SELECT o.*
                  FROM {whakaharatau_option} o
                  JOIN {whakaharatau_slide} s ON s.id = o.slideid
                 WHERE s.whakaharatauid = :wid
              ORDER BY o.slideid ASC, o.sortorder ASC, o.id ASC";
        $grouped = [];
        foreach ($DB->get_records_sql($sql, ['wid' => $whakaharatauid]) as $option) {
            $grouped[(int) $option->slideid][(int) $option->id] = $option;
        }
        return $grouped;
    }

    /**
     * The id of the correct option of a slide, or null when none (or more than one) is set.
     *
     * Server-side only: never send this to the browser before the slide is finished.
     *
     * @param int $slideid slide id
     * @return int|null
     */
    public static function get_correct_optionid(int $slideid): ?int {
        global $DB;
        $ids = $DB->get_fieldset_select('whakaharatau_option', 'id', 'slideid = ? AND iscorrect = 1', [$slideid]);
        return count($ids) === 1 ? (int) reset($ids) : null;
    }

    // Writing slides.

    /**
     * Create a slide at the end of the activity (or at $data->sortorder).
     *
     * @param int $whakaharatauid instance id
     * @param stdClass $data fields: alttext, decorative, prompt, promptformat, promptlang, sortorder, fade, fadewidth
     *      (all optional; a missing fade or fadewidth takes the activity's default for new slides)
     * @return int new slide id
     */
    public static function create_slide(int $whakaharatauid, stdClass $data): int {
        global $DB;
        $now = time();
        $fade = $data->fade ?? null;
        $fadewidth = $data->fadewidth ?? null;
        if ($fade === null || $fadewidth === null) {
            $instance = $DB->get_record('whakaharatau', ['id' => $whakaharatauid], 'fade, fadewidth', MUST_EXIST);
            $fade = $fade ?? $instance->fade;
            $fadewidth = $fadewidth ?? $instance->fadewidth;
        }
        $record = (object) [
            'whakaharatauid' => $whakaharatauid,
            'sortorder' => isset($data->sortorder) ? (int) $data->sortorder : self::next_slide_sortorder($whakaharatauid),
            'prompt' => self::clean_text($data->prompt ?? ''),
            'promptformat' => FORMAT_PLAIN,
            'promptlang' => self::clean_lang($data->promptlang ?? null),
            'alttext' => self::clean_text($data->alttext ?? ''),
            'decorative' => empty($data->decorative) ? 0 : 1,
            'fade' => empty($fade) ? 0 : 1,
            'fadewidth' => instance_settings::clamp_fade($fadewidth),
            'version' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        return (int) $DB->insert_record('whakaharatau_slide', $record);
    }

    /**
     * Update a slide's own fields (not its files or options).
     *
     * @param stdClass $data must have id; any of alttext, decorative, prompt, promptformat, promptlang, sortorder
     * @return void
     */
    public static function update_slide(stdClass $data): void {
        global $DB;
        $slide = self::get_slide((int) $data->id);
        foreach (['alttext', 'prompt'] as $field) {
            if (property_exists($data, $field)) {
                $slide->$field = self::clean_text($data->$field);
            }
        }
        $slide->promptformat = FORMAT_PLAIN;
        if (property_exists($data, 'promptlang')) {
            $slide->promptlang = self::clean_lang($data->promptlang);
        }
        if (property_exists($data, 'decorative')) {
            $slide->decorative = empty($data->decorative) ? 0 : 1;
        }
        if (isset($data->fade)) {
            $slide->fade = empty($data->fade) ? 0 : 1;
        }
        if (isset($data->fadewidth)) {
            $slide->fadewidth = instance_settings::clamp_fade($data->fadewidth);
        }
        if (property_exists($data, 'sortorder')) {
            $slide->sortorder = (int) $data->sortorder;
        }
        $slide->timemodified = time();
        $DB->update_record('whakaharatau_slide', $slide);
    }

    /**
     * Delete a slide, its options and all their files, then resequence the rest.
     *
     * Responses that point at the slide are kept (history); readers must tolerate missing slides.
     *
     * @param context_module $context module context
     * @param int $slideid slide id
     * @return void
     */
    public static function delete_slide(context_module $context, int $slideid): void {
        global $DB;
        $slide = self::get_slide($slideid, IGNORE_MISSING);
        if (!$slide) {
            return;
        }
        // Answers stay in the reports, so what they were answers to is kept.
        versions::ensure($context, $slideid);
        $fs = get_file_storage();
        foreach (self::get_options($slideid) as $option) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_AUDIO, $option->id);
        }
        $DB->delete_records('whakaharatau_option', ['slideid' => $slideid]);
        $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_IMAGE, $slideid);
        $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_PROMPT, $slideid);
        $DB->delete_records('whakaharatau_slide', ['id' => $slideid]);
        versions::purge_orphans((int) $slide->whakaharatauid);
        self::resequence_slides((int) $slide->whakaharatauid);
    }

    /**
     * Delete every slide of an activity (used by delete_instance).
     *
     * @param context_module|null $context module context, or null when it no longer exists (files are then left
     *      to core's context cleanup)
     * @param int $whakaharatauid instance id
     * @return void
     */
    public static function delete_all_slides(?context_module $context, int $whakaharatauid): void {
        global $DB;
        $slideids = $DB->get_fieldset_select('whakaharatau_slide', 'id', 'whakaharatauid = ?', [$whakaharatauid]);
        if ($slideids) {
            [$insql, $params] = $DB->get_in_or_equal($slideids);
            $DB->delete_records_select('whakaharatau_option', "slideid $insql", $params);
        }
        $DB->delete_records('whakaharatau_slide', ['whakaharatauid' => $whakaharatauid]);
        versions::delete_all($context, $whakaharatauid);
        if ($context) {
            $fs = get_file_storage();
            foreach ([self::AREA_IMAGE, self::AREA_PROMPT, self::AREA_AUDIO] as $area) {
                $fs->delete_area_files($context->id, self::COMPONENT, $area);
            }
        }
    }

    /**
     * Delete some slides of an activity, with their options and files, then renumber the rest.
     *
     * @param context_module $context module context
     * @param int $whakaharatauid instance id
     * @param int[] $slideids ids of slides of this activity (other ids are ignored)
     * @return void
     */
    public static function delete_slides(context_module $context, int $whakaharatauid, array $slideids): void {
        global $DB;
        $slideids = array_values(array_intersect(
            array_map('intval', $slideids),
            array_map('intval', array_keys(self::get_slides($whakaharatauid)))
        ));
        if (!$slideids) {
            return;
        }
        foreach ($slideids as $slideid) {
            versions::ensure($context, $slideid);
        }
        $fs = get_file_storage();
        [$insql, $params] = $DB->get_in_or_equal($slideids);
        $optionids = $DB->get_fieldset_select('whakaharatau_option', 'id', "slideid $insql", $params);
        foreach ($optionids as $optionid) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_AUDIO, (int) $optionid);
        }
        foreach ($slideids as $slideid) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_IMAGE, $slideid);
            $fs->delete_area_files($context->id, self::COMPONENT, self::AREA_PROMPT, $slideid);
        }
        $DB->delete_records_select('whakaharatau_option', "slideid $insql", $params);
        $DB->delete_records_select('whakaharatau_slide', "id $insql", $params);
        versions::purge_orphans($whakaharatauid);
        self::resequence_slides($whakaharatauid);
    }

    /**
     * Put the slides in the given order. Every slide of the activity must be listed exactly once.
     *
     * @param int $whakaharatauid instance id
     * @param int[] $slideids slide ids in the new order
     * @return void
     * @throws moodle_exception when the list does not match the activity's slides
     */
    public static function reorder_slides(int $whakaharatauid, array $slideids): void {
        global $DB;
        $slideids = array_map('intval', array_values($slideids));
        $existing = array_map('intval', array_keys(self::get_slides($whakaharatauid)));
        $a = $slideids;
        $b = $existing;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new moodle_exception('errorslideorder', 'mod_whakaharatau');
        }
        foreach ($slideids as $i => $slideid) {
            $DB->set_field('whakaharatau_slide', 'sortorder', $i, ['id' => $slideid]);
        }
    }

    /**
     * Move one slide so that it comes straight after another (for core/sortable_list).
     *
     * @param int $slideid slide to move
     * @param int $afterslideid slide it should follow, or 0 to move it to the top
     * @return void
     */
    public static function move_slide_after(int $slideid, int $afterslideid): void {
        $slide = self::get_slide($slideid);
        $order = array_map('intval', array_keys(self::get_slides((int) $slide->whakaharatauid)));
        $order = array_values(array_diff($order, [$slideid]));
        if ($afterslideid && ($pos = array_search($afterslideid, $order, true)) !== false) {
            array_splice($order, $pos + 1, 0, [$slideid]);
        } else {
            array_unshift($order, $slideid);
        }
        self::reorder_slides((int) $slide->whakaharatauid, $order);
    }

    /**
     * Renumber sortorder 0..n-1 keeping the current order.
     *
     * @param int $whakaharatauid instance id
     * @return void
     */
    public static function resequence_slides(int $whakaharatauid): void {
        global $DB;
        $i = 0;
        foreach (self::get_slides($whakaharatauid) as $slide) {
            if ((int) $slide->sortorder !== $i) {
                $DB->set_field('whakaharatau_slide', 'sortorder', $i, ['id' => $slide->id]);
            }
            $i++;
        }
    }

    // Writing options.

    /**
     * Create an option at the end of a slide (or at $data->sortorder).
     *
     * Marking it correct does NOT unmark the others; call set_correct_option() for that.
     *
     * @param int $slideid slide id
     * @param stdClass $data fields: transcript, iscorrect, sortorder (all optional)
     * @return int new option id
     */
    public static function create_option(int $slideid, stdClass $data): int {
        global $DB;
        $now = time();
        $record = (object) [
            'slideid' => $slideid,
            'sortorder' => isset($data->sortorder) ? (int) $data->sortorder : self::next_option_sortorder($slideid),
            'transcript' => self::clean_text($data->transcript ?? ''),
            'iscorrect' => empty($data->iscorrect) ? 0 : 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        return (int) $DB->insert_record('whakaharatau_option', $record);
    }

    /**
     * Update an option's own fields (not its audio).
     *
     * @param stdClass $data must have id; any of transcript, iscorrect, sortorder
     * @return void
     */
    public static function update_option(stdClass $data): void {
        global $DB;
        $option = self::get_option((int) $data->id);
        if (property_exists($data, 'transcript')) {
            $option->transcript = self::clean_text($data->transcript);
        }
        if (property_exists($data, 'iscorrect')) {
            $option->iscorrect = empty($data->iscorrect) ? 0 : 1;
        }
        if (property_exists($data, 'sortorder')) {
            $option->sortorder = (int) $data->sortorder;
        }
        $option->timemodified = time();
        $DB->update_record('whakaharatau_option', $option);
    }

    /**
     * Delete an option and its audio.
     *
     * @param context_module $context module context
     * @param int $optionid option id
     * @return void
     */
    public static function delete_option(context_module $context, int $optionid): void {
        global $DB;
        get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::AREA_AUDIO, $optionid);
        $DB->delete_records('whakaharatau_option', ['id' => $optionid]);
    }

    /**
     * Make one option the only correct option of its slide.
     *
     * @param int $slideid slide id
     * @param int $optionid option id (must belong to the slide)
     * @return void
     */
    public static function set_correct_option(int $slideid, int $optionid): void {
        global $DB;
        $option = self::get_option($optionid);
        if ((int) $option->slideid !== $slideid) {
            throw new moodle_exception('errorwrongslide', 'mod_whakaharatau');
        }
        $DB->set_field('whakaharatau_option', 'iscorrect', 0, ['slideid' => $slideid]);
        $DB->set_field('whakaharatau_option', 'iscorrect', 1, ['id' => $optionid]);
    }

    // Files.

    /**
     * The kind of file an area holds.
     *
     * @param string $filearea one of the AREA_* constants
     * @return string 'image' or 'audio'
     */
    public static function area_kind(string $filearea): string {
        switch ($filearea) {
            case self::AREA_IMAGE:
            case self::AREA_COVER:
                return 'image';
            case self::AREA_PROMPT:
            case self::AREA_AUDIO:
                return 'audio';
        }
        throw new \coding_exception('Unknown file area ' . $filearea);
    }

    /**
     * File manager options for an area (one file, accepted types only).
     *
     * @param string $filearea one of the AREA_* constants
     * @return array options for the filemanager element and file_prepare_draft_area()
     */
    public static function filemanager_options(string $filearea): array {
        global $CFG;
        // The FILE_* return types are defined in repository/lib.php.
        require_once($CFG->dirroot . '/repository/lib.php');
        if (self::area_kind($filearea) === 'image') {
            $types = array_merge(self::IMAGE_TYPES, ['jpeg']);
        } else {
            $types = array_merge(self::AUDIO_TYPES, ['oga']);
        }
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => 0,
            'accepted_types' => array_map(fn($ext) => '.' . $ext, $types),
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Identify an image or audio file by its first bytes (never trust the extension).
     *
     * @param string $head at least the first 16 bytes of the file
     * @return string|null canonical extension from IMAGE_TYPES/AUDIO_TYPES, or null when not recognised
     */
    public static function sniff_media_type(string $head): ?string {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (strncmp($head, 'OggS', 4) === 0) {
            return 'ogg';
        }
        if (strncmp($head, 'ID3', 3) === 0) {
            return 'mp3';
        }
        if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
            return 'mp3';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        return null;
    }

    /**
     * Whether a sniffed extension is acceptable for an area.
     *
     * @param string $filearea one of the AREA_* constants
     * @param string|null $ext result of sniff_media_type()
     * @return bool
     */
    public static function type_allowed(string $filearea, ?string $ext): bool {
        if ($ext === null) {
            return false;
        }
        $types = self::area_kind($filearea) === 'image' ? self::IMAGE_TYPES : self::AUDIO_TYPES;
        return in_array($ext, $types, true);
    }

    /**
     * The neutral stored file name for an area. Audio names never carry the original name, so a
     * name such as "01-b-correct.mp3" can never reveal the answer to the browser.
     *
     * @param string $filearea one of the AREA_* constants
     * @param string $ext canonical extension
     * @return string
     */
    public static function stored_filename(string $filearea, string $ext): string {
        $base = [
            self::AREA_IMAGE => 'image',
            self::AREA_COVER => 'cover',
            self::AREA_PROMPT => 'prompt',
            self::AREA_AUDIO => 'audio',
        ][$filearea];
        return $base . '.' . $ext;
    }

    /**
     * Store raw content as the single file of an area item, replacing what was there.
     *
     * @param context_module $context module context
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0 (cover)
     * @param string $content file bytes
     * @return stored_file
     * @throws moodle_exception invalidmediafile when the content is not an accepted type
     */
    public static function save_file_from_string(
        context_module $context,
        string $filearea,
        int $itemid,
        string $content
    ): stored_file {
        $ext = self::sniff_media_type(substr($content, 0, 16));
        if (!self::type_allowed($filearea, $ext)) {
            throw new moodle_exception('invalidmediafile', 'mod_whakaharatau');
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, $filearea, $itemid);
        $filename = self::stored_filename($filearea, $ext);
        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => mimeinfo('type', $filename),
        ], $content);
    }

    /**
     * Copy a stored file (e.g. from a draft area or an unpacked ZIP) as the single file of an area item.
     *
     * @param context_module $context module context
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0 (cover)
     * @param stored_file $source file to copy (left in place)
     * @return stored_file
     * @throws moodle_exception invalidmediafile when the content is not an accepted type
     */
    public static function save_file_from_stored_file(
        context_module $context,
        string $filearea,
        int $itemid,
        stored_file $source
    ): stored_file {
        $ext = self::sniff_media_type(self::read_head($source));
        if (!self::type_allowed($filearea, $ext)) {
            throw new moodle_exception('invalidmediafile', 'mod_whakaharatau');
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, $filearea, $itemid);
        $filename = self::stored_filename($filearea, $ext);
        return $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'mimetype' => mimeinfo('type', $filename),
        ], $source);
    }

    /**
     * Save the file of a filemanager draft area as the single file of an area item.
     *
     * An empty draft deletes the stored file. Only a single file at "/" is accepted, checked by content.
     *
     * @param context_module $context module context
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0 (cover)
     * @param int $draftitemid the current user's draft item id
     * @return stored_file|null the stored file, or null when the draft was empty
     * @throws moodle_exception invalidmediafile when the draft file is not an accepted type
     */
    public static function save_file_from_draft(
        context_module $context,
        string $filearea,
        int $itemid,
        int $draftitemid
    ): ?stored_file {
        global $USER;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        $files = array_values(array_filter($files, fn(stored_file $f) => $f->get_filepath() === '/'));
        if (!$files) {
            $fs->delete_area_files($context->id, self::COMPONENT, $filearea, $itemid);
            return null;
        }
        if (count($files) > 1) {
            throw new moodle_exception('invalidmediafile', 'mod_whakaharatau');
        }
        $existing = self::get_file($context, $filearea, $itemid);
        if ($existing && $existing->get_contenthash() === $files[0]->get_contenthash()) {
            return $existing;
        }
        return self::save_file_from_stored_file($context, $filearea, $itemid, $files[0]);
    }

    /**
     * Prepare a draft area holding the current file of an area item, for a filemanager element.
     *
     * @param context_module|null $context module context (null for a new activity)
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0
     * @return int draft item id
     */
    public static function prepare_draft(?context_module $context, string $filearea, int $itemid): int {
        $draftitemid = 0;
        file_prepare_draft_area(
            $draftitemid,
            $context ? $context->id : null,
            self::COMPONENT,
            $filearea,
            $itemid,
            self::filemanager_options($filearea)
        );
        return (int) $draftitemid;
    }

    /**
     * The single file of an area item.
     *
     * @param context_module $context module context
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0
     * @return stored_file|null
     */
    public static function get_file(context_module $context, string $filearea, int $itemid): ?stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            self::COMPONENT,
            $filearea,
            $itemid,
            'id DESC',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Pluginfile URL of the single file of an area item. The path carries a revision so a replaced file is
     * never served from the browser cache: pluginfile.php/{ctx}/mod_whakaharatau/{area}/{itemid}/{rev}/{name}.
     *
     * @param context_module $context module context
     * @param string $filearea one of the AREA_* constants
     * @param int $itemid slide id, option id or 0
     * @return moodle_url|null
     */
    public static function get_file_url(context_module $context, string $filearea, int $itemid): ?moodle_url {
        $file = self::get_file($context, $filearea, $itemid);
        if (!$file) {
            return null;
        }
        return moodle_url::make_pluginfile_url(
            $context->id,
            self::COMPONENT,
            $filearea,
            $itemid,
            '/' . $file->get_timemodified() . '/',
            $file->get_filename()
        );
    }

    // Validation.

    /**
     * Problems that stop a slide from being played.
     *
     * @param context_module $context module context
     * @param stdClass $slide slide record
     * @param stdClass[] $options the slide's option records
     * @param array|null $present files known to exist, from file_index(); null = look each file up
     * @return string[] PROBLEM_* codes (each is also a lang string key), empty when the slide is fine
     */
    public static function validate_slide(
        context_module $context,
        stdClass $slide,
        array $options,
        ?array $present = null
    ): array {
        $hasfile = function (string $filearea, int $itemid) use ($context, $present): bool {
            if ($present !== null) {
                return isset($present[$filearea][$itemid]);
            }
            return self::get_file($context, $filearea, $itemid) !== null;
        };
        $problems = [];
        if (!$hasfile(self::AREA_IMAGE, (int) $slide->id)) {
            $problems[] = self::PROBLEM_NOIMAGE;
        }
        if (empty($slide->decorative) && trim((string) $slide->alttext) === '') {
            $problems[] = self::PROBLEM_NOALT;
        }
        if (count($options) < self::MIN_OPTIONS) {
            $problems[] = self::PROBLEM_TOOFEW;
        }
        if (count($options) > self::MAX_OPTIONS) {
            $problems[] = self::PROBLEM_TOOMANY;
        }
        $correct = count(array_filter($options, fn($o) => !empty($o->iscorrect)));
        if ($correct === 0) {
            $problems[] = self::PROBLEM_NOCORRECT;
        } else if ($correct > 1) {
            $problems[] = self::PROBLEM_MANYCORRECT;
        }
        foreach ($options as $option) {
            if (!$hasfile(self::AREA_AUDIO, (int) $option->id)) {
                $problems[] = self::PROBLEM_NOAUDIO;
                break;
            }
        }
        return $problems;
    }

    /**
     * Item ids that hold a file in the image and audio areas, read with one query per area.
     *
     * @param context_module $context module context
     * @return array filearea => [itemid => true]
     */
    public static function file_index(context_module $context): array {
        $fs = get_file_storage();
        $index = [];
        foreach ([self::AREA_IMAGE, self::AREA_AUDIO] as $filearea) {
            $index[$filearea] = [];
            foreach ($fs->get_area_files($context->id, self::COMPONENT, $filearea, false, 'itemid, id', false) as $file) {
                $index[$filearea][(int) $file->get_itemid()] = true;
            }
        }
        return $index;
    }

    /**
     * Problems of every slide of an activity.
     *
     * @param context_module $context module context
     * @param int $whakaharatauid instance id
     * @return array slideid => string[] PROBLEM_* codes; slides without problems are left out
     */
    public static function get_problems(context_module $context, int $whakaharatauid): array {
        $options = self::get_options_for_instance($whakaharatauid);
        $present = self::file_index($context);
        $problems = [];
        foreach (self::get_slides($whakaharatauid) as $slide) {
            $found = self::validate_slide($context, $slide, $options[(int) $slide->id] ?? [], $present);
            if ($found) {
                $problems[(int) $slide->id] = $found;
            }
        }
        return $problems;
    }

    /**
     * Whether learners can attempt the activity: at least one slide and no slide with a problem.
     *
     * @param stdClass $instance whakaharatau record
     * @param context_module $context module context
     * @return bool
     */
    public static function is_playable(stdClass $instance, context_module $context): bool {
        if (!self::count_slides((int) $instance->id)) {
            return false;
        }
        return !self::get_problems($context, (int) $instance->id);
    }

    // Helpers.

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

    /**
     * Clean a plain-text field (alt text, prompt, transcript): no HTML, trimmed. Every way in uses it: the slide
     * form, the importer and restore (where a crafted backup is input like any other).
     *
     * @param mixed $value raw value
     * @return string
     */
    public static function clean_text($value): string {
        return trim(clean_param((string) $value, PARAM_TEXT));
    }

    /**
     * Clean a slide row from a backup in place, as create_slide() cleans form and import data.
     *
     * @param stdClass $data slide fields
     * @return void
     */
    public static function clean_slide_fields(stdClass $data): void {
        $data->alttext = self::clean_text($data->alttext ?? '');
        $data->prompt = self::clean_text($data->prompt ?? '');
        $data->promptformat = FORMAT_PLAIN;
        $data->promptlang = self::clean_lang($data->promptlang ?? null);
        $data->decorative = empty($data->decorative) ? 0 : 1;
        // A backup from before the fade settings: the fade every picture had then.
        $data->fade = empty($data->fade ?? 1) ? 0 : 1;
        $data->fadewidth = instance_settings::clamp_fade($data->fadewidth ?? instance_settings::FADE_WIDTH);
        $data->version = max(1, (int) ($data->version ?? 1));
        $data->sortorder = (int) ($data->sortorder ?? 0);
    }

    /**
     * Clean an option row from a backup in place, as create_option() cleans form and import data.
     *
     * @param stdClass $data option fields
     * @return void
     */
    public static function clean_option_fields(stdClass $data): void {
        $data->transcript = self::clean_text($data->transcript ?? '');
        $data->iscorrect = empty($data->iscorrect) ? 0 : 1;
        $data->sortorder = (int) ($data->sortorder ?? 0);
    }

    /**
     * Clean a language code (e.g. "mi", "en_us"), or null.
     *
     * @param mixed $lang raw value
     * @return string|null
     */
    private static function clean_lang($lang): ?string {
        $lang = clean_param((string) $lang, PARAM_ALPHANUMEXT);
        return $lang === '' ? null : \core_text::substr($lang, 0, 30);
    }

    /**
     * Next free slide sortorder.
     *
     * @param int $whakaharatauid instance id
     * @return int
     */
    private static function next_slide_sortorder(int $whakaharatauid): int {
        global $DB;
        $max = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {whakaharatau_slide} WHERE whakaharatauid = ?',
            [$whakaharatauid]
        );
        return $max === null || $max === false ? 0 : (int) $max + 1;
    }

    /**
     * Next free option sortorder in a slide.
     *
     * @param int $slideid slide id
     * @return int
     */
    private static function next_option_sortorder(int $slideid): int {
        global $DB;
        $max = $DB->get_field_sql('SELECT MAX(sortorder) FROM {whakaharatau_option} WHERE slideid = ?', [$slideid]);
        return $max === null || $max === false ? 0 : (int) $max + 1;
    }
}
