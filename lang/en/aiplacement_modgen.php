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
$string['activitytype_label'] = 'Label';
$string['activitytype_label'] = 'Label';
$string['activitycreated'] = 'Activity created: {$a}';
$string['quizcreationerror'] = 'Unable to create the "quiz" activity automatically. Please review the course.';
$string['labelcreationerror'] = 'Unable to create the "label" activity automatically. Please review the course.';
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
$string['missingcourseid'] = 'Course ID is required to use the Module Assistant.';

// Tabbed interface
$string['generatetablabel'] = 'Generate from Template';
$string['uploadtablabel'] = 'Upload Content';

// File upload and content import
$string['contentfile'] = 'Upload document file';
$string['contentfiledescription'] = 'Upload a Word document or OpenDocument file to extract content and create activities.';
$string['selectactivitytype'] = 'What activity would you like to create?';
$string['unsupportedfiletype'] = 'File type "{$a}" is not supported. Please upload a .docx, .doc, or .odt file.';
$string['conversionfailed'] = 'Could not convert "{$a}" to HTML. Falling back to plain text extraction.';
$string['fallbacktoplaintext'] = 'File was converted to plain text (formatting was not preserved).';
$string['couldnotextractcontent'] = 'Could not extract content from "{$a}". Please check the file and try again.';
$string['bookcreated'] = 'Book activity created: {$a} with {$chapters} chapters.';
$string['uploadandcreate'] = 'Upload and create activity';
$string['longquery'] = 'This may take a moment while the AI processes your request.';
$string['connectedcurriculum30'] = '30 credit module';
$string['connectedcurriculum60'] = '60 credit module';
$string['connectedcurriculum120'] = '120 credit module';
$string['connectedcurriculumcredits'] = 'Module type';
$string['connectedcurriculuminstruction'] = 'Module credit volume: {$a} credit Connected Curriculum module.';
$string['nocurriculum'] = 'No curriculum template';
$string['selectcurriculum'] = 'Curriculum template';
$string['curriculumtemplates'] = 'Curriculum templates';

// Book activity
$string['activitytype_book'] = 'Book';
$string['bookdescription'] = 'Chapter-based content from uploaded document';

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
$string['moduletype_help'] = 'Choose how to structure your module:

**Weekly format**: Creates sequential weekly sections with clear titles and activities for each week of teaching.

**Themed format**: Organizes content into distinct learning themes that may span multiple weeks.';

// Template system strings
$string['templateheading'] = 'Curriculum Template Configuration';
$string['templateheading_desc'] = 'Configure curriculum modules that can be used as templates for AI generation';
$string['enabletemplates'] = 'Enable Template System';
$string['enabletemplates_desc'] = 'Allow users to select predefined modules as templates for AI generation';
$string['curriculumtemplates'] = 'Curriculum Template Modules';
$string['curriculumtemplates_desc'] = 'Define curriculum template modules. Format: One per line as "Template Name|Course ID|Section ID (optional)". Example:<br/>
Basic Mathematics|15<br/>
Advanced Chemistry|23|2<br/>
Introduction to Biology|31';
$string['selectcurriculum'] = 'Select Template';
$string['nocurriculum'] = 'Create from scratch';
$string['curriculumnotfound'] = 'Selected curriculum template not found or not accessible';
$string['invalidcurriculumconfig'] = 'Invalid curriculum template configuration. Please check admin settings.';
$string['curriculumtemplates_help'] = 'Select an existing module to use as a template for AI generation. The AI will analyze the structure, activities, and content of the selected template to create similar content for your prompt.

Choose "Create from scratch" to generate content without using any existing template.';

// Upload form error messages
$string['nofileuploadederror'] = 'No file was uploaded. Please select a file to upload.';
$string['nochaptersextractederror'] = 'Could not extract chapters from the uploaded file. Ensure it is a valid document (.doc, .docx, or .odt).';
$string['bookactivitycreated'] = 'Book activity "{$a}" has been created successfully with imported chapters.';

// Upload form labels
$string['contentfile'] = 'Upload document';
$string['contentfile_help'] = 'Select a document file (.doc, .docx, or .odt) to extract content from. The content will be parsed into chapters for the activity.';
$string['selectactivitytype'] = 'Activity type';
$string['activityintro'] = 'Activity description';
$string['generatetablabel'] = 'Generate from template';
$string['uploadtablabel'] = 'Activity from file';

// File upload workflow settings
$string['fileuploadheading'] = 'File Upload Workflow';
$string['fileuploadheading_desc'] = 'Configure the file upload workflow that allows users to create activities from uploaded documents.';
$string['enablefileupload'] = 'Enable file upload workflow';
$string['enablefileupload_desc'] = 'When enabled, users will see an "Activity from file" tab in the Module Assistant where they can upload documents to create book activities.';
