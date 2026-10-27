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
 * The "Add an Assessments section" choice in the build forms.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Adds the Assessments choice to Quick Add, file and prompt forms.
 */
class assessments_choice {
    /**
     * Add the choice to a form.
     *
     * With no assessments section in the course, a checkbox, ticked. With one, a line naming it and
     * a hidden 0, so the form never asks for a second. Either way the form submits 'createassessments'.
     *
     * @param \MoodleQuickForm $mform The form.
     * @param int $courseid Course the build is for.
     */
    public static function add_to_form(\MoodleQuickForm $mform, int $courseid): void {
        $existing = theme_builder::find_assessments_section($courseid);
        if ($existing === null) {
            $mform->addElement('advcheckbox', 'createassessments', get_string('createassessments', 'aiplacement_modgen'));
            $mform->setType('createassessments', PARAM_BOOL);
            $mform->setDefault('createassessments', 1);
            $mform->addHelpButton('createassessments', 'createassessments', 'aiplacement_modgen');
            return;
        }

        $name = format_string($existing->name, true, ['context' => \context_course::instance($courseid)]);
        $mform->addElement('static', 'assessmentsexists', '', get_string('assessmentsexists', 'aiplacement_modgen', $name));
        $mform->addElement('hidden', 'createassessments', 0);
        $mform->setType('createassessments', PARAM_BOOL);
    }
}
