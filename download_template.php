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
 * Endpoint for downloading CSV templates.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use aiplacement_modgen\local\template_manager;

require_login();

$templateid = required_param('id', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Use course context if provided, otherwise use system context.
// Course context: user is downloading from a course form.
// System context: user is downloading from admin template management.
if ($courseid) {
    $context = context_course::instance($courseid);
} else {
    $context = context_system::instance();
}

// Check capability - users must be able to generate from templates.
require_capability('aiplacement/modgen:generatefromtemplate', $context);

// Get template.
try {
    $template = template_manager::get_by_id($templateid);
} catch (Exception $e) {
    throw new moodle_exception('templatefilenotfound', 'aiplacement_modgen');
}

// Get file.
$fs = get_file_storage();
$file = $fs->get_file_by_id($template->fileid);

if (!$file) {
    throw new moodle_exception('templatefilenotfound', 'aiplacement_modgen');
}

// Create clean filename.
$filename = clean_filename($template->name . '.csv');

// Send file.
send_stored_file($file, 0, 0, true, ['filename' => $filename]);
