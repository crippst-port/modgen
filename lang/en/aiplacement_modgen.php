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
$string['moduletype_connected_weekly'] = 'Connected Weekly';
$string['moduletype_connected_theme'] = 'Connected Themed';
$string['moduletypeinstruction_weekly'] = 'Structure the module as sequential weekly teaching sections with clear titles, summaries, and an outline array of 3-5 bullet points describing activities/resources.';
$string['moduletypeinstruction_theme'] = 'Structure the module into distinct themes. For each theme provide a high-level summary and include an array of weekly entries that detail how the theme is delivered over time.';
$string['moduletypeinstruction_connected_weekly'] = '[PLACEHOLDER: Connected weekly format instruction - custom prompt to be added]';
$string['moduletypeinstruction_connected_theme'] = '[PLACEHOLDER: Connected themed format instruction - custom prompt to be added]';
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
$string['supportingfiles'] = 'Supporting documents';
$string['supportingfiles_help'] = 'Upload up to 5 supporting documents (for example: .docx, .odt, .txt, or .html). These files will be used as additional context by the Module Assistant when generating module structure and content. Content may be extracted from the files and included in the AI prompt. Maximum 10MB per file.';
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

// Forum activity
$string['activitytype_forum'] = 'Forum';
$string['forumdescription'] = 'Collaborative discussion space for peer interaction and group communication';

// URL activity
$string['activitytype_url'] = 'External Link';
$string['urldescription'] = 'Links to external websites, articles, videos, or resources';

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
$string['generatetablabel'] = 'Generate module template';
$string['uploadtablabel'] = 'Activity from file';

// File upload workflow settings
$string['fileuploadheading'] = 'File Upload Workflow';
$string['fileuploadheading_desc'] = 'Configure the file upload workflow that allows users to create activities from uploaded documents.';
$string['enablefileupload'] = 'Enable file upload workflow';
$string['enablefileupload_desc'] = 'When enabled, users will see an "Activity from file" tab in the Module Assistant where they can upload documents to create book activities.';

// Activity creation toggle
$string['createsuggestedactivities'] = 'Create suggested activities';
$string['createsuggestedactivities_help'] = 'When enabled, the generator will create activity shells as suggestions for your content. These are empty placeholder activities without content, ready for you to fill in with your own materials. When disabled, only section headings and descriptions will be created.';
$string['activityguidanceinstructions'] = 'ACTIVITY GUIDANCE AND COHERENCE - CRITICAL REQUIREMENTS:

AUDIENCE: All summaries, guidance, and activity descriptions must be written for UK UNIVERSITY STUDENTS. Use appropriate academic language and assume prior tertiary-level education.

ACTIVITY REQUIREMENTS:
- Each week MUST include AT LEAST ONE activity, maximum 5 Moodle activities per week (or as many as the content supports, up to 5)
- External links and face-to-face activities do not count toward the activity limit and can be included as described below
- The number and type of activities should be led by the topic complexity and learning outcomes
- All suggested activities MUST be pedagogically sound and evidence-based
- Focus on the learning outcome, and naturally reference the activity when it helps clarify the task

EXTERNAL LINKS (URLs):
- Use external links to direct students to reading materials, reference websites, videos, multimedia content, or context related to other activities
- External links do NOT count toward the activity limit and can be used liberally to supplement learning
- Examples: "Review the X article to understand background", "Watch the X video for context before the quiz", "Use the X database for references"
- Include externalurl field with full URL (e.g., "https://example.com")

FACE-TO-FACE ACTIVITIES:
- If the module includes face-to-face components, include these as descriptive text in the weekly summary
- Face-to-face activities do NOT require associated Moodle activities
- Examples in weekly summary: "Attend the Wednesday 2pm lecture on X topic", "Complete face-to-face group work in lab session", "Present findings in class"
- Keep descriptions clear about timing, location expectations, and learning purpose

1. IN EACH WEEKLY/SECTION SUMMARY, YOU MUST:
   - Clearly describe what students will learn and do this week
   - Explain the LEARNING PURPOSE of each element (what concept or skill it develops)
   - Provide HOW TO APPROACH guidance (what students should do first, then next, etc.)
   - Explain what students will gain or be able to do after engaging with the activities
   - Use natural, conversational language appropriate for university students
   - Reference activities by name when it aids clarity, e.g., "Use the [Activity Name] book to read about X" or "Take the [Activity Name] quiz to check your understanding"
   - Include any face-to-face activities as natural descriptions of in-class or on-campus activities
   - Reference external reading links when they provide important context or prerequisites

2. IN EACH ACTIVITY DESCRIPTION, YOU MUST:
   - Expand on and reinforce the learning purposes from the weekly summary
   - Provide specific, practical guidance for engaging with the activity
   - Link back to the learning objectives mentioned in the summary
   - Make the activity description coherent and naturally flowing from the summary

3. COHERENCE REQUIREMENT:
   - The weekly summary and activity descriptions MUST tell a consistent story
   - Students should understand not just WHAT to do, but WHY they are doing it and what it contributes to their learning
   - Guidance must flow logically from week-level overview to specific activity engagement
   - External links should be naturally woven in to support the learning narrative

PEDAGOGICAL SOUNDNESS:
- Activities should align with Bloom\'s taxonomy (remember, understand, apply, analyze, evaluate, create)
- Vary activity types throughout the week to maintain student engagement
- Ensure activities build progressively toward the learning outcomes
- Consider diverse learning preferences (visual, auditory, kinesthetic, reading/writing)

EXAMPLE SUMMARY FORMAT: "This week you\'ll explore [Topic] through structured learning. Begin by reviewing the [URL Name] article for background context, then use the [Name] book to read about [concept], which helps you understand [key idea]. You\'ll then take the [Name] quiz to check your understanding and identify areas for deeper engagement. Attend the Wednesday lecture on [topic] to discuss applications with peers. By working through these elements, you\'ll develop [learning outcome]."

LANGUAGE GUIDELINES:
- Write for mature learners; avoid patronising or overly simple language
- Focus on learning outcomes and intellectual development, but can naturally reference activity names when helpful
- Use natural phrases like "explore," "investigate," "develop understanding" combined with activity references where appropriate
- Examples: "Use the X book to...", "Work through the X quiz to...", "Discuss in the X forum how...", "Review the X link for...", "Explore the X resource to...", "Review the X reading to understand...", "Attend the X lecture to..."
- Vary your sentence structure and phrasing to maintain engagement
- Be specific about what students will learn, not just what they\'ll do

IMPORTANT - DO NOT USE LABELS:
- Never include "label" activity types in your response - they are not learning activities
- Labels are content display containers, not pedagogical activities
- All items in the "activities" array must be real learning activities (quiz, book, forum, url, assignment, etc.)
- If you need to display important information, use a different activity type or include it in the section summary instead';

// AI prompt configuration
$string['aipromptheading'] = 'AI Generation Settings';
$string['aipromptheading_desc'] = 'Configure the pedagogical guidance and institutional context sent to the AI for module generation. The JSON schema and technical requirements are managed by the system and cannot be modified here.';
$string['baseprompt'] = 'Pedagogical Guidance';
$string['baseprompt_desc'] = 'This guidance is sent to the AI to establish pedagogical context, institutional approach, and quality standards. Include information about your institution\'s teaching philosophy, any mandatory pedagogical frameworks, accessibility requirements, or specific learning design principles. The system automatically appends the technical JSON schema requirements to this guidance.';

// Module exploration feature
$string['explorationheading'] = 'Module EXPLORE Insights Report';
$string['explorationheading_desc'] = 'Enable pedagogical insights report to be generated by AI for any moodle module by a user with editing rights to that module.';
$string['enableexploration'] = 'Enable module EXPLORE insights report';
$string['enableexploration_desc'] = 'When enabled, users will see an "EXPLORE module insights" link in the course module menu. This provides AI-generated pedagogical insights, learning type breakdowns, and activity summaries.';
$string['exploretitle'] = 'EXPLORE Module Insights';
$string['exploremenuitem'] = 'EXPLORE Module Insights';
$string['exploreheading'] = 'EXPLORE Module Insights';
$string['explorepedagogical'] = 'Pedagogical Analysis';
$string['explorelearningtypes'] = "Laurillard's Learning Types";
$string['exploreactivities'] = 'Activity Breakdown';
$string['exploreloading'] = 'Generating EXPLORE module insights...';
$string['exploreerror'] = 'Unable to generate module EXPLORE insights report at this time. Please try again later.';
$string['explorationdisabled'] = 'Module EXPLORE insights report feature is not enabled.';
$string['analysiscard'] = 'Analysis Summary';
$string['strengths'] = 'Key Strengths';
$string['keyimprovements'] = 'Areas to Improve';
$string['downloadreport'] = 'Download PDF Report';
$string['downloadreporthelp'] = 'Download the EXPLORE module insights report as a PDF file.';
$string['exploreheading'] = 'EXPLORE Module Insights';
$string['refreshinsights'] = 'Refresh insights';
$string['refreshinsightshelp'] = 'Refresh insights by calling AI (clears cache)';
$string['downloadpdf'] = 'Download as PDF';
$string['downloadpdfhelp'] = 'Download report as PDF';
$string['loadinginsights'] = 'Loading insights...';
$string['activitysummary'] = 'Activity Summary';
$string['totalactivities'] = 'Total activities:';
$string['improvementsuggestions'] = 'Improvement Suggestions';

// Validation error strings
$string['generationfailed'] = 'Generation Failed';
$string['validationerrorhelp'] = 'The AI response was malformed and cannot be used to create content. This sometimes happens when the AI double-encodes the response or returns an incorrect structure. Please try generating again with the same or modified prompt.';
$string['tryagain'] = 'Try Again';


