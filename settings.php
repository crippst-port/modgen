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
 * Plugin administration pages are defined here.
 *
 * @package     aiplacement_modgen
 * @category    admin
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_ai\admin\admin_settingspage_provider;

if ($hassiteconfig) {
    $settings = new admin_settingspage_provider(
        'aiplacement_modgen',
        new lang_string('pluginname', 'aiplacement_modgen'),
        'moodle/site:config',
        true
    );

    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf
    if ($ADMIN->fulltree) {
        // AI Enable/Disable Setting
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/aienabledheading',
            new lang_string('aienabledheading', 'aiplacement_modgen'),
            new lang_string('aienabledheading_desc', 'aiplacement_modgen')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_ai',
            new lang_string('enableai', 'aiplacement_modgen'),
            new lang_string('enableai_desc', 'aiplacement_modgen'),
            1
        ));

        // AI Generation Settings
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/aipromptheading',
            new lang_string('aipromptheading', 'aiplacement_modgen'),
            new lang_string('aipromptheading_desc', 'aiplacement_modgen')
        ));

        $basepromptdefault = "You are an expert Moodle learning content designer at a UK higher education institution.\n" .
            "Your task is to design a Moodle module for the user's input, using activities and resources appropriate for UK HE.\n" .
            "Design learning activities aligned with UK HE standards, inclusive pedagogy, and clear learning outcomes.\n" .
            "Structure the module with sections, activities, and resources that promote engagement and effective learning, keep any graphical elements consistent, and ensure accessibility for all users.";

        $settings->add(new admin_setting_configtextarea(
            'aiplacement_modgen/baseprompt',
            new lang_string('baseprompt', 'aiplacement_modgen'),
            new lang_string('baseprompt_desc', 'aiplacement_modgen'),
            $basepromptdefault,
            PARAM_TEXT,
            60,
            10
        ));

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/timeout',
            new lang_string('timeout', 'aiplacement_modgen'),
            new lang_string('timeout_desc', 'aiplacement_modgen'),
            '300'
        ));

        // Base on existing module configuration
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/existingmoduleheading',
            new lang_string('existingmoduleheading', 'aiplacement_modgen'),
            new lang_string('existingmoduleheading_desc', 'aiplacement_modgen')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_existing_modules',
            new lang_string('enableexistingmodules', 'aiplacement_modgen'),
            new lang_string('enableexistingmodules_desc', 'aiplacement_modgen') . "\n\nNote: AI generation must be enabled for this feature to work.",
            1
        ));

        // Module exploration feature
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/explorationheading',
            new lang_string('explorationheading', 'aiplacement_modgen'),
            new lang_string('explorationheading_desc', 'aiplacement_modgen')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_exploration',
            new lang_string('enableexploration', 'aiplacement_modgen'),
            new lang_string('enableexploration_desc', 'aiplacement_modgen'),
            0
        ));
        // Add file upload or other settings as needed.
    }
}
