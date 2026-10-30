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
 * Shared pieces of the Quick Add forms' layout.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Shared pieces of the Quick Add forms' layout.
 *
 * These only add markup and data attributes; amd/src/quick_add_form.js turns the count fields
 * into steppers and fills in the summary. Without it the forms still work as plain fields.
 */
class quick_add_ui {
    /**
     * Add the line at the top of a Quick Add form saying what it creates.
     *
     * @param \MoodleQuickForm $mform The form.
     * @param string $icon Font Awesome icon name, without the 'fa-' prefix.
     * @param string $text What the form creates.
     */
    public static function add_intro(\MoodleQuickForm $mform, string $icon, string $text): void {
        $iconhtml = \html_writer::tag('span', \html_writer::tag('i', '', ['class' => 'fa fa-' . $icon, 'aria-hidden' => 'true']), [
            'class' => 'aiplacement-modgen-qa__icon',
        ]);
        $mform->addElement('html', \html_writer::div(
            $iconhtml . \html_writer::tag('p', $text, ['class' => 'aiplacement-modgen-qa__intro-text']),
            'aiplacement-modgen-qa__intro'
        ));
    }

    /**
     * Attributes that make a count field a stepper.
     *
     * @param int $max Largest number allowed.
     * @return array Attributes for addElement('text', ...).
     */
    public static function stepper_attributes(int $max): array {
        return [
            'size' => 5,
            'inputmode' => 'numeric',
            'data-modgen-stepper' => 1,
            'data-min' => 1,
            'data-max' => $max,
        ];
    }

    /**
     * Attributes that make a select show as choice cards.
     *
     * @param array $choices Option value => [Font Awesome icon name, one-line description].
     * @return array Attributes for addElement('select', ...).
     */
    public static function choice_attributes(array $choices): array {
        $data = [];
        foreach ($choices as $value => [$icon, $description]) {
            $data[$value] = ['icon' => $icon, 'description' => $description];
        }
        return ['data-modgen-choice' => json_encode($data)];
    }

    /**
     * Add the live summary of what the form will create.
     *
     * @param \MoodleQuickForm $mform The form.
     * @param string $kind 'themes' or 'weeks'.
     */
    public static function add_summary(\MoodleQuickForm $mform, string $kind): void {
        $mform->addElement('html', \html_writer::tag('p', '', [
            'class' => 'aiplacement-modgen-qa__summary',
            'data-region' => 'modgen-qa-summary',
            'data-kind' => $kind,
            'data-sectionsperweek' => theme_builder::SECTIONS_PER_WEEK,
            'aria-live' => 'polite',
            'hidden' => 'hidden',
        ]));
    }
}
