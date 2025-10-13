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
 * Plugin strings are defined here.
 *
 * @package     aiplacement_modgen
 * @category    string
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Module Generator';

$string['prompt'] = 'Describe your module in a few sentences';
$string['submit'] = 'Generate module';
$string['orgparams'] = 'Organisation parameters';

$string['reviewjson'] = 'Review the generated module JSON below. Approve to create activities.';
$string['jsonpreview'] = 'JSON preview';
$string['aisubsystemresponsedata'] = 'AI subsystem response data';
$string['rawoutput'] = 'Raw output';
$string['aigensummary'] = 'AI Generated Summary';
$string['sectioncreated'] = 'Section created: {$a}';
$string['nosectionscreated'] = 'No sections were created from the AI response.';
$string['connectedcurriculumcredits'] = 'Connected Curriculum module type';
$string['connectedcurriculum30'] = '30 credit module';
$string['connectedcurriculum60'] = '60 credit module';
$string['connectedcurriculum120'] = '120 credit module';
$string['connectedcurriculuminstruction'] = 'Module credit volume: {$a} credit Connected Curriculum module.';
$string['approveandcreate'] = 'Approve and create';
$string['aigenlabel'] = 'AI Generated Label';
$string['aigenquiz'] = 'AI Generated Quiz';
$string['labelcreated'] = 'Label created (cmid: {$a})';
$string['quizcreated'] = 'Quiz created (cmid: {$a})';
$string['subsectioncreated'] = 'Subsection created: {$a}';
$string['moduletype'] = 'Module format';
$string['moduletype_weekly'] = 'Weekly format';
$string['moduletype_theme'] = 'Themed format';
$string['moduletypeinstruction_weekly'] = 'Structure the module as sequential weekly teaching sections with clear titles, summaries, and an outline array of 3-5 bullet points describing activities/resources.';
$string['moduletypeinstruction_theme'] = 'Structure the module into distinct themes. For each theme provide a high-level summary and include an array of weekly entries that detail how the theme is delivered over time.';
$string['weeklybreakdown'] = 'Weekly breakdown';
$string['weeklyoutline'] = 'Weekly outline';
$string['themefallback'] = 'Theme overview';
$string['weekfallback'] = 'Weekly focus';
$string['keepweeklabels'] = 'Keep dated headings and insert the subject title as a label';
$string['includeaboutassessments'] = 'Add "About Assessments" subsection to the first section';
$string['includeaboutlearning'] = 'Add "About Learning Outcomes" subsection to the first section';
$string['aboutassessments'] = 'About Assessments';
$string['aboutlearningoutcomes'] = 'About Learning Outcomes';

