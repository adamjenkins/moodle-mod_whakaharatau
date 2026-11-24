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
 * Export of an activity's slides as a ZIP with `slides.csv` and a `media/` folder, in the format that
 * {@see importer} reads, so a set round-trips between courses and sites.
 *
 * Media are named by slide number (`media/01.jpg`, `media/01-prompt.mp3`, `media/01-a.mp3` ...): the names
 * never say which clip is correct; the manifest's `correct` column does.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exporter {
    /** @var string Folder of the media files inside the ZIP. */
    const MEDIA_DIR = 'media/';

    /**
     * The ZIP contents: archive path => stored_file, or => [string content] for the manifest.
     *
     * @param context_module $context module context
     * @param stdClass $instance whakaharatau record
     * @return array archive path => stored_file|string[]
     */
    public static function get_archive_files(context_module $context, stdClass $instance): array {
        $slides = slides::get_slides((int) $instance->id);
        $options = slides::get_options_for_instance((int) $instance->id);
        $maxoptions = slides::MIN_OPTIONS;
        foreach ($options as $slideoptions) {
            $maxoptions = max($maxoptions, count($slideoptions));
        }
        $width = max(2, strlen((string) count($slides)));

        $header = ['image', 'alt', 'decorative', 'prompt', 'promptlang', 'promptaudio', 'correct', 'fade',
            'fadewidth'];
        for ($i = 1; $i <= $maxoptions; $i++) {
            $header[] = 'audio' . $i;
        }
        for ($i = 1; $i <= $maxoptions; $i++) {
            $header[] = 'transcript' . $i;
        }

        $files = [];
        $rows = [$header];
        $number = 0;
        foreach ($slides as $slide) {
            $number++;
            $base = str_pad((string) $number, $width, '0', STR_PAD_LEFT);
            $row = array_fill_keys($header, '');
            $row['alt'] = (string) $slide->alttext;
            $row['decorative'] = empty($slide->decorative) ? '0' : '1';
            $row['fade'] = empty($slide->fade) ? '0' : '1';
            $row['fadewidth'] = (string) (int) $slide->fadewidth;
            $row['prompt'] = (string) $slide->prompt;
            $row['promptlang'] = (string) ($slide->promptlang ?? '');

            if ($file = slides::get_file($context, slides::AREA_IMAGE, (int) $slide->id)) {
                $path = self::MEDIA_DIR . $base . '.' . self::extension($file);
                $files[$path] = $file;
                $row['image'] = $path;
            }
            if ($file = slides::get_file($context, slides::AREA_PROMPT, (int) $slide->id)) {
                $path = self::MEDIA_DIR . $base . '-prompt.' . self::extension($file);
                $files[$path] = $file;
                $row['promptaudio'] = $path;
            }
            $n = 0;
            foreach ($options[(int) $slide->id] ?? [] as $option) {
                $n++;
                $row['transcript' . $n] = (string) $option->transcript;
                if ($file = slides::get_file($context, slides::AREA_AUDIO, (int) $option->id)) {
                    $path = self::MEDIA_DIR . $base . '-' . self::letter($n) . '.' . self::extension($file);
                    $files[$path] = $file;
                    $row['audio' . $n] = $path;
                }
                if (!empty($option->iscorrect)) {
                    $row['correct'] = (string) $n;
                }
            }
            $rows[] = array_values($row);
        }
        $files[importer::MANIFEST] = [self::to_csv($rows)];
        return $files;
    }

    /**
     * Write the export ZIP to a file in this request's temporary directory.
     *
     * The caller has checked mod/whakaharatau:manage.
     *
     * @param context_module $context module context
     * @param stdClass $instance whakaharatau record
     * @return string full path of the ZIP
     * @throws moodle_exception when the ZIP cannot be written
     */
    public static function export_to_file(context_module $context, stdClass $instance): string {
        $path = make_request_directory() . '/slides.zip';
        $packer = get_file_packer('application/zip');
        if (!$packer->archive_to_pathname(self::get_archive_files($context, $instance), $path)) {
            throw new moodle_exception('exportfailed', 'mod_whakaharatau');
        }
        return $path;
    }

    /**
     * The download file name for an activity's export.
     *
     * @param stdClass $instance whakaharatau record
     * @param context_module $context module context
     * @return string e.g. "Kai-slides.zip"
     */
    public static function get_filename(stdClass $instance, context_module $context): string {
        $name = clean_filename(format_string($instance->name, true, ['context' => $context, 'escape' => false]));
        $name = trim(str_replace(' ', '-', $name), '-');
        return ($name === '' ? 'whakaharatau' : $name) . '-slides.zip';
    }

    /**
     * CSV text of rows (UTF-8, comma separated, CRLF line ends as spreadsheets expect).
     *
     * A cell starting with = + - or @ gets a leading ' (\core\dataformat::escape_spreadsheet_formula()), so a
     * spreadsheet shows the text instead of running it as a formula. The importer removes that ' again.
     *
     * @param array[] $rows rows of cells
     * @return string
     */
    public static function to_csv(array $rows): string {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            $row = array_map(fn($cell) => \core\dataformat::escape_spreadsheet_formula($cell), $row);
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Extension of a stored media file (its stored name is always neutral, e.g. "audio.mp3").
     *
     * @param \stored_file $file file
     * @return string
     */
    private static function extension(\stored_file $file): string {
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        return $ext === '' ? 'bin' : $ext;
    }

    /**
     * Option letter: 1 => a, 2 => b ...
     *
     * @param int $n 1-based option number
     * @return string
     */
    private static function letter(int $n): string {
        return chr(ord('a') + $n - 1);
    }
}
