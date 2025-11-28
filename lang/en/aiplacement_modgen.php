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

$string['existingmodule'] = 'Base on existing module';
$string['addtemplate'] = 'Add another template';
$string['createfromscratch'] = 'Create from scratch';
$string['existingmodule_help'] = 'Optionally select one or more existing modules to use as templates for AI generation. The AI will analyze the structure, activities, and content of the selected modules to create similar content for your prompt. Choose "Create from scratch" to generate content without using any existing template. You can add up to 3 templates, and the AI will merge their structures.';

$string['prompt'] = 'Additional context or requests for the Assistant';
$string['submit'] = 'Preview Template';

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
$string['approveandcreate'] = 'Create';
$string['reenterprompt'] = 'Re-enter prompt';
$string['loadingthinking'] = 'Thinking... generating your request.';
$string['activitytypeunsupported'] = 'The generated activity type "{$a}" is not available on this site.';
$string['activitytypecreationfailed'] = 'Unable to create the "{$a}" activity automatically. Please review the course.';
$string['activity_created_coursemodule'] = 'Activity created (coursemodule id: {$a})';
$string['activity_created_instance'] = 'Activity created (instance id: {$a})';
$string['activity_created_cmid'] = 'Activity created (cmid: {$a})';
$string['unsupported_label'] = 'Unsupported';
$string['suggest_noresults'] = 'No suggestions were generated for this section.';
$string['creation_warnings'] = 'Creation warnings';
$string['url_created_placeholder'] = 'URL activity "{$a}" created with placeholder URL - please edit to set the correct link';
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
$string['moduletype_connected_weekly'] = 'Connected Weekly';
$string['moduletype_connected_theme'] = 'Connected Themed';
$string['moduletypeinstruction_weekly'] = 'Structure the module as sequential weekly teaching sections with clear titles, summaries, and an outline array of 3-5 bullet points describing activities/resources.';
$string['moduletypeinstruction_connected_weekly'] = '[PLACEHOLDER: Connected weekly format instruction - custom prompt to be added]';
$string['moduletypeinstruction_connected_theme'] = 'Structure the module as themed sections (themes), each containing multiple weeks of teaching. Each week has THREE subsections: "Pre-session", "Session", and "Post-session". 

For THEMES: Provide a title and (if enabled) a brief introduction explaining the theme content.

For WEEKS: Provide a generic, overarching weekly title and summary that introduces the week\'s overall learning outcomes and flow.

For ACTIVITIES: Place activities in the appropriate session subsection:
- "Pre-session" activities: Preparatory work, background reading, prerequisite materials students should engage with BEFORE the main session (e.g., "Review the X article", "Complete the Y preparation task")
- "Session" activities: Main learning activities conducted DURING the session time (e.g., "Take the quiz on X", "Participate in the X forum discussion", "Complete the X practical exercise")
- "Post-session" activities: Consolidation and reflection work AFTER the session (e.g., "Reflect on learning via the X assignment", "Review key concepts in the X book", "Complete the post-session quiz")

Structure each week object with:
- title: Week name/number
- summary: Generic overview of the week\'s learning flow and outcomes
- weeks array containing subsections for each pre/session/post component
- activities organized within the appropriate session subsection

Important: Each week MUST include at least one activity distributed across the three session types (pre, session, post) as appropriate for the learning design. Ensure activities are logically sequenced and pedagogically sound.';

$string['weeklybreakdown'] = 'Weekly breakdown';
$string['weeklyoutline'] = 'Weekly outline';
$string['themefallback'] = 'Theme overview';
$string['weekfallback'] = 'Weekly focus';
$string['presession'] = 'Pre-session';
$string['session'] = 'Session';
$string['postsession'] = 'Post-session';
$string['keepweeklabels'] = 'Keep dated headings and insert the subject title as a label';
$string['includeaboutassessments'] = 'Add "About Assessments" subsection to the first section';
$string['includeaboutlearning'] = 'Add "About Learning Outcomes" subsection to the first section';
$string['aboutassessments'] = 'About Assessments';
$string['aboutlearningoutcomes'] = 'About Learning Outcomes';
$string['returntocourse'] = 'Return to course home';
$string['promptsentheading'] = 'Prompt sent to AI subsystem';
$string['launchgenerator'] = 'Generate Template';
$string['modgenmodalheading'] = 'Module Assistant';
$string['modgenfabaria'] = 'Open Module Assistant';
$string['navtitle'] = 'Module Assistant';
$string['generatorbutton'] = 'Generate';
$string['generatorlabel'] = 'Template from file';
$string['explorelabel'] = 'Explore Module';
$string['explorebutton'] = 'Explore';
$string['modalintro'] = 'Click below to open the Module Assistant and generate module content.';
$string['closemodgenmodal'] = 'Close and return to module';
$string['modalinaccessible'] = 'To access the full Module Assistant form, please use the "Generate Template" link from the course navigation menu.';
$string['missingcourseid'] = 'Course ID is required to use the Module Assistant.';

// Tabbed interface
$string['generatetablabel'] = 'Generate from Template';

$string['supportingfiles'] = 'CSV structure file';
$string['supportingfiles_help'] = 'Upload a CSV file containing your module structure. When AI is disabled, the module will be created exactly as specified in the CSV. When AI is enabled, the CSV provides the base structure, and you can optionally enable "Expand on themes" to have AI enhance the content. Maximum 5MB.';

$string['longquery'] = 'This may take a moment while the AI processes your request.';
$string['connectedcurriculum30'] = '30 credit module';
$string['connectedcurriculum60'] = '60 credit module';
$string['connectedcurriculum120'] = '120 credit module';
$string['connectedcurriculumcredits'] = 'Module type';
$string['connectedcurriculuminstruction'] = 'Module credit volume: {$a} credit Connected Curriculum module.';

// Book activity
$string['activitytype_book'] = 'Book';
$string['bookdescription'] = 'Chapter-based content from uploaded document';

// Forum activity
$string['activitytype_forum'] = 'Forum';
$string['forumdescription'] = 'Collaborative discussion space for peer interaction and group communication';

// URL activity
$string['activitytype_url'] = 'External Link';
$string['urldescription'] = 'Links to external websites, articles, videos, or resources';

// Assignment activity
$string['activitytype_assignment'] = 'Assignment';
$string['assignmentdescription'] = 'Student work submission activity for formative and summative assessments, essays, projects, and reflective tasks';

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

**Connected Weekly**: Weekly format enhanced with the Flexible Sections layout to improve organization and usability for the new Connected Curriculum.

**Connected Themed**: Themed format enhanced with the Flexible Sections layout, organizing content into distinct learning themes for improved usability with the new Connected Curriculum.

**Note**: If you upload a CSV file, the format will be automatically detected based on its contents (themes vs weekly structure). You can override the auto-detected format by selecting a specific option here.';

// Form section headers
$string['templatesettings'] = 'Template Setup';
$string['suggestedcontent'] = 'Suggest Content';

// Generator introduction
$string['generatorintroduction'] = 'Use the Module Generator to create the structure of your Connected Module. Use a Module Layout file to upload and generate your layout.';

// Base on existing module settings
$string['existingmoduleheading'] = 'Base on Existing Module';
$string['existingmoduleheading_desc'] = 'Allow users to select existing modules as the basis for AI generation.';
$string['enableexistingmodules'] = 'Enable base on existing module';
$string['enableexistingmodules_desc'] = 'When enabled, users can select one or more existing modules to base their AI generation on. The AI will analyze the structure and activities of the selected modules and use them as a template for the new content.';

// Activity creation toggle
$string['expandonthemes'] = 'Expand on themes';
$string['expandonthemes_help'] = 'When enabled, AI will enhance section titles and descriptions using professional academic language suitable for UK higher education. Titles will be made clear, descriptive, and informative while maintaining the exact structure (same number of themes/weeks/sessions) from your CSV file. When disabled, section names remain exactly as specified in the CSV file.';
$string['generateexamplecontent'] = 'Generate example content';
$string['generateexamplecontent_help'] = 'When enabled, AI will generate example activities, session instructions, and theme introductions. This creates placeholder content to help you visualize the course structure. When disabled, only the section structure is created without example content.';
$string['createsuggestedactivities'] = 'Create suggested activities';
$string['createsuggestedactivities_help'] = 'When enabled, the generator will create activity shells as suggestions for your content. These are empty placeholder activities without content, ready for you to fill in with your own materials. When disabled, only section headings and descriptions will be created.';
$string['generatethemeintroductions'] = 'Generate theme introductions';
$string['generatethemeintroductions_help'] = 'When enabled, the AI will generate an introductory paragraph for each theme section to introduce students to that theme. These introductions will be placed in the summary/overview of each themed section.';
$string['generatesessioninstructions'] = 'Generate session instructions';
$string['generatesessioninstructions_help'] = 'When enabled, the AI will generate a paragraph for each session/week aimed at students. This explains what the session covers and lists the activities included. Helps students understand their learning path and what to focus on.';
$string['activityguidanceinstructions'] = 'ACTIVITY GUIDANCE - CORE REQUIREMENTS:

AUDIENCE: Write for UK university students with academic, mature language.

ACTIVITY LIMITS:
- Each week: minimum 1 activity, maximum 5 Moodle activities
- External links (URLs) and face-to-face do NOT count toward limit
- Activity selection should match topic complexity and learning outcomes

WEEKLY SUMMARY (REQUIRED):
- Clearly describe what students will learn and do
- Explain the LEARNING PURPOSE (what concept/skill each element develops)
- Provide HOW TO APPROACH guidance (sequence of activities)
- Reference activities by name naturally: "Take the [Name] quiz to check your understanding"
- Include face-to-face activities as descriptions: "Attend the Wednesday 2pm lecture on X"
- Link external resources to learning context: "Review the X article for background"

ACTIVITY DESCRIPTIONS (REQUIRED):
- Reinforce learning purposes from weekly summary
- Provide specific, practical guidance
- Link back to weekly learning objectives
- Create coherent flow from summary to activity

COHERENCE:
- Weekly summary and activity descriptions must tell a consistent story
- Students understand WHY they are doing activities, not just WHAT
- Activities build progressively toward learning outcomes
- External links support the learning narrative naturally

PEDAGOGICAL QUALITY:
- Align with learning outcomes and Bloom\'s taxonomy
- Vary activity types to maintain engagement
- Support diverse learning preferences

CRITICAL RULES:
- Do NOT use "label" activities - labels are display containers, not learning activities
- All items in "activities" array must be real activities (quiz, book, forum, url, assignment)
- Display important information in summaries or other activity types instead
- Be specific about what students will learn (outcomes focus)';

// AI enable/disable setting
$string['aienabledheading'] = 'AI Integration';
$string['aienabledheading_desc'] = 'Control whether the plugin uses AI to generate module structures or processes uploaded files directly.';
$string['enableai'] = 'Enable AI generation';
$string['enableai_desc'] = 'When enabled, uploaded files are processed exactly as specified, and you can make additional adjustments via the prompt field or base the structure on an existing module template. When disabled, only uploaded CSV files are processed with no AI adjustments available.';

// Placement options
$string['hideexistingsections'] = 'Hide existing sections and place new content at top';
$string['hideexistingsections_help'] = 'When enabled, all existing sections in the course will be hidden (made invisible to students), and the new module structure will be placed at the top of the course. This is useful when replacing an existing course structure with a new one while preserving the old content in a hidden state.';

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
$string['suggestheading'] = 'Suggest toolbar';
$string['suggestheading_desc'] = 'Choose a section to suggest appropriate Moodle activities for.';
$string['enablesuggest'] = 'Enable Suggest toolbar button';
$string['enablesuggest_desc'] = 'When enabled (and AI integration is active), a "Suggest" dropdown will be shown in the course toolbar to launch quick suggestion workflows.';
$string['suggest'] = 'Suggest';
$string['suggestactivities'] = 'Suggest activities';
$string['exploretitle'] = 'EXPLORE Module Insights';
$string['exploremenuitem'] = 'EXPLORE Module Insights';
$string['exploreheading'] = 'EXPLORE Module Insights';
$string['explorepedagogical'] = 'Pedagogical Analysis';
$string['explorelearningtypes'] = "Section Learning Type Mix";
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

// Module preview display strings
$string['moduleoverview'] = 'Module Overview';
$string['themes'] = 'Themes';
$string['weeks'] = 'Weeks';
$string['activities'] = 'Activities';
$string['downloadjson'] = 'Download JSON';
$string['viewjson'] = 'View JSON';
$string['nothemes'] = 'No themes defined';
$string['noweeks'] = 'No weeks defined';
$string['noactivities'] = 'No activities defined';
$string['regenerate'] = 'Regenerate';
$string['modulestructureinfo'] = 'This preview shows the structure and organisation of the module that will be created. It is a schematic representation and does not reflect how the content will appear in Moodle. Click "Create" below to proceed with creating the module in your course.';

// Quick add forms
$string['title'] = 'Title';
$string['summary'] = 'Summary';
$string['addtheme'] = 'Add Theme';
$string['addweek'] = 'Add Week';
$string['newtheme'] = 'New Theme';
$string['newweek'] = 'New Week';
$string['quickadd'] = 'Quick Add';
$string['themecount'] = 'How many themes do you want to create?';
$string['weekcount'] = 'How many weeks do you want to create?';
$string['weeksperTheme'] = 'How many weeks per theme?';
$string['invalidcount'] = 'Please select a number between 1 and 10';
$string['defaultthemename'] = 'Theme {$a}';
$string['defaultthemesummary'] = 'Placeholder theme structure. Edit this section to add your theme introduction and learning outcomes.';
$string['defaultweekname'] = 'Theme {$a->theme} - Week {$a->week}';
$string['defaultstandaloneweekname'] = 'Week {$a}';
$string['defaultweeksummary'] = 'Placeholder week structure. Edit this section to add your weekly overview and key topics.';
$string['themescreated'] = '{$a} theme(s) successfully created';
$string['weekscreated'] = '{$a} week(s) successfully created';
$string['returntocourseview'] = 'Return to course';
$string['erroracquiringlock'] = 'Could not acquire course lock. Another user may be editing this course.';
$string['errorconvertingformat'] = 'Could not convert course to flexsections format.';

