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
 * Plugin version and other meta-data are defined here.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiplacement_modgen';
$plugin->release = '0.7.2';
$plugin->version = 2026091002;
$plugin->requires = 2022112800;
$plugin->maturity = MATURITY_BETA;

// This plugin only builds courses in the Flexible sections format: theme_builder refuses
// anything else with 'errorformatnotflexsections', so the format is a hard requirement
// rather than an optional integration.
//
// The floor is 2024120801 (format_flexsections 4.1.4) for safety, not for API: 4.1.3
// already has every method called here (create_new_section, move_section,
// can_move_section_to, get_max_section_depth, delete_section_with_children), so the plugin
// would run against it without erroring. What 4.1.4 added is the lock around
// delete_section_with_children ("Use lock when deleting sections to avoid course
// corruption", upstream #82). theme_builder::acquire_structure_locks() takes that same
// key to exclude a teacher's section delete from a bulk build; against 4.1.3 nothing on
// the format side takes it, so the exclusion silently buys nothing and a delete can
// interleave with a build. Failing the install is better than that happening quietly.
$plugin->dependencies = [
    'format_flexsections' => 2024120801,
];
