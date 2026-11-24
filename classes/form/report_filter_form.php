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

namespace mod_whakaharatau\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_whakaharatau\local\report_filter;

/**
 * The report filter form. The page turns the submitted values into URL parameters and redirects.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_filter_form extends \moodleform {
    /**
     * Form definition. Custom data: cohorts (id => name), anonymised (bool), active (bool).
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('header', 'filterhdr', get_string('report_filter', 'mod_whakaharatau'));
        $mform->setExpanded('filterhdr', !empty($this->_customdata['active']));
        $anonymised = !empty($this->_customdata['anonymised']);
        if (!$anonymised) {
            $mform->addElement('text', 'learner', get_string('report_filterlearner', 'mod_whakaharatau'), ['size' => 30]);
            $mform->setType('learner', PARAM_TEXT);
            $mform->addHelpButton('learner', 'report_filterlearner', 'mod_whakaharatau');
        }
        // Select labels are printed as HTML by formslib.
        $cohorts = [0 => get_string('report_anycohort', 'mod_whakaharatau')];
        foreach ($this->_customdata['cohorts'] as $id => $name) {
            $cohorts[$id] = s($name);
        }
        $mform->addElement('select', 'cohort', get_string('report_cohort', 'mod_whakaharatau'), $cohorts);
        $mform->addHelpButton('cohort', 'report_cohort', 'mod_whakaharatau');
        if (!$anonymised) {
            // Not named like the URL parameters: a date is posted as an array.
            $mform->addElement('date_selector', 'datefrom', get_string('report_from', 'mod_whakaharatau'), [
                'optional' => true]);
            $mform->addElement('date_selector', 'dateto', get_string('report_to', 'mod_whakaharatau'), ['optional' => true]);
            $mform->addHelpButton('dateto', 'report_to', 'mod_whakaharatau');
        }
        $buttons = [
            $mform->createElement('submit', 'applyfilter', get_string('report_applyfilter', 'mod_whakaharatau')),
            $mform->createElement('cancel', 'clearfilter', get_string('report_clearfilter', 'mod_whakaharatau')),
        ];
        $mform->addGroup($buttons, 'filterbuttons', '', [' '], false);
    }

    /**
     * Validation: the dates are in order.
     *
     * @param array $data submitted data
     * @param array $files not used
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['datefrom']) && !empty($data['dateto']) && $data['dateto'] < $data['datefrom']) {
            $errors['dateto'] = get_string('report_baddates', 'mod_whakaharatau');
        }
        return $errors;
    }

    /**
     * Show the form for a page, or redirect when it was submitted.
     *
     * @param \moodle_url $url page URL without filters
     * @param \context $context context of the report
     * @param report_filter $filter filters now set
     * @param bool $anonymised whether to offer only the cohort filter
     * @return self
     */
    public static function handle(
        \moodle_url $url,
        \context $context,
        report_filter $filter,
        bool $anonymised = false
    ): self {
        $form = new self(new \moodle_url($url, $filter->get_params()), [
            'cohorts' => report_filter::get_cohorts($context),
            'anonymised' => $anonymised,
            'active' => $filter->is_active(),
        ]);
        if ($form->is_cancelled()) {
            redirect($url);
        }
        if ($data = $form->get_data()) {
            $new = new report_filter();
            $new->cohortid = (int) $data->cohort;
            if (!$anonymised) {
                $new->learner = trim((string) $data->learner);
                $new->from = (int) $data->datefrom;
                $new->to = (int) $data->dateto;
            }
            redirect(new \moodle_url($url, $new->get_params()));
        }
        $form->set_data(['learner' => $filter->learner, 'cohort' => $filter->cohortid, 'datefrom' => $filter->from,
            'dateto' => $filter->to]);
        return $form;
    }
}
