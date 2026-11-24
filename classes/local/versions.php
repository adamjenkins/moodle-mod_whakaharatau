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
use moodle_url;
use stdClass;
use stored_file;

/**
 * Versions of a slide's content, so that answers given before and after a change can be compared.
 *
 * A version holds what the learner saw and heard: picture, alternative text, prompt, prompt audio, clips,
 * transcripts and which clip was correct, with copies of the files. The current content of a slide is version
 * whakaharatau_slide.version; its row is made when it is first needed (first answer, edit or deletion). A change to
 * the content starts a new version once the current one has answers; before that the version is just updated. The
 * fade and the order of slides and clips are not content.
 *
 * Files are in the area "version", item id = version row id: image.ext, prompt.ext, audio-1.ext ... in the order of
 * the version's options.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class versions {
    /** @var string File area of the version copies. */
    const AREA = 'version';

    /**
     * Make sure the slide's current version has a row, before its content changes or an answer points at it.
     *
     * @param context_module $context module context
     * @param int $slideid slide id
     * @return void
     */
    public static function ensure(context_module $context, int $slideid): void {
        global $DB;
        $slide = slides::get_slide($slideid, IGNORE_MISSING);
        if (!$slide || self::exists($slide)) {
            return;
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_whakaharatau')->get_lock('version' . $slideid, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $slide = slides::get_slide($slideid);
            if (!self::exists($slide)) {
                // Version 1 is as old as the slide.
                $time = (int) $slide->version === 1 ? (int) $slide->timecreated : time();
                self::store($context, $slide, (int) $slide->version, $time);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Record the slide's content after it was edited: a new version when the current one has answers, else the
     * current version brought up to date. Nothing happens when the content is the same.
     *
     * Call ensure() before the edit, so that the content before it is on record.
     *
     * @param context_module $context module context
     * @param int $slideid slide id
     * @return int the slide's current version
     */
    public static function sync(context_module $context, int $slideid): int {
        global $DB;
        $slide = slides::get_slide($slideid);
        $current = self::get_row((int) $slide->whakaharatauid, $slideid, (int) $slide->version);
        if (!$current) {
            self::ensure($context, $slideid);
            return (int) $slide->version;
        }
        if ($current->fingerprint === self::fingerprint($context, $slide)) {
            return (int) $slide->version;
        }
        $answered = $DB->record_exists('whakaharatau_response', ['slideid' => $slideid, 'version' => $slide->version]);
        if (!$answered) {
            self::store($context, $slide, (int) $slide->version, (int) $current->timecreated, $current);
            return (int) $slide->version;
        }
        $version = (int) $slide->version + 1;
        self::store($context, $slide, $version, time());
        $DB->set_field('whakaharatau_slide', 'version', $version, ['id' => $slideid]);
        return $version;
    }

    /**
     * The version an answer belongs to: the one the learner's page showed, if it is a real one, else the current.
     *
     * @param stdClass $slide slide record
     * @param int $seen version the page showed, 0 = not known
     * @return int
     */
    public static function answered_version(stdClass $slide, int $seen): int {
        return $seen >= 1 && $seen <= (int) $slide->version ? $seen : (int) $slide->version;
    }

    /**
     * All version rows of an activity.
     *
     * @param int $whakaharatauid instance id
     * @return stdClass[] rows by [slideid][version]; options is decoded to a list of stdClass (id, transcript, correct)
     */
    public static function get_all(int $whakaharatauid): array {
        global $DB;
        $rows = [];
        $records = $DB->get_records('whakaharatau_version', ['whakaharatauid' => $whakaharatauid], 'slideid, version');
        foreach ($records as $record) {
            $record->options = self::decode_options((string) $record->options);
            $rows[(int) $record->slideid][(int) $record->version] = $record;
        }
        return $rows;
    }

    /**
     * Read a version's stored options.
     *
     * @param string $json stored JSON
     * @return stdClass[] list of id (int), transcript (plain text) and correct (bool)
     */
    public static function decode_options(string $json): array {
        $options = [];
        foreach ((array) json_decode($json) as $option) {
            if (is_object($option) && isset($option->id) && count($options) < slides::MAX_OPTIONS) {
                $options[] = (object) [
                    'id' => (int) $option->id,
                    'transcript' => slides::clean_text($option->transcript ?? ''),
                    'correct' => !empty($option->correct),
                ];
            }
        }
        return $options;
    }

    /**
     * URL of a version's picture, prompt or clip.
     *
     * @param context_module $context module context
     * @param int $versionid version row id
     * @param string $name 'image', 'prompt' or 'audio-N' (1-based)
     * @return moodle_url|null
     */
    public static function get_file_url(context_module $context, int $versionid, string $name): ?moodle_url {
        foreach (self::get_files($context, $versionid) as $file) {
            if (pathinfo($file->get_filename(), PATHINFO_FILENAME) === $name) {
                return moodle_url::make_pluginfile_url(
                    $context->id,
                    slides::COMPONENT,
                    self::AREA,
                    $versionid,
                    '/' . $file->get_timemodified() . '/',
                    $file->get_filename()
                );
            }
        }
        return null;
    }

    /**
     * After a restore: the fingerprints of the current versions, which hold the new option ids.
     *
     * @param context_module $context module context
     * @param int $whakaharatauid instance id
     * @return void
     */
    public static function refresh_fingerprints(context_module $context, int $whakaharatauid): void {
        global $DB;
        foreach (slides::get_slides($whakaharatauid) as $slide) {
            $DB->set_field('whakaharatau_version', 'fingerprint', self::fingerprint($context, $slide), [
                'whakaharatauid' => $whakaharatauid, 'slideid' => $slide->id, 'version' => $slide->version]);
        }
    }

    /**
     * Delete versions of deleted slides that no answer points at any more.
     *
     * @param int $whakaharatauid instance id
     * @return void
     */
    public static function purge_orphans(int $whakaharatauid): void {
        global $DB;
        $sql = "SELECT v.id
                  FROM {whakaharatau_version} v
             LEFT JOIN {whakaharatau_slide} s ON s.id = v.slideid
                 WHERE v.whakaharatauid = :wid AND s.id IS NULL
                       AND NOT EXISTS (SELECT 1
                                         FROM {whakaharatau_response} r
                                        WHERE r.slideid = v.slideid AND r.version = v.version)";
        $ids = $DB->get_fieldset_sql($sql, ['wid' => $whakaharatauid]);
        if (!$ids) {
            return;
        }
        $cm = get_coursemodule_from_instance('whakaharatau', $whakaharatauid, 0, false, IGNORE_MISSING);
        $context = $cm ? context_module::instance($cm->id, IGNORE_MISSING) : null;
        foreach ($ids as $id) {
            if ($context) {
                get_file_storage()->delete_area_files($context->id, slides::COMPONENT, self::AREA, (int) $id);
            }
            $DB->delete_records('whakaharatau_version', ['id' => $id]);
        }
    }

    /**
     * Delete every version of an activity.
     *
     * @param context_module|null $context module context, null when it no longer exists
     * @param int $whakaharatauid instance id
     * @return void
     */
    public static function delete_all(?context_module $context, int $whakaharatauid): void {
        global $DB;
        $DB->delete_records('whakaharatau_version', ['whakaharatauid' => $whakaharatauid]);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, slides::COMPONENT, self::AREA);
        }
    }

    // Helpers.

    /**
     * Whether the slide's current version has a row.
     *
     * @param stdClass $slide slide record
     * @return bool
     */
    private static function exists(stdClass $slide): bool {
        global $DB;
        return $DB->record_exists('whakaharatau_version', ['whakaharatauid' => $slide->whakaharatauid,
            'slideid' => $slide->id, 'version' => $slide->version]);
    }

    /**
     * One version row.
     *
     * @param int $whakaharatauid instance id
     * @param int $slideid slide id
     * @param int $version version number
     * @return stdClass|false
     */
    private static function get_row(int $whakaharatauid, int $slideid, int $version) {
        global $DB;
        return $DB->get_record('whakaharatau_version', ['whakaharatauid' => $whakaharatauid, 'slideid' => $slideid,
            'version' => $version]);
    }

    /**
     * Files of a version.
     *
     * @param context_module $context module context
     * @param int $versionid version row id
     * @return stored_file[]
     */
    private static function get_files(context_module $context, int $versionid): array {
        return get_file_storage()->get_area_files(
            $context->id,
            slides::COMPONENT,
            self::AREA,
            $versionid,
            'filename',
            false
        );
    }

    /**
     * A fingerprint of everything the learner sees and hears on a slide.
     *
     * @param context_module $context module context
     * @param stdClass $slide slide record
     * @return string sha1
     */
    private static function fingerprint(context_module $context, stdClass $slide): string {
        $hash = function (string $area, int $itemid) use ($context): string {
            $file = slides::get_file($context, $area, $itemid);
            return $file ? $file->get_contenthash() : '';
        };
        $options = [];
        foreach (slides::get_options((int) $slide->id) as $option) {
            $options[(int) $option->id] = [(string) $option->transcript, (int) $option->iscorrect,
                $hash(slides::AREA_AUDIO, (int) $option->id)];
        }
        // The order of the clips is not content.
        ksort($options);
        return sha1(json_encode([
            (string) $slide->alttext,
            (int) $slide->decorative,
            (string) $slide->prompt,
            (string) $slide->promptlang,
            $hash(slides::AREA_IMAGE, (int) $slide->id),
            $hash(slides::AREA_PROMPT, (int) $slide->id),
            $options,
        ]));
    }

    /**
     * Store the slide's content as a version, with copies of its files (Moodle keeps one copy of equal content).
     *
     * @param context_module $context module context
     * @param stdClass $slide slide record
     * @param int $version version number
     * @param int $time when the version began
     * @param stdClass|null $row existing row to overwrite
     * @return void
     */
    private static function store(
        context_module $context,
        stdClass $slide,
        int $version,
        int $time,
        ?stdClass $row = null
    ): void {
        global $DB;
        $files = [];
        $options = [];
        foreach (slides::get_options((int) $slide->id) as $option) {
            $options[] = ['id' => (int) $option->id, 'transcript' => (string) $option->transcript,
                'correct' => (int) $option->iscorrect];
            $files['audio-' . count($options)] = slides::get_file($context, slides::AREA_AUDIO, (int) $option->id);
        }
        $files['image'] = slides::get_file($context, slides::AREA_IMAGE, (int) $slide->id);
        $files['prompt'] = slides::get_file($context, slides::AREA_PROMPT, (int) $slide->id);
        $record = (object) [
            'whakaharatauid' => (int) $slide->whakaharatauid,
            'slideid' => (int) $slide->id,
            'version' => $version,
            'prompt' => (string) $slide->prompt,
            'promptlang' => $slide->promptlang,
            'alttext' => (string) $slide->alttext,
            'decorative' => (int) $slide->decorative,
            'options' => json_encode($options),
            'fingerprint' => self::fingerprint($context, $slide),
            'timecreated' => $time,
        ];
        if ($row) {
            $record->id = $row->id;
            $DB->update_record('whakaharatau_version', $record);
        } else {
            $record->id = $DB->insert_record('whakaharatau_version', $record);
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, slides::COMPONENT, self::AREA, (int) $record->id);
        foreach (array_filter($files) as $name => $file) {
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => slides::COMPONENT,
                'filearea' => self::AREA,
                'itemid' => (int) $record->id,
                'filepath' => '/',
                'filename' => $name . '.' . strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)),
            ], $file);
        }
    }
}
