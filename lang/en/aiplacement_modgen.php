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

// Error strings
$string['jsontoolarge'] = 'JSON data is too large to process';
$string['invalidjson'] = 'Invalid JSON data: {$a}';

$string['existingmodule'] = 'Base on existing module';
$string['inputrequired'] = 'You must provide at least one of the following: a text prompt, upload a CSV file, select a structure, or choose an existing module to base this on.';
$string['templatefromprompt'] = 'Structure from prompt';
$string['addtemplate'] = 'Add another template';
$string['createfromscratch'] = 'Create from scratch';
$string['existingmodule_help'] = 'Optionally select one or more existing modules to use as templates for AI generation. The AI will analyse the structure, activities, and content of the selected modules to create similar content for your prompt. Choose "Create from scratch" to generate content without using any existing template. You can add up to 3 templates, and the AI will merge their structures.';

$string['prompt'] = 'Additional context or requests for the Assistant';
$string['submit'] = 'Preview Structure';

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
$string['aigenerated'] = 'AI suggested';
$string['aigenmarker_tooltip'] = 'Unedited AI suggestion. (This marker is removed after editing)';
$string['aigen_unedited'] = 'unedited AI suggestions';
$string['aigen_none'] = 'No unedited AI suggestions';
$string['aigen_list_title'] = 'Unedited AI Suggestions';
$string['aigen_list_description'] = 'These activities were created by AI and have not yet been reviewed or edited.';
$string['aigen_list_empty'] = 'There are no unedited AI-generated activities in this course.';
$string['aigen_list_count'] = 'unedited AI-generated activities';
$string['aigen_list_hint'] = 'Click Edit to review and modify each activity. The AI marker will be removed once you save changes.';
$string['aigen_view_all'] = 'View all';
$string['aigen_view_count'] = 'View {$a} unedited suggestions';
$string['aigen_toggle_hide'] = 'Hide list';
$string['aigen_toggle_show'] = 'Show list';
$string['aigen_hide_activity'] = 'Hide from students';
$string['aigen_show_activity'] = 'Show to students';
$string['visibility'] = 'Visibility';
$string['suggestpedagogicalguidance'] = 'Pedagogical guidance for suggestions';
$string['suggestpedagogicalguidance_desc'] = 'Customise the pedagogical guidance included in the AI prompt when generating activity suggestions. This guidance helps the AI design appropriate learning activities. Each line should start with a dash (-).';
$string['hidden'] = 'Hidden';
$string['backtocourse'] = 'Back to course';
$string['created'] = 'Created';
$string['type'] = 'Type';
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
$string['moduletypeinstruction_connected_weekly'] = 'Structure the module as sequential weekly teaching sections. Each week has THREE subsections: "Pre-session", "Session", and "Post-session".

For WEEKS: Provide a generic, overarching weekly title and summary that introduces the week\'s overall learning outcomes and flow.

For ACTIVITIES: Place activities in the appropriate session subsection:
- "Pre-session" activities: Preparatory work, background reading, prerequisite materials students should engage with BEFORE the main session (e.g., "Review the X article", "Complete the Y preparation task")
- "Session" activities: Main learning activities conducted DURING the session time (e.g., "Take the quiz on X", "Participate in the X forum discussion", "Complete the X practical exercise")
- "Post-session" activities: Consolidation and reflection work AFTER the session (e.g., "Reflect on learning via the X assignment", "Review key concepts in the X book", "Complete the post-session quiz")

Structure each week object with:
- title: Week name/number
- summary: Generic overview of the week\'s learning flow and outcomes
- sessions object containing subsections for each pre/session/post component
- activities organised within the appropriate session subsection

Important: Each week MUST include at least one activity distributed across the three session types (pre, session, post) as appropriate for the learning design. Ensure activities are logically sequenced and pedagogically sound.';
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
- activities organised within the appropriate session subsection

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
$string['launchgenerator'] = 'Structure from file';
$string['modgenmodalheading'] = 'Module Assistant';
$string['modgenfabaria'] = 'Open Module Assistant';
$string['navtitle'] = 'Module Assistant';
$string['generatorbutton'] = 'Create';
$string['generatorlabel'] = 'Structure from file';
$string['modalintro'] = 'Click below to open the Module Creator and create module content.';
$string['closemodgenmodal'] = 'Close and return to module';
$string['modalinaccessible'] = 'To access the full Module Creator form, please use the "Create Module" link from the course navigation menu.';
$string['missingcourseid'] = 'Course ID is required to use the Module Assistant.';

// Tabbed interface
$string['generatetablabel'] = 'Generate from Structure';

$string['supportingfiles'] = 'Upload structure file';
$string['supportingfiles_help'] = 'Upload a CSV file containing your module structure. The module will be created as specified in the CSV. Maximum 5MB.';

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

// Learning activity (metadata module)
$string['activitytype_learningactivity'] = 'Learning Activity';
$string['learningactivity_created'] = 'Created learning activity metadata for {$a->name} (type: {$a->type})';
$string['learningactivity_section'] = 'Section';

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
$string['aiprocessingdetail'] = 'AI is analysing your request and generating module content. This process may take several minutes for complex requests.';
$string['prompt_help'] = 'Describe what you want to create for your module. Be specific about the topic, learning objectives, and type of activities you want. More detailed prompts will give better results but may take longer to process.';
$string['moduletype_help'] = 'Choose how to structure your module:

**Connected Weekly**: Weekly format enhanced with the Flexible Sections layout to improve organisation and usability for the new Connected Curriculum.

**Connected Themed**: Themed format enhanced with the Flexible Sections layout, organising content into distinct learning themes for improved usability with the new Connected Curriculum.

**Note**: If you upload a CSV file, the format will be automatically detected based on its contents (themes vs weekly structure). You can override the auto-detected format by selecting a specific option here.';

// Form section headers
$string['selecttemplate'] = 'Select Structure';
$string['uploadtemplatefile'] = 'Upload Structure File';
$string['selectoruploadtemplate'] = 'Select or Upload Structure File';
$string['templatesettings'] = 'Structure Setup';
$string['suggestedcontent'] = 'Suggest Content';

// CSV Template Library
$string['csvtemplatelibrary'] = 'CSV Structure Library';
$string['csvtemplatelibrary_desc'] = 'Manage pre-made CSV structures that users can select when generating modules.';
$string['managetemplates'] = 'Manage CSV Structure files';
$string['managetemplates_desc'] = 'Upload, edit, and organise CSV structures. <a href="{$a}">Manage templates</a>';
$string['templatename'] = 'Structure name';
$string['templatedescription'] = 'Description';
$string['csvfile'] = 'CSV file';
$string['csvtemplate'] = 'Select a pre-made structure';
$string['csvtemplate_help'] = 'Select a pre-made CSV to use as your module structure. You can either apply it directly or download it to edit offline before uploading.';
$string['notemplateselected'] = '-- None selected --';
$string['downloadtemplate'] = 'Download to edit';
$string['applytemplate'] = 'Use selected structure';
$string['addnewtemplate'] = 'Add new structure';
$string['edittemplate'] = 'Edit structure';
$string['deletetemplate'] = 'Delete structure';
$string['confirmdeletetemplate'] = 'Are you sure you want to delete the structure "{$a}"? This cannot be undone.';
$string['templatedeleted'] = 'Structure deleted successfully';
$string['templatesaved'] = 'Structure saved successfully';
$string['templatecreated'] = 'Structure created successfully';
$string['templateupdated'] = 'Structure updated successfully';
$string['notemplates'] = 'No structures available yet. Add your first structure below.';
$string['moveup'] = 'Move up';
$string['movedown'] = 'Move down';
$string['csvvalidationfailed'] = 'CSV validation failed: {$a}';
$string['invalidcsvstructure'] = 'The uploaded CSV file does not have a valid structure.';
$string['templatefilenotfound'] = 'Structure file not found';
$string['templatename_help'] = 'Give this structure a descriptive name that users will see when selecting structures.';
$string['templatedescription_help'] = 'Optionally provide a brief description of what this structure contains or when it should be used.';
$string['timecreated'] = 'Date created';

// Generator introduction
$string['generatorintroduction'] = 'This page allows you to create your course structure in several ways. You can upload a CSV structure file to define sections, weeks, or themes, or optionally select a pre-made structure from the library. When you upload a structure file, the form will read its contents and create your course layout. You can edit titles and descriptions after the strucure has been created on your module. Choose whether to place the new structure at the top of your course or after existing content.';

// Base on existing module settings
$string['existingmoduleheading'] = 'Base on Existing Module';
$string['existingmoduleheading_desc'] = 'Allow users to select existing modules as the basis for AI generation.';
$string['enableexistingmodules'] = 'Enable base on existing module';
$string['enableexistingmodules_desc'] = 'When enabled, users can select one or more existing modules to base their AI generation on. The AI will analyse the structure and activities of the selected modules and use them as a template for the new content.';

// Activity creation toggle
$string['expandonthemes'] = 'Expand on themes';
$string['expandonthemes_help'] = 'When enabled, AI will enhance section titles and descriptions. Titles will be made clear, descriptive, and informative while maintaining the exact structure (same number of themes/weeks/sessions) from your CSV file. When disabled, section names remain exactly as specified in the CSV file.';
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
$string['contentplacement'] = 'Content placement';
$string['contentplacement_help'] = 'Choose whether to place new module content at the top of the course or after existing content.';
$string['hideexistingsections'] = 'Hide existing sections and place new content at top';
$string['hideexistingsections_help'] = 'When enabled, all existing sections in the course will be hidden (made invisible to students), and the new module structure will be placed at the top of the course. This is useful when replacing an existing course structure with a new one while preserving the old content in a hidden state.';

// AI prompt configuration
$string['aipromptheading'] = 'AI Generation Settings';
$string['aipromptheading_desc'] = 'Configure the pedagogical guidance and institutional context sent to the AI for module generation. The JSON schema and technical requirements are managed by the system and cannot be modified here.';
$string['baseprompt'] = 'Pedagogical Guidance';
$string['baseprompt_desc'] = 'This guidance is sent to the AI to establish pedagogical context, institutional approach, and quality standards. Include information about your institution\'s teaching philosophy, any mandatory pedagogical frameworks, accessibility requirements, or specific learning design principles. The system automatically appends the technical JSON schema requirements to this guidance.';

// Suggest toolbar
$string['suggestheading'] = 'Suggest toolbar';
$string['suggestheading_desc'] = 'Analyses your existing module section content and intelligently recommends complementary Moodle activities to enhance student learning and pedagogical balance.';
$string['enablesuggest'] = 'Enable Suggest toolbar button';
$string['enablesuggest_desc'] = 'When enabled (and AI integration is active), a "Suggest" dropdown will be shown in the course toolbar to launch quick suggestion workflows.';
$string['suggest'] = 'Suggest';
$string['suggestactivities'] = 'Activities from section';
$string['suggestlearningtypes'] = 'Section Learning Type Mix';
$string['suggestpedagogicalguidance'] = 'Pedagogical guidance for suggestions';
$string['suggestpedagogicalguidance_desc'] = 'Customise the pedagogical guidance included in the AI prompt when generating activity suggestions. This guidance helps the AI design appropriate learning activities. Each line should start with a dash (-).';

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
$string['addtheme'] = 'Add Theme(s)';
$string['addweek'] = 'Add Week(s)';
$string['newtheme'] = 'New Theme(s)';
$string['newweek'] = 'New Week(s)';
$string['quickadd'] = 'Quick Add';
$string['themecount'] = 'How many themes do you want to create?';
$string['themecount_help'] = 'Enter the number of themes to create. The maximum is set by your site administrator.';
$string['weekcount'] = 'How many weeks do you want to create?';
$string['weekcount_help'] = 'Enter the number of weeks to create. The maximum is set by your site administrator.';
$string['weeksperTheme'] = 'How many weeks per theme?';
$string['weeksperTheme_help'] = 'Enter the number of weeks to create within each theme. The maximum is set by your site administrator.';
$string['invalidcount'] = 'Please enter a number between {$a->min} and {$a->max}';
$string['invalidthemecount'] = 'Please enter a number between 1 and {$a}';
$string['invalidweekcount'] = 'Please enter a number between 1 and {$a}';
$string['invalidweeksperTheme'] = 'Please enter a number between 1 and {$a}';
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
$string['introductionsectionname'] = 'Introduction & General Information';
$string['assessmentssectionname'] = 'Assessments';

// Dates for sections feature
$string['datesforsections'] = 'Dates to sections...';
$string['holidaydates'] = 'University Holiday Dates';
$string['holidaydates_desc'] = 'Enter university holiday dates (one per line). Dates will be excluded when calculating weekly section dates. Format: Holiday Name: DDMMYYYY-DDMMYYYY. Examples accepted: 25122024-05012025, 25/12/2024-05/01/2025, or 25-12-2024 to 05-01-2025';
$string['holidaydates_format_example'] = 'Example: Christmas Break: 25122024-05012025';
$string['applydates'] = 'Apply Selected Dates';
$string['removealldates'] = 'Remove Selected Dates';
$string['currentname'] = 'Current Name';
$string['proposedname'] = 'Proposed Name (with dates)';
$string['sectiontype'] = 'Type';
$string['includeparentsections'] = 'Include themed sections';
$string['includeparentsections_help'] = 'When enabled, themed sections (top-level parents) will also receive dates based on the date range of their child weeks.';
$string['selectsections'] = 'Select sections to apply dates';
$string['datesappliedsuccess'] = '{$a} section(s) updated with dates';
$string['datesremovedsuccess'] = 'Dates removed from {$a} section(s)';
$string['datesrecalculated'] = 'Dates recalculated';
$string['invaliddateformat'] = 'Invalid holiday date format on line {$a}. Expected format: Holiday Name: DDMMYYYY-DDMMYYYY';
$string['nosectionsselected'] = 'Please select at least one section';
$string['themesection'] = 'Theme';
$string['parentsection'] = 'Parent';
$string['weeksection'] = 'Week';
$string['themedSections'] = 'Theme Sections';
$string['weekSections'] = 'Week Sections';
$string['nosectionsavailable'] = 'No sections available for date assignment';
$string['calculatingdates'] = 'Calculating dates...';
$string['selectallthemes'] = 'Select all top sections';
$string['selectallweeks'] = 'Select all sub sections';

// Help and Advice Links
$string['helpandadvice'] = 'Help and Advice';
$string['helplinksheading'] = 'Help and Advice Links';
$string['helplinksheading_desc'] = 'Configure up to 5 external help links that will appear in the Module Assistant toolbar dropdown menu. Links will only appear if both text and URL are provided.';
$string['helplinktext'] = 'Help Link {$a} Text';
$string['helplinktext_desc'] = 'Display text for the help link';
$string['helplinkurl'] = 'Help Link {$a} URL';
$string['helplinkurl_desc'] = 'Full URL for the help resource (must start with http:// or https://)';

// Section Creation Limits
$string['sectionlimitsheading'] = 'Section Creation Limits';
$string['sectionlimitsheading_desc'] = 'Configure maximum number of sections that can be created at once through quick-add forms and CSV file uploads. These limits help prevent accidental creation of excessive course structure.';
$string['maxquicksections'] = 'Maximum themes or weeks';
$string['maxquicksections_desc'] = 'Maximum number of themes OR weeks that can be created at once using the quick-add forms. This applies when creating standalone themes or standalone weeks. Default: 30';
$string['maxweeksperTheme'] = 'Maximum weeks per theme';
$string['maxweeksperTheme_desc'] = 'Maximum number of weeks that can be created within EACH theme when using the themed structure. For example, creating 10 themes with 5 weeks each would create 50 total sections (10 parent themes + 40 child weeks). Set this lower than the maximum themes/weeks to control total section creation. Default: 5';
$string['maxcsvsections'] = 'Maximum CSV sections';
$string['maxcsvsections_desc'] = 'Maximum total number of sections (themes + weeks) that can be created from a CSV file upload. Set to 0 for unlimited (not recommended). Default: 50';
$string['csvlimitexceeded'] = 'CSV file contains {$a->count} sections, which exceeds the maximum allowed limit of {$a->max}. Please reduce the number of sections in your CSV file.';

// Capabilities
$string['modgen:viewexplore'] = 'View AI course insights and analytics';
$string['modgen:managestructure'] = 'Manage course structure (themes, weeks, dates)';
$string['modgen:generatewithprompt'] = 'Generate content with AI prompt';
$string['modgen:generatefromtemplate'] = 'Generate content from templates';
$string['modgen:usesuggest'] = 'Use AI activity suggestions';
$string['modgen:managetemplates'] = 'Manage site-wide templates';
