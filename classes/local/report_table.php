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

use moodle_url;
use renderer_base;

/**
 * A report table: sortable by every column heading, paged, and downloadable in the same order.
 *
 * Every cell holds plain text; the template and \core\dataformat escape it.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_table {
    /** @var int Rows per page. */
    const PERPAGE = 50;

    /** @var string caption, read by screen readers */
    protected $caption;
    /** @var array[] key => label, numeric, show (both, screen or download) */
    protected $columns = [];
    /** @var array[] rows: cells (key => cell), and id for a row that can be selected */
    protected $rows = [];
    /** @var string column sorted by, '' = the order the rows were added in */
    protected $sort = '';
    /** @var bool descending */
    protected $desc = false;

    /**
     * Constructor.
     *
     * @param string $caption plain text
     */
    public function __construct(string $caption) {
        $this->caption = $caption;
    }

    /**
     * Add a column.
     *
     * @param string $key column key
     * @param string $label plain-text heading
     * @param bool $numeric whether to align it right
     * @param string $show both, screen or download
     * @return self
     */
    public function column(string $key, string $label, bool $numeric = false, string $show = 'both'): self {
        $this->columns[$key] = ['label' => $label, 'numeric' => $numeric, 'show' => $show];
        return $this;
    }

    /**
     * A cell.
     *
     * @param string|int|float|null $text what is shown
     * @param string|int|float|null $sort value to sort by, default the text; null or '' sorts last
     * @param array $extra url, imageurl, percent (a bar), audios (list of label and url), download (value for files)
     * @return array
     */
    public static function cell($text, $sort = null, array $extra = []): array {
        $text = (string) ($text ?? '');
        return ['text' => $text, 'sort' => $sort ?? $text] + $extra;
    }

    /**
     * A percentage cell: "75%" with a bar on screen, the number in files.
     *
     * @param float|null $percent 0 to 100, or null for none
     * @param bool $bar whether to draw the bar
     * @param bool $signed whether to show a plus sign (for a change)
     * @return array
     */
    public static function percent(?float $percent, bool $bar = false, bool $signed = false): array {
        if ($percent === null) {
            return self::cell('', '');
        }
        $percent = round($percent, 1);
        $text = ($signed && $percent > 0 ? '+' : '') . format_float($percent, 1, true, true) .
            ($signed ? '' : '%');
        return self::cell($text, $percent, ['download' => $percent] + ($bar ? ['percent' => $percent] : []));
    }

    /**
     * A date cell.
     *
     * @param int $time timestamp, 0 for none
     * @return array
     */
    public static function date(int $time): array {
        return self::cell($time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : '', $time ?: '');
    }

    /**
     * Add a row.
     *
     * @param array[] $cells key => cell()
     * @param int $id id for a row that can be selected, else 0
     * @return void
     */
    public function add_row(array $cells, int $id = 0): void {
        $this->rows[] = ['cells' => $cells, 'id' => $id];
    }

    /**
     * Number of rows.
     *
     * @return int
     */
    public function count_rows(): int {
        return count($this->rows);
    }

    /**
     * Sort by a column. Unknown columns are ignored. Empty values come last in both directions.
     *
     * @param string $key column key
     * @param bool $desc descending
     * @return self
     */
    public function sort_by(string $key, bool $desc): self {
        if (!isset($this->columns[$key]) || $this->columns[$key]['show'] === 'download') {
            return $this;
        }
        $this->sort = $key;
        $this->desc = $desc;
        $values = [];
        $empty = [];
        $numeric = true;
        foreach ($this->rows as $i => $row) {
            $value = $row['cells'][$key]['sort'] ?? '';
            if ($value === '') {
                $empty[] = $i;
                continue;
            }
            $numeric = $numeric && is_numeric($value);
            $values[$i] = $value;
        }
        if ($numeric) {
            asort($values, SORT_NUMERIC);
        } else {
            $values = array_map('strval', $values);
            \core_collator::asort($values, \core_collator::SORT_NATURAL);
        }
        $order = array_keys($values);
        if ($desc) {
            $order = array_reverse($order);
        }
        $this->rows = array_map(fn($i) => $this->rows[$i], array_merge($order, $empty));
        return $this;
    }

    /**
     * Columns of the downloaded file.
     *
     * @return string[] key => heading
     */
    public function get_download_columns(): array {
        return array_map(
            fn($column) => $column['label'],
            array_filter($this->columns, fn($column) => $column['show'] !== 'screen')
        );
    }

    /**
     * Rows of the downloaded file.
     *
     * @return array[] list of key => value
     */
    public function get_download_rows(): array {
        $keys = array_keys($this->get_download_columns());
        $rows = [];
        foreach ($this->rows as $row) {
            $values = [];
            foreach ($keys as $key) {
                $cell = $row['cells'][$key] ?? self::cell('');
                $values[$key] = (string) ($cell['download'] ?? $cell['text']);
            }
            $rows[] = $values;
        }
        return $rows;
    }

    /**
     * Data for mod_whakaharatau/report_table.
     *
     * @param moodle_url $url page URL, with every filter, without sort and page
     * @param int $page page to show, 0-based
     * @return array
     */
    public function export(moodle_url $url, int $page = 0): array {
        $columns = array_filter($this->columns, fn($column) => $column['show'] !== 'download');
        $heads = [];
        foreach ($columns as $key => $column) {
            $active = $key === $this->sort;
            $heads[] = [
                'label' => $column['label'],
                'numeric' => $column['numeric'],
                'url' => (new moodle_url($url, ['tsort' => $key, 'tdir' => $active && !$this->desc ? 'desc' : 'asc']))
                    ->out(false),
                'ariasort' => $active ? ($this->desc ? 'descending' : 'ascending') : 'none',
                'asc' => $active && !$this->desc,
                'desc' => $active && $this->desc,
            ];
        }
        $rows = [];
        $first = array_key_first($columns);
        foreach (array_slice($this->rows, $page * self::PERPAGE, self::PERPAGE) as $row) {
            $cells = [];
            foreach ($columns as $key => $column) {
                $cell = $row['cells'][$key] ?? self::cell('');
                $cells[] = [
                    'text' => $cell['text'],
                    'url' => $cell['url'] ?? '',
                    'imageurl' => $cell['imageurl'] ?? '',
                    'hasbar' => isset($cell['percent']),
                    'percent' => $cell['percent'] ?? 0,
                    'audios' => $cell['audios'] ?? [],
                    'head' => $key === $first,
                    'numeric' => $column['numeric'],
                ];
            }
            $rows[] = ['cells' => $cells, 'id' => $row['id'], 'label' => $row['cells'][$first]['text'] ?? ''];
        }
        return [
            'caption' => $this->caption,
            'columns' => $heads,
            'rows' => $rows,
            'hasrows' => !empty($rows),
        ];
    }
}
