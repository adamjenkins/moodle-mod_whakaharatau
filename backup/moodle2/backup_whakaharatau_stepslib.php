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
 * Backup structure step for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the whakaharatau.xml structure: the instance, its slides and options, and (with user data) the
 * attempts and their responses, with id and file annotations.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_whakaharatau_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $whakaharatau = new backup_nested_element('whakaharatau', ['id'], [
            'name', 'intro', 'introformat', 'mode', 'shuffle', 'repeatmode', 'optionorder', 'showprogress',
            'displayinline', 'feedbackmode', 'triesperslide', 'slidescore', 'attempts', 'grademethod',
            'allowreview', 'scoring', 'autoplay', 'transcriptsbefore', 'fade', 'fadewidth', 'coverfade',
            'coverfadewidth', 'grade', 'completionallslides', 'timecreated', 'timemodified',
        ]);

        $slides = new backup_nested_element('slides');
        $slide = new backup_nested_element('slide', ['id'], [
            'sortorder', 'prompt', 'promptformat', 'promptlang', 'alttext', 'decorative', 'fade', 'fadewidth',
            'timecreated', 'timemodified',
        ]);

        $options = new backup_nested_element('options');
        $option = new backup_nested_element('option', ['id'], [
            'sortorder', 'transcript', 'iscorrect', 'timecreated', 'timemodified',
        ]);

        $attempts = new backup_nested_element('userattempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'mode', 'attempt', 'state', 'shuffle', 'repeatmode', 'round', 'slideorder', 'optionorder',
            'currentslide', 'score', 'maxscore', 'currentstreak', 'beststreak', 'timestart', 'timefinish',
            'timemodified',
        ]);

        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'round', 'slideid', 'optionid', 'correct', 'tries', 'timeanswered',
        ]);

        // Build the tree. Slides come before attempts, so restore maps every slide and option before it
        // rewrites the attempts' frozen orders.
        $whakaharatau->add_child($slides);
        $slides->add_child($slide);
        $slide->add_child($options);
        $options->add_child($option);
        $whakaharatau->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($responses);
        $responses->add_child($response);

        // Sources.
        $whakaharatau->set_source_table('whakaharatau', ['id' => backup::VAR_ACTIVITYID]);
        $slide->set_source_table('whakaharatau_slide', ['whakaharatauid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        $option->set_source_table('whakaharatau_option', ['slideid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        if ($userinfo) {
            $attempt->set_source_table('whakaharatau_attempt', ['whakaharatauid' => backup::VAR_PARENTID], 'id ASC');
            $response->set_source_table('whakaharatau_response', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }

        // Id annotations.
        $attempt->annotate_ids('user', 'userid');

        // File annotations.
        $whakaharatau->annotate_files('mod_whakaharatau', 'intro', null);
        $whakaharatau->annotate_files('mod_whakaharatau', 'cover', null);
        $slide->annotate_files('mod_whakaharatau', 'slideimage', 'id');
        $slide->annotate_files('mod_whakaharatau', 'slideprompt', 'id');
        $option->annotate_files('mod_whakaharatau', 'optionaudio', 'id');

        return $this->prepare_activity_structure($whakaharatau);
    }
}
