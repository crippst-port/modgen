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
$string['locktimeout'] = 'Another user is currently creating content in this course. Please try again in a few moments.';
$string['sectorcreationfailed'] = 'Failed to create section "{$a}". Check logs for details.';
$string['invalidparentsection'] = 'Parent section {$a} does not exist';
$string['maxsectiondepthreached'] = 'Maximum section depth of {$a} reached';
$string['circularsectionparent'] = 'Cannot create circular reference: Section {$a->child} cannot be its own parent';
$string['circularsectionchain'] = 'Cannot create circular reference: Section {$a->child} with parent {$a->parent} would create a loop in the hierarchy';
$string['themecreationfailed'] = 'Failed to create themes. Check logs for details.';
$string['jsonsectionscreationfailed'] = 'Failed to create sections from JSON: {$a}';
$string['cacherebuildfailed'] = 'Course cache rebuild failed: {$a}';
$string['jobqueued'] = 'Creation queued - {$a} sections will be created in the background. This may take a few minutes.';
$string['jobqueuedinfo'] = 'You can return to your course while this runs. Refresh the course page in a few minutes to see the new sections.';
$string['jobrunning'] = 'Job is running...';
$string['jobcompleted'] = 'Job completed successfully';
$string['jobfailed'] = 'Job failed: {$a}';
$string['jobinterrupted'] = 'A previous attempt was interrupted before completing (the course may be too large to process in one run). To avoid creating duplicate sections, the job was not retried. Please review the course and, if needed, start a smaller generation.';
$string['checkingstatus'] = 'Checking job status...';
$string['invalidaction'] = 'Invalid action';
$string['creatingstructure'] = 'Creating Course Structure';

// Job status page strings
$string['jobstatuspage_title'] = 'Job Status';
$string['jobstatus_queued'] = 'Queued';
$string['jobstatus_queued_desc'] = 'Your job is waiting to be processed...';
$string['jobstatus_running'] = 'Creating sections...';
$string['jobstatus_running_desc'] = 'Please wait while your course structure is being created.';
$string['jobstatus_completed'] = 'Completed successfully!';
$string['jobstatus_completed_redirect'] = 'Redirecting to your course in a few seconds...';
$string['jobstatus_failed'] = 'Job failed';
$string['jobstatus_will_retry'] = 'This job will automatically retry in a moment...';
$string['jobaction_create_themes'] = 'Creating {$a->themes} theme(s) with {$a->weeks} week(s) each';
$string['jobaction_create_weeks'] = 'Creating {$a} standalone week(s)';
$string['jobaction_create_from_json'] = 'Creating {$a} section(s) from uploaded file';
$string['jobaction_generic'] = 'Creating course structure';
$string['jobdetails'] = 'Job Details';
$string['jobid'] = 'Job ID';
$string['timecreated'] = 'Created';
$string['unknownerror'] = 'An unknown error occurred';

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
$string['hidden'] = 'Hidden';
$string['backtocourse'] = 'Back to course';
$string['created'] = 'Created';
$string['type'] = 'Type';
$string['labelcreated'] = 'Label created (cmid: {$a})';
$string['quizcreated'] = 'Quiz created: {$a}';
$string['activitytype_quiz'] = 'Quiz';
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
$string['supportingfiles_help'] = 'Upload a CSV file containing your module structure. The module will be created as specified in the CSV. Maximum 5MB. Note: If you upload a file, it will be used even if you have also selected a template from the dropdown below.';

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

$string['aipolicynotaccepted'] = 'You must accept the statement before using the Module Assistant.';
$string['aipolicyacceptance'] = 'AI statement Acceptance Required';
$string['acceptaipolicy'] = 'I agree to the terms of AI use in this system';
$string['aipolicyinfo'] = 'This tool does not currently include active AI functionality. It is designated as AI-enabled to allow for potential future enhancements. Please accept to continue.';
$string['timeout'] = 'AI Request Timeout (seconds)';
$string['timeout_desc'] = 'Maximum time to wait for AI responses before timing out. Default is 300 seconds (5 minutes).';
$string['airatelimit'] = 'AI Request Rate Limit (per hour)';
$string['airatelimit_desc'] = 'Maximum number of AI generation requests a user can make per hour. This helps prevent API quota exhaustion and abuse. Default is 10 requests per hour. Set to 0 for unlimited (not recommended).';
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
$string['csvtemplate_help'] = 'Select a pre-made CSV to use as your module structure. You can either apply it directly or download it to edit offline before uploading. Note: If you upload a file below, the uploaded file will take priority over any template selected here.';
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
$string['hideexistingsections'] = 'Hide existing sections';
$string['hideexistingsections_help'] = 'When enabled, all existing sections in the course will be hidden (made invisible to students). The new module structure will remain visible. This is useful when replacing an existing course structure with a new one while preserving the old content in a hidden state.';
$string['createsummaryactivities'] = 'Add a section summary activity to each section';
$string['createsummaryactivities_help'] = 'When enabled, a Learning Activity "section summary" is added to the top of each week and session to hold learning design details (duration, learning type, instructions). Leave it off for a lighter structure of empty sections you can fill in later, which also makes large structures noticeably faster to create.';
$string['createsummaryactivitiesai'] = 'Add a section summary activity to each section';
$string['createsummaryactivitiesai_help'] = 'When enabled, a Learning Activity "section summary" is added to the top of each week and session to hold learning design details (duration, learning type, instructions). Leave it off for a lighter structure of empty sections you can fill in later, which also makes large structures noticeably faster to create.

Note: if you are generating with AI, turning this off means any learning-design details the AI produced (durations, learning types, instructions) will not be added to the course — only the section structure is created.';

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
$string['defaultthemesummary'] = 'This is a new empty theme. Replace this text with a description of the content of this theme.';
$string['defaultweekname'] = 'Theme {$a->theme} - Week {$a->week}';
$string['defaultstandaloneweekname'] = 'Week {$a}';
$string['defaultweeksummary'] = 'This is a new empty Week. Replace this text with a description of the content of this week.';
$string['themescreated'] = '{$a} theme(s) successfully created';
$string['weekscreated'] = '{$a} week(s) successfully created';
$string['seedetails'] = 'See details';
$string['returntocourseview'] = 'Return to course';
$string['erroracquiringlock'] = 'Could not acquire course lock. Another user may be editing this course.';
$string['errorconvertingformat'] = 'Could not convert course to flexsections format.';
$string['introductionsectionname'] = 'Introduction & General Information';
$string['assessmentssectionname'] = 'Assessments';

// Error messages for exceptions
$string['flexsectionsnotinstalled'] = 'The flexsections course format plugin is required but not installed. Please install the flexsections plugin before using this feature.';
$string['flexsectionssetfailed'] = 'Failed to set course format to flexsections. Current format: {$a}';
$string['flexsectionsmethodnotavailable'] = 'Flexsections create_new_section method not available';
$string['themesectioncreatefailed'] = 'Failed to create theme section \'{$a}\'';
$string['sectionnotfound'] = 'Section not found in course';
$string['errorformatnotflexsections'] = 'Course format must be flexsections';
$string['errorflexsectionsmissingmethod'] = 'Required flexsections method is not available';
$string['invalidsectionparent'] = 'Invalid section parent number. Must be a non-negative integer.';
$string['invalidsectionname'] = 'Section name cannot be empty';

// Navigation and UI messages
$string['creationinprogress'] = 'Creation in progress. Leaving this page will abandon your changes.';
$string['generatingcontent'] = 'Generating content... this may take a minute.';
$string['genericerror'] = 'An error occurred';

// Accessibility
$string['successicon'] = 'Success';
$string['showdetailsaria'] = 'Show creation details';
$string['sectionscreatedsuccess'] = 'Successfully created {$a} section(s)';

// Dates for sections feature
$string['datesforsections'] = 'Dates to sections...';
$string['holidaydates'] = 'University Holiday Dates';
$string['holidaydates_desc'] = 'Enter University holiday dates (one per line). When a week overlaps with a holiday, the holiday name will be appended to the section title. Format: Holiday Name: DDMMYYYY-DDMMYYYY. Examples accepted: 25122024-05012025, 25/12/2024-05/01/2025, or 25-12-2024 to 05-01-2025. Note: For best results, holiday dates should start on a Monday and end on a Sunday to align with teaching weeks.';
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
$string['maxtotalsections'] = 'Maximum total sections per course';
$string['maxtotalsections_desc'] = 'Hard safety limit on the total number of sections a course may contain (existing sections plus those a generation would add). The flexsections format renumbers and rebuilds the whole course on every section insert, so creation slows down quadratically as a course grows and very large courses can exhaust memory. Generation is refused before it starts if it would push the course past this limit. Raise it only if your server has ample memory and you accept slower generation. Default: 300';
$string['sectionlimitexceeded'] = 'That\'s too many sections to create at once. This would take the course to {$a->total} sections, but the limit is {$a->max} (it already has {$a->existing}). Please try again with fewer themes or weeks.';

// Loading indicator strings
$string['processingrequest'] = 'Processing your request...';
$string['pleasewait'] = 'Please wait';
$string['creatingthemes'] = 'Creating themes...';
$string['creatingweeks'] = 'Creating weeks...';
$string['applyingdates'] = 'Applying dates to sections...';
$string['removingdates'] = 'Removing dates from sections...';
$string['calculatingdates'] = 'Calculating dates...';
$string['generatingsuggestions'] = 'Generating activity suggestions...';
$string['loadingform'] = 'Loading form...';
$string['creatingsections'] = 'Creating sections...';

// Dates form
$string['selectsections_hierarchical'] = 'Select sections to add dates to. New dates appear as badges <span class="badge bg-info text-white">Dec 21-27:</span> on the right in this preview, When applied, dates will be added to section titles and any existing dates will be updated. Use the toggle buttons to quickly select or deselect groups.';
$string['sectiontitle'] = 'Section Title';
$string['newdate'] = 'New Date';
$string['toggleall'] = 'Toggle All';
$string['toggletoplevel'] = 'Toggle Top-Level';
$string['haschildren'] = 'Has subsections';
$string['startdate'] = 'Start date for calculation';
$string['startdate_help'] = 'Choose the date from which to start calculating week dates. This will be used as the first day of the first selected section. Dates will be calculated sequentially for each checked section, skipping any configured holidays.';

$string['modgen:managestructure'] = 'Manage course structure (themes, weeks)';
$string['modgen:managedates'] = 'Manage dates to sections';
$string['modgen:generatewithprompt'] = 'Generate content with AI prompt';
$string['modgen:generatefromtemplate'] = 'Generate content from templates';
$string['modgen:usesuggest'] = 'Use AI activity suggestions';
$string['modgen:managetemplates'] = 'Manage site-wide templates';
$string['sectionplaceholder'] = 'Section {$a}';

// Admin tools page
$string['admintools'] = 'Admin Diagnostic Tools';
$string['admintoolsdesc'] = 'This page provides administrative tools for testing, diagnosing, and maintaining the modgen plugin. These tools help ensure database integrity and verify that security and performance improvements are working correctly.';
$string['testing'] = 'Testing';
$string['phpunittests'] = 'PHPUnit Tests';
$string['phpunittestsdesc'] = 'Run the transaction handling test suite to verify that database transactions, XSS protection, error sanitization, and cache optimization are working correctly.';
$string['runtests'] = 'Run Tests';
$string['testresults'] = 'Test Results';
$string['testspass'] = 'All tests passed successfully! ✓';
$string['testsfail'] = 'Some tests failed. See details below.';
$string['runningtests'] = 'Running PHPUnit tests... This may take a moment.';
$string['databaseintegrity'] = 'Database Integrity';
$string['integritychecker'] = 'Integrity Checker';
$string['integritycheckerdesc'] = 'Check for and optionally fix orphaned sections, invalid parent references, and other database inconsistencies.';
$string['courseid'] = 'Course ID';
$string['entercourseid'] = 'Enter course ID';
$string['findcourseid'] = 'How do I find a course ID?';
$string['selectcourse'] = 'Select a course';
$string['choosecourse'] = '-- Choose a course --';
$string['checkintegrity'] = 'Check Integrity';
$string['fixintegrity'] = 'Repair Invalid Parents';
$string['cleanup'] = 'Delete Hidden Empty Sections';
$string['integritycheck'] = 'Integrity Check Results';
$string['fixingintegrity'] = 'Fixing Integrity Issues';
$string['cleaningup'] = 'Cleaning Up Orphaned Sections';
$string['confirmfixintegrity'] = 'Are you sure you want to fix integrity issues in this course? This will set invalid parent sections to top-level (parent=0) and delete orphaned format options.';
$string['confirmcleanup'] = 'Are you sure you want to clean up orphaned sections? This will permanently delete all hidden sections that have no activities.';
$string['checkingcourse'] = 'Checking integrity for course: {$a}';
$string['cleaningcourse'] = 'Cleaning orphaned sections from course: {$a}';
$string['orphanedoptions'] = '{$a} orphaned format options (options for sections that no longer exist)';
$string['invalidparents'] = '{$a} sections with invalid parent references';
$string['fixedorphaned'] = 'Fixed {$a} orphaned format options';
$string['fixedinvalid'] = 'Fixed {$a} sections with invalid parent references';
$string['noissuesfound'] = 'No integrity issues found. Database is clean! ✓';
$string['usefixbutton'] = 'Use the "Repair Invalid Parents" button to automatically repair these problems.';
$string['nosectionstoclean'] = 'No orphaned sections found to clean up.';
$string['sectionsdeleted'] = 'Successfully deleted {$a} orphaned sections';
$string['backtomainpage'] = 'Back to Admin Tools';
$string['statistics'] = 'Statistics';
$string['pluginstatistics'] = 'Plugin Usage Statistics';
$string['flexsectionscourses'] = 'Courses using flexsections';
$string['estimatedsections'] = 'Estimated modgen-created sections';
$string['documentation'] = 'Documentation';
$string['resourcelinks'] = 'Resource Links';
$string['corruptionanalysis'] = 'Corruption Analysis Report';
$string['implementationplan'] = 'Implementation Plan';

// Hierarchy analysis strings.
$string['analyzehierarchy'] = 'Analyze Hierarchy';
$string['hierarchyanalysis'] = 'Hierarchy Analysis';
$string['hierarchytree'] = 'Section Hierarchy Tree';
$string['sectiondetails'] = 'Section Details Table';
$string['hierarchystats'] = 'Hierarchy Statistics';
$string['analyzingcourse'] = 'Analyzing hierarchy for course: {$a}';
$string['totalsections'] = 'Total sections';
$string['toplevelsections'] = 'Top-level sections';
$string['maxdepth'] = 'Maximum depth';
$string['hiddensections'] = 'Hidden sections';
$string['orphanedsections'] = 'Orphaned sections';
$string['circularreferences'] = 'Circular references';
$string['idnumberconfusion'] = 'ID/number confusion';
$string['depthviolations'] = 'Depth violations';
$string['hiddensubsections'] = 'Hidden subsections (no activities)';

// Export strings.
$string['quickexport'] = 'Quick Hierarchy Export';
$string['exporthierarchy'] = 'Export Hierarchy Data';
$string['exporthierarchydesc'] = 'Export course section hierarchy without running full analysis. Useful for sharing diagnostic data when reporting issues.';
$string['exportoptions'] = 'Download Hierarchy Data:';
$string['downloadjson'] = 'Download as JSON (Machine Readable)';
$string['downloadhtml'] = 'Download as HTML (Human Readable)';
$string['downloadtext'] = 'Download as Text (Plain Text)';
$string['hierarchyexported'] = 'Hierarchy data exported successfully';

// Repair action strings.
$string['repairactions'] = 'Repair Actions:';
$string['recheck'] = 'Run Integrity Check Again';
$string['reanalyzehierarchy'] = 'View Hierarchy Report Again';
$string['fixcircular'] = 'Break Circular Reference Loops';
$string['flattenhierarchy'] = 'Reset All to Top Level (Destructive)';
$string['confirmfixcircular'] = 'Are you sure you want to fix circular parent references? This will break cycles by setting affected sections to top-level (parent=0).';
$string['confirmflattenhierarchy'] = 'WARNING: This will set ALL sections to top-level (parent=0), destroying the entire hierarchy structure. This is a destructive operation that cannot be undone. Are you absolutely sure you want to continue?';
$string['fixingcircular'] = 'Fixing Circular References';
$string['flatteninghierarchy'] = 'Flattening Hierarchy';
$string['fixingcircularcourse'] = 'Fixing circular references in course: {$a}';
$string['flatteningcourse'] = 'Flattening hierarchy for course: {$a}';
$string['circularfixed'] = 'Fixed {$a} circular references';
$string['nocircularfound'] = 'No circular references found';
$string['circularfixerror'] = 'Error fixing circular references: {$a}';
$string['hierarchyflattened'] = 'Hierarchy successfully flattened - all sections are now top-level';
$string['flattenerror'] = 'Error flattening hierarchy: {$a}';

// Privacy API strings.
$string['privacy:metadata:jobs'] = 'Background job records for section creation operations';
$string['privacy:metadata:jobs:userid'] = 'The ID of the user who queued the creation job';
$string['privacy:metadata:jobs:courseid'] = 'The ID of the course where sections are being created';
$string['privacy:metadata:jobs:action'] = 'The type of creation action (create_themes, create_weeks, create_from_json)';
$string['privacy:metadata:jobs:parameters'] = 'JSON parameters for the job, may include user prompts or template selections';
$string['privacy:metadata:jobs:result'] = 'JSON result data from the job execution, may include AI-generated content';
$string['privacy:metadata:jobs:status'] = 'Current status of the job (queued, running, completed, failed)';
$string['privacy:metadata:jobs:timecreated'] = 'When the job was queued';
$string['privacy:metadata:jobs:timestarted'] = 'When the job started execution';
$string['privacy:metadata:jobs:timecompleted'] = 'When the job completed or failed';
$string['privacy:path:jobs'] = 'Module generation jobs';

// Scheduled task strings.
$string['cleanupoldjobs'] = 'Clean up old job records';

// File upload form strings.
$string['contentfile'] = 'Content file';
$string['selectactivitytype'] = 'Create as activity type';
$string['activityintro'] = 'Introduction';
$string['uploadandcreate'] = 'Upload and create';

// File processor warning strings.
$string['unsupportedfiletype'] = 'Unsupported file type: {$a}';
$string['conversionfailed'] = 'Could not convert file "{$a}" to HTML';
$string['fallbacktoplaintext'] = 'HTML conversion failed; content extracted as plain text';
$string['couldnotextractcontent'] = 'Could not extract content from file "{$a}"';
