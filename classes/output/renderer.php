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
use core\output\plugin_renderer_base;
use stdClass;

/**
 * Renderer of mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /** @var string[] Strings used by amd/src/player.js. */
    const JS_STRINGS = [
        'audioerror', 'backtocourse', 'backtostart', 'chooseoption', 'correctwas', 'exit', 'nextavailable',
        'nextround', 'nextslide', 'optionchosen', 'optioncorrect', 'optionrevealed', 'optionwrong', 'pauseoption',
        'pauseprompt', 'playoption', 'playprompt', 'resultheading', 'reviewcorrect', 'reviewheading', 'reviewrevealed',
        'reviewslide', 'reviewslideround', 'reviewwrong', 'roundagain', 'roundlabel', 'roundmissed', 'roundmissedone',
        'scorelabel', 'seeresults', 'slidelabel', 'slidelabelround', 'startlastscore', 'startlastsession',
        'startround', 'statbeststreak', 'statfirsttry', 'statrounds', 'statslidesplayed', 'summaryannouncement',
        'summaryheading', 'thumbcorrect', 'thumbwrong', 'triesleft', 'triesleftone', 'tryanother',
    ];

    /**
     * The player with its start screen, and its JavaScript queued.
     *
     * @param stdClass $instance whakaharatau record
     * @param cm_info $cm course module
     * @param bool $inline true on the course page, false on view.php
     * @return string HTML
     */
    public function render_player(stdClass $instance, cm_info $cm, bool $inline = false): string {
        $player = new player($instance, $cm, $inline);
        $html = $this->render_from_template('mod_whakaharatau/player', $player->export_for_template($this));
        // Strings the player uses, sent with the page so it needs no extra request for them.
        $this->page->requires->strings_for_js(self::JS_STRINGS, 'mod_whakaharatau');
        $this->page->requires->js_call_amd('mod_whakaharatau/player', 'init', [(int) $cm->id]);
        return $html;
    }

    /**
     * The player for the course page (called by whakaharatau_cm_info_view() when the teacher chose inline display).
     *
     * Returns formatted HTML for cm_info::set_content() and queues the JavaScript. The caller has checked
     * mod/whakaharatau:view; this does not call require_login().
     *
     * @param cm_info $cm course module
     * @return string HTML, empty if the instance is missing
     */
    public function render_inline_player(cm_info $cm): string {
        global $DB;
        $instance = $DB->get_record('whakaharatau', ['id' => $cm->instance]);
        if (!$instance) {
            return '';
        }
        return $this->render_player($instance, $cm, true);
    }
}
