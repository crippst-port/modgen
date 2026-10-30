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
    // Create settings page.
    $settings = new admin_settingspage_provider(
        'aiplacement_modgen',
        new lang_string('pluginname', 'aiplacement_modgen'),
        'moodle/site:config',
        true
    );

    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf
    if ($ADMIN->fulltree) {
        // Features: each can be switched off for the whole site. Capabilities still apply on top.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/featuretoggleheading',
            new lang_string('featuretoggleheading', 'aiplacement_modgen'),
            new lang_string('featuretoggleheading_desc', 'aiplacement_modgen')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_quickadd',
            new lang_string('enablequickadd', 'aiplacement_modgen'),
            new lang_string('enablequickadd_desc', 'aiplacement_modgen'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_assessments',
            new lang_string('enableassessments', 'aiplacement_modgen'),
            new lang_string('enableassessments_desc', 'aiplacement_modgen'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_structurefromfile',
            new lang_string('enablestructurefromfile', 'aiplacement_modgen'),
            new lang_string('enablestructurefromfile_desc', 'aiplacement_modgen'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_ai',
            new lang_string('enableai', 'aiplacement_modgen'),
            new lang_string('enableai_desc', 'aiplacement_modgen'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'aiplacement_modgen/enable_coreformats',
            new lang_string('enablecoreformats', 'aiplacement_modgen'),
            new lang_string('enablecoreformats_desc', 'aiplacement_modgen'),
            0
        ));

        // Structure from prompt: what is sent to the AI, and limits on its use.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/aipromptheading',
            new lang_string('aipromptheading', 'aiplacement_modgen'),
            new lang_string('aipromptheading_desc', 'aiplacement_modgen')
        ));

        $basepromptdefault = "You are an expert Moodle learning content designer at a UK higher education institution.\n" .
            "Your task is to design a Moodle module for the user's input, using activities and resources appropriate for UK HE.\n" .
            "Design learning activities aligned with UK HE standards, inclusive pedagogy, and clear learning outcomes.\n" .
            "Structure the module with sections, activities, and resources that promote engagement and " .
            "effective learning, keep any graphical elements consistent, and ensure accessibility for all users.";

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

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/ai_rate_limit',
            new lang_string('airatelimit', 'aiplacement_modgen'),
            new lang_string('airatelimit_desc', 'aiplacement_modgen'),
            '10',
            PARAM_INT
        ));

        // Size limits.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/sectionlimitsheading',
            new lang_string('sectionlimitsheading', 'aiplacement_modgen'),
            new lang_string('sectionlimitsheading_desc', 'aiplacement_modgen')
        ));

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/maxquicksections',
            new lang_string('maxquicksections', 'aiplacement_modgen'),
            new lang_string('maxquicksections_desc', 'aiplacement_modgen'),
            '30',
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/maxweeksperTheme',
            new lang_string('maxweeksperTheme', 'aiplacement_modgen'),
            new lang_string('maxweeksperTheme_desc', 'aiplacement_modgen'),
            '5',
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/maxcsvsections',
            new lang_string('maxcsvsections', 'aiplacement_modgen'),
            new lang_string('maxcsvsections_desc', 'aiplacement_modgen'),
            '50',
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'aiplacement_modgen/maxtotalsections',
            new lang_string('maxtotalsections', 'aiplacement_modgen'),
            new lang_string('maxtotalsections_desc', 'aiplacement_modgen'),
            (string) \aiplacement_modgen\local\constants::MAX_TOTAL_SECTIONS,
            PARAM_INT
        ));

        // CSV structure library.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/csvtemplateheading',
            new lang_string('csvtemplatelibrary', 'aiplacement_modgen'),
            new lang_string('csvtemplatelibrary_desc', 'aiplacement_modgen')
        ));

        $manageurl = new moodle_url('/ai/placement/modgen/manage_templates.php');
        $settings->add(new admin_setting_description(
            'aiplacement_modgen/managetemplates_link',
            '',
            new lang_string('managetemplates_desc', 'aiplacement_modgen', $manageurl->out())
        ));

        // Help and Advice menu.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/helplinksheading',
            new lang_string('helplinksheading', 'aiplacement_modgen'),
            new lang_string('helplinksheading_desc', 'aiplacement_modgen')
        ));

        // Add 5 configurable help links.
        for ($i = 1; $i <= 5; $i++) {
            $settings->add(new admin_setting_configtext(
                "aiplacement_modgen/helplink{$i}_text",
                new lang_string('helplinktext', 'aiplacement_modgen', $i),
                new lang_string('helplinktext_desc', 'aiplacement_modgen'),
                '',
                PARAM_TEXT
            ));

            $settings->add(new admin_setting_configtext(
                "aiplacement_modgen/helplink{$i}_url",
                new lang_string('helplinkurl', 'aiplacement_modgen', $i),
                new lang_string('helplinkurl_desc', 'aiplacement_modgen'),
                '',
                PARAM_URL
            ));
        }

        // Diagnostics.
        $settings->add(new admin_setting_heading(
            'aiplacement_modgen/admintoolsheading',
            new lang_string('admintools_link', 'aiplacement_modgen'),
            ''
        ));

        $admintoolsurl = new moodle_url('/ai/placement/modgen/admin_tools.php');
        $settings->add(new admin_setting_description(
            'aiplacement_modgen/admintools_link',
            '',
            new lang_string('admintools_link_desc', 'aiplacement_modgen', $admintoolsurl->out())
        ));
    }
}
