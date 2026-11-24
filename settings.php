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
 * Site-wide defaults for new mod_whakaharatau activities.
 *
 * Each setting name matches a mod_form element, so moodleform_mod::apply_admin_defaults() applies the
 * default and its Advanced ("_adv") and Locked ("_locked") flags.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_whakaharatau\local\instance_settings;

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'whakaharatau/defaultsheading',
        get_string('defaultsheading', 'mod_whakaharatau'),
        get_string('defaultsheading_desc', 'mod_whakaharatau')
    ));

    $builtin = instance_settings::get_builtin_defaults();
    $choices = [
        'mode' => instance_settings::get_mode_choices(),
        'shuffle' => instance_settings::get_yesno_choices(),
        'repeatmode' => instance_settings::get_repeat_choices(),
        'optionorder' => instance_settings::get_optionorder_choices(),
        'practicetries' => instance_settings::get_tries_choices(),
        'testtries' => instance_settings::get_tries_choices(),
        'displayinline' => instance_settings::get_display_choices(),
        'showprogress' => instance_settings::get_yesno_choices(),
        'autoplay' => instance_settings::get_yesno_choices(),
        'transcriptsbefore' => instance_settings::get_yesno_choices(),
        'fade' => instance_settings::get_yesno_choices(),
        'fadewidth' => instance_settings::get_fade_choices(),
        'coverfade' => instance_settings::get_yesno_choices(),
        'coverfadewidth' => instance_settings::get_fade_choices(),
        'feedbackmode' => instance_settings::get_feedback_choices(),
        'scoring' => instance_settings::get_scoring_choices(),
        'slidescore' => instance_settings::get_slidescore_choices(),
        'attempts' => instance_settings::get_attempts_choices(),
        'grademethod' => instance_settings::get_grademethod_choices(),
        'allowreview' => instance_settings::get_yesno_choices(),
    ];
    // Lang string used as the label of each setting (help text: same key + '_help').
    $labels = [
        'practicetries' => 'practicetries',
        'testtries' => 'testtries',
        'attempts' => 'attemptsallowed',
    ];

    foreach ($choices as $name => $options) {
        $label = $labels[$name] ?? $name;
        $setting = new admin_setting_configselect(
            'whakaharatau/' . $name,
            new lang_string($label, 'mod_whakaharatau'),
            new lang_string($label . '_help', 'mod_whakaharatau'),
            $builtin[$name],
            $options
        );
        $setting->set_advanced_flag_options(admin_setting_flag::ENABLED, false);
        $setting->set_locked_flag_options(admin_setting_flag::ENABLED, false);
        $settings->add($setting);
    }

    // A site-wide look, not a default: it applies to every activity.
    $settings->add(new admin_setting_heading(
        'whakaharatau/siteheading',
        get_string('siteheading', 'mod_whakaharatau'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'whakaharatau/stackedfade',
        new lang_string('stackedfade', 'mod_whakaharatau'),
        new lang_string('stackedfade_help', 'mod_whakaharatau'),
        instance_settings::FADE_STACKED,
        instance_settings::get_fade_choices()
    ));
    $minimums = range(instance_settings::ANONYMOUS_LOWEST, instance_settings::ANONYMOUS_HIGHEST);
    $settings->add(new admin_setting_configselect(
        'whakaharatau/anonymousmin',
        new lang_string('anonymousmin', 'mod_whakaharatau'),
        new lang_string('anonymousmin_help', 'mod_whakaharatau'),
        instance_settings::ANONYMOUS_MIN,
        array_combine($minimums, $minimums)
    ));
}
