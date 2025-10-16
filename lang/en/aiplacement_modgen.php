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

$string['pluginname'] = 'Module Assistant';

$string['prompt'] = 'What would you like to create for your module?';
$string['submit'] = 'Submit prompt';
$string['orgparams'] = 'Organisation parameters';

$string['reviewjson'] = 'Review the generated module JSON below. Approve to create activities.';
$string['jsonpreview'] = 'JSON preview';
$string['generationresultssummaryheading'] = 'What will be created';
$string['generationresultspromptheading'] = 'Your prompt';
$string['generationresultsprompttoggle'] = 'Show prompt details';
$string['generationresultsjsonheading'] = 'Full module JSON';
$string['generationresultsjsondescription'] = 'Review or share the structured JSON output from the generator.';
$string['generationresultsjsonnote'] = 'Keep a copy if you may need to regenerate the same structure later.';
$string['generationresultsfallbacksummary_weekly'] = 'The plan creates {$sections} weekly sections with around {$outlineitems} suggested activities and resources.';
$string['generationresultsfallbacksummary_theme'] = 'The plan creates {$themes} themed sections spanning approximately {$weeks} delivery weeks.';
$string['aisubsystemresponsedata'] = 'AI subsystem response data';
$string['rawoutput'] = 'Raw output';
$string['aigensummary'] = 'AI Generated Summary';
$string['sectioncreated'] = 'Section created: {$a}';
$string['nosectionscreated'] = 'No sections were created from the AI response.';
$string['connectedcurriculumcredits'] = 'Module type';
$string['connectedcurriculum30'] = '30 credit module';
$string['connectedcurriculum60'] = '60 credit module';
$string['connectedcurriculum120'] = '120 credit module';
$string['connectedcurriculuminstruction'] = 'Module credit volume: {$a} credit Connected Curriculum module.';
$string['approveandcreate'] = 'Approve and create';
$string['reenterprompt'] = 'Re-enter prompt';
$string['loadingthinking'] = 'Thinking... generating your request.';
$string['activitytypeunsupported'] = 'The generated activity type "{$a}" is not available on this site.';
$string['activitytypecreationfailed'] = 'Unable to create the "{$a}" activity automatically. Please review the course.';
$string['aigenlabel'] = 'AI Generated Label';
$string['aigenquiz'] = 'AI Generated Quiz';
$string['labelcreated'] = 'Label created (cmid: {$a})';
$string['quizcreated'] = 'Quiz created: {$a}';
$string['activitytype_quiz'] = 'Quiz';
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
$string['returntocourse'] = 'Return to course home';
$string['promptsentheading'] = 'Prompt sent to AI subsystem';
$string['launchgenerator'] = 'Module Assistant';
$string['modgenmodalheading'] = 'Module Assistant';
$string['modgenfabaria'] = 'Open Module Assistant';
$string['closemodgenmodal'] = 'Close and return to module';
$string['aipolicynotaccepted'] = 'You must accept the AI policy before using the Module Assistant.';
$string['aipolicyacceptance'] = 'AI Policy Acceptance Required';
$string['acceptaipolicy'] = 'I agree to the terms of AI use in this system';
$string['aipolicyinfo'] = 'By using this AI-powered tool, you acknowledge that your data will be processed according to our AI usage policy. Please review and accept the terms to continue.';
$string['timeout'] = 'AI Request Timeout (seconds)';
$string['timeout_desc'] = 'Maximum time to wait for AI responses before timing out. Default is 300 seconds (5 minutes).';
$string['processing'] = 'Processing your request, this may take several minutes...';
$string['requesttimeout'] = 'Your request is taking longer than expected. Please try with a shorter prompt or try again later.';
$string['aiprocessing'] = 'AI is generating your module. Please wait...';
$string['longquery'] = 'Long queries may take up to 5 minutes to process.';
$string['aiprocessingdetail'] = 'AI is analyzing your request and generating module content. This process may take several minutes for complex requests.';
$string['prompt_help'] = 'Describe what you want to create for your module. Be specific about the topic, learning objectives, and type of activities you want. More detailed prompts will give better results but may take longer to process.';
