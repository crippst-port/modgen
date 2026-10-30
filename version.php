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
$plugin->release = '0.9.0';
$plugin->version = 2026103000;
$plugin->requires = 2024100700; // Moodle 4.5: needs the core AI subsystem and the aiplacement plugin type.
$plugin->maturity = MATURITY_BETA;

// No dependencies: the plugin builds in Custom sections and Weekly sections with core subsections, and
// in Flexible sections when it is installed. A Flexible sections course is only built in from
// format_flexsections 4.1.4 on (see course_formats::FLEXSECTIONS_MIN_VERSION): earlier versions take
// no lock around deleting a section, so a delete could interleave with a build unnoticed.
