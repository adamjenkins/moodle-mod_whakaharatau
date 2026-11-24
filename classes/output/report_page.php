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

use mod_whakaharatau\local\report_table;
use moodle_url;

/**
 * What the report pages have in common: reading the sort order, downloading, and drawing a table.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_page {
    /**
     * Sort a table as the URL asks, and send it as a file when the URL asks for that.
     *
     * @param report_table $table table
     * @param string $filename file name without extension
     * @return array sort parameters for links
     */
    public static function prepare(report_table $table, string $filename): array {
        $sort = optional_param('tsort', '', PARAM_ALPHANUMEXT);
        $desc = optional_param('tdir', 'asc', PARAM_ALPHA) === 'desc';
        if ($sort !== '') {
            $table->sort_by($sort, $desc);
        }
        $download = optional_param('download', '', PARAM_ALPHA);
        if ($download !== '') {
            \core\dataformat::download_data(
                clean_filename($filename),
                $download,
                $table->get_download_columns(),
                new \ArrayIterator($table->get_download_rows())
            );
            exit;
        }
        return $sort === '' ? [] : ['tsort' => $sort, 'tdir' => $desc ? 'desc' : 'asc'];
    }

    /**
     * A table with its paging bar and download menu.
     *
     * @param report_table $table table, sorted
     * @param moodle_url $url page URL with every filter, without sort and page
     * @param array $sort prepare() result
     * @param array $extra more data for the template, e.g. for deleting
     * @return string HTML
     */
    public static function render(report_table $table, moodle_url $url, array $sort, array $extra = []): string {
        global $OUTPUT;
        $total = $table->count_rows();
        $page = max(0, optional_param('page', 0, PARAM_INT));
        if ($page * report_table::PERPAGE >= $total) {
            $page = 0;
        }
        $html = $OUTPUT->render_from_template('mod_whakaharatau/report_table', $table->export($url, $page) + $extra);
        $html .= $OUTPUT->paging_bar($total, $page, report_table::PERPAGE, new moodle_url($url, $sort));
        if ($total) {
            $html .= $OUTPUT->download_dataformat_selector(
                get_string('report_download', 'mod_whakaharatau'),
                new moodle_url($url->out_omit_querystring()),
                'download',
                $url->params() + $sort
            );
        }
        return $html;
    }

    /**
     * A row of links for the navigation template.
     *
     * @param moodle_url $url base URL
     * @param string $param parameter the links set
     * @param string[] $choices value => plain-text label
     * @param string|int $active active value
     * @return array[]
     */
    public static function links(moodle_url $url, string $param, array $choices, $active): array {
        $links = [];
        foreach ($choices as $value => $label) {
            $links[] = ['url' => (new moodle_url($url, [$param => $value]))->out(false), 'label' => $label,
                'active' => (string) $value === (string) $active];
        }
        return $links;
    }
}
