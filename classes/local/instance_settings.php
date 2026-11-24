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

/**
 * Constants, choice lists and derived values of the activity settings.
 *
 * Shared by mod_form.php, settings.php, the domain layer and the player, so that a
 * setting's stored values are defined in exactly one place.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instance_settings {
    /** @var int Practice mode (default). */
    const MODE_PRACTICE = 0;
    /** @var int Test mode (graded). */
    const MODE_TEST = 1;

    /** @var int One pass, then the end screen. */
    const REPEAT_OFF = 0;
    /** @var int The deck starts again after the last slide, until the learner leaves. */
    const REPEAT_ALL = 1;
    /** @var int Next round holds only the slides not right first time. */
    const REPEAT_MISSED = 2;

    /** @var int Options in the teacher's order. */
    const OPTIONORDER_FIXED = 0;
    /** @var int Options shuffled each round. */
    const OPTIONORDER_RANDOM = 1;

    /** @var int Link only on the course page. */
    const DISPLAY_LINK = 0;
    /** @var int The player runs inline on the course page. */
    const DISPLAY_INLINE = 1;

    /** @var int Feedback after each answer. */
    const FEEDBACK_EACH = 0;
    /** @var int Feedback only at the end (Test only; forces one try). */
    const FEEDBACK_END = 1;

    /** @var int Tries per slide: until correct. */
    const TRIES_UNLIMITED = 0;
    /** @var int Largest selectable tries per slide. */
    const TRIES_MAX = 5;

    /** @var int A slide scores only when right first time. */
    const SLIDESCORE_FIRSTTRY = 0;
    /** @var int Partial credit for each wrong try. */
    const SLIDESCORE_PARTIAL = 1;

    /** @var int Unlimited Test attempts. */
    const ATTEMPTS_UNLIMITED = 0;
    /** @var int Largest selectable number of attempts. */
    const ATTEMPTS_MAX = 10;

    /** @var int Grade = highest attempt. */
    const GRADE_HIGHEST = 1;
    /** @var int Grade = average of attempts. */
    const GRADE_AVERAGE = 2;
    /** @var int Grade = first attempt. */
    const GRADE_FIRST = 3;
    /** @var int Grade = last attempt. */
    const GRADE_LAST = 4;

    /** @var int Score correct answers. */
    const SCORING_CORRECT = 0;
    /** @var int Score slides completed (as the SCORM package did). */
    const SCORING_COMPLETED = 1;

    /** @var int Largest picture fade, as a percentage of the picture. */
    const FADE_MAX = 50;
    /** @var int Built-in side fade, in percent. */
    const FADE_WIDTH = 15;
    /** @var int Built-in fade of the bottom edge when the picture is stacked above the options, in percent. */
    const FADE_STACKED = 10;

    /** @var int Built-in smallest number of learners in an anonymised result. */
    const ANONYMOUS_MIN = 5;
    /** @var int Smallest number an administrator may choose. */
    const ANONYMOUS_LOWEST = 2;
    /** @var int Largest number an administrator may choose. */
    const ANONYMOUS_HIGHEST = 50;

    /** @var string[] Names of the settings that have a site default in settings.php and a mod_form element. */
    const DEFAULTABLE = [
        'mode', 'shuffle', 'repeatmode', 'optionorder', 'showprogress', 'displayinline', 'feedbackmode',
        'practicetries', 'testtries', 'slidescore', 'attempts', 'grademethod', 'allowreview', 'scoring',
        'autoplay', 'transcriptsbefore', 'fade', 'fadewidth', 'coverfade', 'coverfadewidth',
    ];

    /**
     * Built-in defaults (BRIEF §4), used when no site default is stored.
     *
     * @return array setting name => value
     */
    public static function get_builtin_defaults(): array {
        return [
            'mode' => self::MODE_PRACTICE,
            'shuffle' => 0,
            'repeatmode' => self::REPEAT_OFF,
            'optionorder' => self::OPTIONORDER_RANDOM,
            'showprogress' => 1,
            'displayinline' => self::DISPLAY_LINK,
            'feedbackmode' => self::FEEDBACK_EACH,
            'practicetries' => self::TRIES_UNLIMITED,
            'testtries' => 1,
            'slidescore' => self::SLIDESCORE_FIRSTTRY,
            'attempts' => self::ATTEMPTS_UNLIMITED,
            'grademethod' => self::GRADE_HIGHEST,
            'allowreview' => 1,
            'scoring' => self::SCORING_CORRECT,
            'autoplay' => 0,
            'transcriptsbefore' => 0,
            'fade' => 1,
            'fadewidth' => self::FADE_WIDTH,
            'coverfade' => 1,
            'coverfadewidth' => self::FADE_WIDTH,
        ];
    }

    /**
     * Smallest number of learners a result in an anonymised report may come from.
     *
     * @return int
     */
    public static function get_anonymous_minimum(): int {
        $min = (int) get_config('whakaharatau', 'anonymousmin') ?: self::ANONYMOUS_MIN;
        return max(self::ANONYMOUS_LOWEST, min(self::ANONYMOUS_HIGHEST, $min));
    }

    /**
     * Fade width choices, 0% to FADE_MAX%.
     *
     * @return array value => label
     */
    public static function get_fade_choices(): array {
        $choices = [];
        for ($i = 0; $i <= self::FADE_MAX; $i++) {
            $choices[$i] = get_string('percents', 'core', $i);
        }
        return $choices;
    }

    /**
     * A fade width brought into range.
     *
     * @param mixed $width percent
     * @return int 0 to FADE_MAX
     */
    public static function clamp_fade($width): int {
        return max(0, min(self::FADE_MAX, (int) $width));
    }

    /**
     * Whether the site locks a setting, so that teachers cannot change it.
     *
     * @param string $name setting name
     * @return bool
     */
    public static function is_locked(string $name): bool {
        return (bool) get_config('whakaharatau', $name . '_locked');
    }

    /**
     * The fade of a picture as the player draws it. Off (or a width of 0) is no fade at all, at either edge.
     *
     * @param mixed $fade whether the fade is on
     * @param mixed $width side fade in percent
     * @return int[] fadeside and fadebottom, in percent (bottom: used when the picture is stacked above the options)
     */
    public static function get_fade($fade, $width): array {
        $side = empty($fade) ? 0 : self::clamp_fade($width);
        $stacked = get_config('whakaharatau', 'stackedfade');
        $stacked = ($stacked === false || $stacked === '') ? self::FADE_STACKED : self::clamp_fade($stacked);
        return ['fadeside' => $side, 'fadebottom' => $side ? $stacked : 0];
    }

    /**
     * Site defaults merged over the built-in defaults.
     *
     * @return array setting name => value (ints)
     */
    public static function get_defaults(): array {
        $config = (array) get_config('whakaharatau');
        $defaults = self::get_builtin_defaults();
        foreach ($defaults as $name => $value) {
            if (isset($config[$name]) && $config[$name] !== '') {
                $defaults[$name] = (int) $config[$name];
            }
        }
        return $defaults;
    }

    /**
     * Mode choices.
     *
     * @return array value => label
     */
    public static function get_mode_choices(): array {
        return [
            self::MODE_PRACTICE => get_string('modepractice', 'mod_whakaharatau'),
            self::MODE_TEST => get_string('modetest', 'mod_whakaharatau'),
        ];
    }

    /**
     * Repeat choices.
     *
     * @return array value => label
     */
    public static function get_repeat_choices(): array {
        return [
            self::REPEAT_OFF => get_string('repeatoff', 'mod_whakaharatau'),
            self::REPEAT_ALL => get_string('repeatall', 'mod_whakaharatau'),
            self::REPEAT_MISSED => get_string('repeatmissed', 'mod_whakaharatau'),
        ];
    }

    /**
     * Option order choices.
     *
     * @return array value => label
     */
    public static function get_optionorder_choices(): array {
        return [
            self::OPTIONORDER_FIXED => get_string('optionorderfixed', 'mod_whakaharatau'),
            self::OPTIONORDER_RANDOM => get_string('optionorderrandom', 'mod_whakaharatau'),
        ];
    }

    /**
     * Course-page display choices.
     *
     * @return array value => label
     */
    public static function get_display_choices(): array {
        return [
            self::DISPLAY_LINK => get_string('displaylink', 'mod_whakaharatau'),
            self::DISPLAY_INLINE => get_string('displayinlineyes', 'mod_whakaharatau'),
        ];
    }

    /**
     * Feedback timing choices.
     *
     * @return array value => label
     */
    public static function get_feedback_choices(): array {
        return [
            self::FEEDBACK_EACH => get_string('feedbackeach', 'mod_whakaharatau'),
            self::FEEDBACK_END => get_string('feedbackend', 'mod_whakaharatau'),
        ];
    }

    /**
     * Tries-per-slide choices.
     *
     * @return array value => label
     */
    public static function get_tries_choices(): array {
        $choices = [self::TRIES_UNLIMITED => get_string('triesuntilcorrect', 'mod_whakaharatau')];
        for ($i = 1; $i <= self::TRIES_MAX; $i++) {
            $choices[$i] = (string) $i;
        }
        return $choices;
    }

    /**
     * Slide-score choices.
     *
     * @return array value => label
     */
    public static function get_slidescore_choices(): array {
        return [
            self::SLIDESCORE_FIRSTTRY => get_string('slidescorefirsttry', 'mod_whakaharatau'),
            self::SLIDESCORE_PARTIAL => get_string('slidescorepartial', 'mod_whakaharatau'),
        ];
    }

    /**
     * Attempts-allowed choices.
     *
     * @return array value => label
     */
    public static function get_attempts_choices(): array {
        $choices = [self::ATTEMPTS_UNLIMITED => get_string('unlimited')];
        for ($i = 1; $i <= self::ATTEMPTS_MAX; $i++) {
            $choices[$i] = (string) $i;
        }
        return $choices;
    }

    /**
     * Grading-method choices.
     *
     * @return array value => label
     */
    public static function get_grademethod_choices(): array {
        return [
            self::GRADE_HIGHEST => get_string('gradehighest', 'mod_whakaharatau'),
            self::GRADE_AVERAGE => get_string('gradeaverage', 'mod_whakaharatau'),
            self::GRADE_FIRST => get_string('gradefirst', 'mod_whakaharatau'),
            self::GRADE_LAST => get_string('gradelast', 'mod_whakaharatau'),
        ];
    }

    /**
     * Scoring choices.
     *
     * @return array value => label
     */
    public static function get_scoring_choices(): array {
        return [
            self::SCORING_CORRECT => get_string('scoringcorrect', 'mod_whakaharatau'),
            self::SCORING_COMPLETED => get_string('scoringcompleted', 'mod_whakaharatau'),
        ];
    }

    /**
     * Yes/No choices.
     *
     * @return array value => label
     */
    public static function get_yesno_choices(): array {
        return [
            0 => get_string('no'),
            1 => get_string('yes'),
        ];
    }

    /**
     * Whether the instance is in Test mode.
     *
     * @param \stdClass $instance whakaharatau record
     * @return bool
     */
    public static function is_test(\stdClass $instance): bool {
        return (int) $instance->mode === self::MODE_TEST;
    }

    /**
     * The repeat mode that applies (Test is always Off).
     *
     * @param \stdClass $instance whakaharatau record
     * @return int REPEAT_* constant
     */
    public static function effective_repeat(\stdClass $instance): int {
        return self::is_test($instance) ? self::REPEAT_OFF : (int) $instance->repeatmode;
    }

    /**
     * The feedback timing that applies (Practice is always after each answer).
     *
     * @param \stdClass $instance whakaharatau record
     * @return int FEEDBACK_* constant
     */
    public static function effective_feedback(\stdClass $instance): int {
        return self::is_test($instance) ? (int) $instance->feedbackmode : self::FEEDBACK_EACH;
    }

    /**
     * Tries allowed per slide; 0 = until correct. "Feedback only at the end" forces 1.
     *
     * @param \stdClass $instance whakaharatau record
     * @return int
     */
    public static function effective_tries(\stdClass $instance): int {
        if (self::effective_feedback($instance) === self::FEEDBACK_END) {
            return 1;
        }
        return max(0, min(self::TRIES_MAX, (int) $instance->triesperslide));
    }

    /**
     * Human-readable play mode for the start screen, e.g. "Shuffled · Repeats until you stop".
     *
     * @param \stdClass $instance whakaharatau record
     * @return string plain text (escape at the sink)
     */
    public static function get_playmode_description(\stdClass $instance): string {
        $parts = [];
        $parts[] = get_string(!empty($instance->shuffle) ? 'playmodeshuffled' : 'playmodeinorder', 'mod_whakaharatau');
        switch (self::effective_repeat($instance)) {
            case self::REPEAT_ALL:
                $parts[] = get_string('playmoderepeatall', 'mod_whakaharatau');
                break;
            case self::REPEAT_MISSED:
                $parts[] = get_string('playmoderepeatmissed', 'mod_whakaharatau');
                break;
            default:
                $parts[] = get_string('playmodeonepass', 'mod_whakaharatau');
        }
        return implode(' · ', $parts);
    }
}
