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

/**
 * Behat steps for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for mod_whakaharatau: page resolution and keyboard-only use of the player.
 *
 * The player steps drive the first visible player on the page (view.php has one; a course page may have
 * several inline players, of which the first is used).
 *
 * @package    mod_whakaharatau
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_whakaharatau extends behat_base {
    /**
     * Resolve a page of an activity for "I am on the "<activity>" "mod_whakaharatau > <page>" page".
     *
     * Pages: View, Edit slides, Import, Report.
     *
     * @param string $type page type
     * @param string $identifier activity idnumber or name
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $cm = $this->get_cm_by_activity_name('whakaharatau', $identifier);
        switch (core_text::strtolower($type)) {
            case 'view':
                return new moodle_url('/mod/whakaharatau/view.php', ['id' => $cm->id]);
            case 'edit slides':
                return new moodle_url('/mod/whakaharatau/edit.php', ['id' => $cm->id]);
            case 'import':
                return new moodle_url('/mod/whakaharatau/import.php', ['id' => $cm->id]);
            case 'report':
                return new moodle_url('/mod/whakaharatau/report.php', ['id' => $cm->id]);
        }
        throw new Exception('Unrecognised mod_whakaharatau page type "' . $type . '."');
    }

    /**
     * Focus a player button by its data-action and press Enter, as a keyboard user would.
     *
     * Actions: start, continue, startagain, next, exit, review, tryagain, goagain, practisemissed, back.
     *
     * @When I press the whakaharatau :action button with the keyboard
     * @param string $action data-action of the button
     */
    public function i_press_the_player_button_with_the_keyboard(string $action): void {
        $button = $this->find_visible_in_player(
            '[data-action="' . $this->escape_attribute($action) . '"]',
            "the \"$action\" button"
        );
        $this->press_enter_on($button);
    }

    /**
     * Focus the choose (dart) button of an option row of the current slide and press Enter.
     *
     * @When I choose option :number on the whakaharatau slide with the keyboard
     * @param int $number option number as shown (1-based)
     */
    public function i_choose_option_with_the_keyboard(int $number): void {
        $button = $this->find_visible_in_player(
            '[data-region="slide"] [data-region="option"][data-number="' . $number . '"] [data-action="choose"]',
            "the choose button of option $number"
        );
        $this->press_enter_on($button);
    }

    /**
     * Check the display state of an option row on the current slide (none, chosen, wrong, correct, revealed).
     *
     * @Then option :number on the whakaharatau slide should be :state
     * @param int $number option number as shown (1-based)
     * @param string $state expected data-state
     */
    public function option_should_be(int $number, string $state): void {
        $row = $this->find_visible_in_player(
            '[data-region="slide"] [data-region="option"][data-number="' . $number . '"]',
            "option $number"
        );
        $actual = (string) $row->getAttribute('data-state');
        if ($actual !== $state) {
            throw new ExpectationException("Option $number is \"$actual\", not \"$state\"", $this->getSession());
        }
    }

    /**
     * Check that the given player button is the focused element.
     *
     * @Then the whakaharatau :action button should have the focus
     * @param string $action data-action of the button
     */
    public function the_player_button_should_have_the_focus(string $action): void {
        $session = $this->getSession();
        $js = '(function() { var el = document.activeElement; return el ? (el.getAttribute("data-action") || "") : ""; })()';
        $actual = (string) $session->evaluateScript('return ' . $js . ';');
        if ($actual !== $action) {
            throw new ExpectationException("The focused element is \"$actual\", not the \"$action\" button", $session);
        }
    }

    /**
     * Find the first visible element matching a CSS selector inside the first visible player.
     *
     * @param string $css CSS selector, relative to the player root
     * @param string $description for the error message
     * @return NodeElement
     */
    protected function find_visible_in_player(string $css, string $description): NodeElement {
        behat_base::require_javascript_in_session($this->getSession());
        $players = $this->find_all('css', '[data-region="mod-whakaharatau-player"]');
        foreach ($players as $player) {
            if (!$player->isVisible()) {
                continue;
            }
            foreach ($player->findAll('css', $css) as $node) {
                if ($node->isVisible()) {
                    return $node;
                }
            }
        }
        throw new ExpectationException(
            "Could not find $description in the listening practice player",
            $this->getSession()
        );
    }

    /**
     * Give an element the keyboard focus and press Enter.
     *
     * @param NodeElement $node the element
     * @return void
     */
    protected function press_enter_on(NodeElement $node): void {
        $node->focus();
        behat_base::type_keys($this->getSession(), [behat_keys::ENTER]);
        $this->wait_for_pending_js();
    }

    /**
     * Escape a value for a CSS attribute selector in double quotes.
     *
     * @param string $value raw value
     * @return string
     */
    protected function escape_attribute(string $value): string {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
