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
 * Plugin callbacks and navigation hooks.
 *
 * @package     aiplacement_modgen
 * @category    lib
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Core hooks and navigation for aiplacement_modgen
function aiplacement_modgen_extend_navigation_course($navigation, $course, $context) {
    global $PAGE;

    if (!$PAGE->user_is_editing()) {
        return;
    }

    if (!has_capability('local/aiplacement_modgen:use', $context)) {
        return;
    }

    $url = new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $course->id, 'embedded' => 1]);

    $params = [
        'url' => $url->out(false),
        'buttonlabel' => get_string('launchgenerator', 'aiplacement_modgen'),
        'dialogtitle' => get_string('modgenmodalheading', 'aiplacement_modgen'),
        'arialabel' => get_string('modgenfabaria', 'aiplacement_modgen'),
    ];

    $PAGE->requires->css('/ai/placement/modgen/styles.css');
    $PAGE->requires->js_call_amd('aiplacement_modgen/fab', 'init', [$params]);
}
