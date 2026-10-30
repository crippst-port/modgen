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

// phpcs:disable moodle.Files.LangFilesOrdering -- strings are grouped by feature area, not alphabetically.
$string['pluginname'] = 'Module Assistant';

// Error strings.
$string['jsontoolarge'] = 'JSON data is too large to process';
$string['invalidjson'] = 'Invalid JSON data: {$a}';
$string['locktimeout'] = 'Another user is currently creating content in this course. Please try again in a few moments.';
$string['sectorcreationfailed'] = 'Failed to create section "{$a}". Check logs for details.';
$string['invalidparentsection'] = 'Parent section {$a} does not exist';
$string['maxsectiondepthreached'] = 'Maximum section depth of {$a} reached';
$string['themecreationfailed'] = 'Failed to create themes. Check logs for details.';
$string['jsonsectionscreationfailed'] = 'Failed to create sections from JSON: {$a}';
$string['cacherebuildfailed'] = 'Course cache rebuild failed: {$a}';
$string['jobqueued'] = 'Creation queued - {$a} sections will be created in the background. This may take a few minutes.';
$string['jobfailed'] = 'Job failed: {$a}';
$string['jobinterrupted'] = 'A previous attempt was interrupted before completing (the course may be too large to process in one run). To avoid creating duplicate sections, the job was not retried. Please review the course and, if needed, start a smaller generation.';
$string['invalidaction'] = 'Invalid action';

// Job status page strings.
$string['jobstatuspage_title'] = 'Job Status';
$string['jobstate_completed'] = 'Completed';
$string['jobstate_failed'] = 'Failed';
$string['jobstate_queued'] = 'Queued';
$string['jobstate_running'] = 'Running';
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

$string['inputrequired'] = 'You must provide at least one of the following: a text prompt, upload a CSV file, or select a structure.';
$string['templatefromprompt'] = 'Structure from prompt';

$string['prompt'] = 'Additional context or requests for the Assistant';
$string['submit'] = 'Preview Structure';

$string['generationresultssummaryheading'] = 'What will be created';
$string['generationresultspromptheading'] = 'Your prompt';
$string['generationresultsprompttoggle'] = 'Show prompt details';
$string['generationresultsjsonheading'] = 'Full module JSON';
$string['generationresultsjsondescription'] = 'Review or share the structured JSON output from the generator.';
$string['generationresultsjsonnote'] = 'Keep a copy if you may need to regenerate the same structure later.';
$string['generationresultsfallbacksummary_weekly'] = 'The plan creates {$sections} weekly sections with around {$outlineitems} suggested activities and resources.';
$string['generationresultsfallbacksummary_theme'] = 'The plan creates {$themes} themed sections spanning approximately {$weeks} delivery weeks.';
$string['aigensummary'] = 'AI Generated Summary';
$string['sectioncreated'] = 'Section created: {$a}';
$string['approveandcreate'] = 'Create';
$string['subsectioncreated'] = 'Subsection created: {$a}';
$string['moduletype'] = 'Module format';
$string['moduletype_connected_weekly'] = 'Connected Weekly';
$string['moduletype_connected_theme'] = 'Connected Themed';
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

$string['themefallback'] = 'Theme overview';
$string['weekfallback'] = 'Weekly focus';
$string['presession'] = 'Pre-session';
$string['session'] = 'Session';
$string['postsession'] = 'Post-session';
$string['returntocourse'] = 'Return to course home';
$string['launchgenerator'] = 'Structure from file';
$string['modgenmodalheading'] = 'Module Assistant';
$string['navtitle'] = 'Module Assistant';
$string['generatorbutton'] = 'Create';
$string['generatorlabel'] = 'Structure from file';
$string['modalintro'] = 'Click below to open the Module Creator and create module content.';
$string['closemodgenmodal'] = 'Close and return to module';
$string['missingcourseid'] = 'Course ID is required to use the Module Assistant.';

// Structure file upload.
$string['supportingfiles'] = 'Upload structure file';
$string['supportingfiles_help'] = 'Upload a CSV file containing your module structure. The module will be created as specified in the CSV. Maximum 5MB. Note: If you upload a file, it will be used even if you have also selected a template from the dropdown below.';

// Learning activity (metadata module).
$string['learningactivity_created'] = 'Created learning activity metadata for {$a->name} (type: {$a->type})';
$string['learningactivity_section'] = 'Section';

$string['aipolicyacceptance'] = 'AI statement Acceptance Required';
$string['acceptaipolicy'] = 'I agree to the terms of AI use in this system';
$string['aipolicyinfo'] = 'This tool does not currently include active AI functionality. It is designated as AI-enabled to allow for potential future enhancements. Please accept to continue.';
$string['timeout'] = 'AI Request Timeout (seconds)';
$string['timeout_desc'] = 'Maximum time to wait for AI responses before timing out. Default is 300 seconds (5 minutes).';
$string['airatelimit'] = 'AI Request Rate Limit (per hour)';
$string['airatelimit_desc'] = 'Maximum number of AI generation requests a user can make per hour. This helps prevent API quota exhaustion and abuse. Default is 10 requests per hour. Set to 0 for unlimited (not recommended).';
$string['cachedef_ai_requests'] = 'How many AI generation requests each user has made in the current hour, for the rate limit';
$string['processing'] = 'Processing your request, this may take several minutes...';
$string['longquery'] = 'Long queries may take up to 5 minutes to process.';
$string['prompt_help'] = 'Describe what you want to create for your module. Be specific about the topic, learning objectives, and type of activities you want. More detailed prompts will give better results but may take longer to process.';
$string['moduletype_help'] = 'Choose how to structure your module:

**Connected Weekly**: Weekly format enhanced with the Flexible Sections layout to improve organisation and usability for the new Connected Curriculum.

**Connected Themed**: Themed format enhanced with the Flexible Sections layout, organising content into distinct learning themes for improved usability with the new Connected Curriculum.

**Note**: If you upload a CSV file, the format will be automatically detected based on its contents (themes vs weekly structure). You can override the auto-detected format by selecting a specific option here.';

// Form section headers.
$string['selectoruploadtemplate'] = 'Select or Upload Structure File';

// CSV Template Library.
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
$string['addnewtemplate'] = 'Add new structure';
$string['confirmdeletetemplate'] = 'Are you sure you want to delete the structure "{$a}"? This cannot be undone.';
$string['templatedeleted'] = 'Structure deleted successfully';
$string['templatecreated'] = 'Structure created successfully';
$string['templateupdated'] = 'Structure updated successfully';
$string['notemplates'] = 'No structures available yet. Add your first structure below.';
$string['moveup'] = 'Move up';
$string['movedown'] = 'Move down';
$string['invalidcsvstructure'] = 'The uploaded CSV file does not have a valid structure: {$a}';
$string['templatefilenotfound'] = 'Structure file not found';
$string['templatename_help'] = 'Give this structure a descriptive name that users will see when selecting structures.';
$string['templatedescription_help'] = 'Optionally provide a brief description of what this structure contains or when it should be used.';

// Generator introduction.
$string['generatorintroduction'] = 'This page allows you to create your course structure in several ways. You can upload a CSV structure file to define sections, weeks, or themes, or optionally select a pre-made structure from the library. When you upload a structure file, the form will read its contents and create your course layout. You can edit titles and descriptions after the structure has been created on your module.';

// AI enable/disable setting.
$string['aienabledheading'] = 'AI Integration';
$string['aienabledheading_desc'] = 'Control whether the plugin uses AI to generate module structures or processes uploaded files directly.';
$string['enableai'] = 'Enable AI generation';
$string['enableai_desc'] = 'When enabled, uploaded files are processed exactly as specified, and you can make additional adjustments via the prompt field When disabled, only uploaded CSV files are processed with no AI adjustments available.';

// Placement options.
$string['contentplacement'] = 'Content placement';
$string['createsummaryactivities'] = 'Add a section summary activity to each section';
$string['createsummaryactivities_help'] = 'When enabled, a Learning Activity "section summary" is added to the top of each week and session to hold learning design details (duration, learning type, instructions). Leave it off for a lighter structure of empty sections you can fill in later, which also makes large structures noticeably faster to create.';
$string['createsummaryactivitiesai'] = 'Add a section summary activity to each section';
$string['createsummaryactivitiesai_help'] = 'When enabled, a Learning Activity "section summary" is added to the top of each week and session to hold learning design details (duration, learning type, instructions). Leave it off for a lighter structure of empty sections you can fill in later, which also makes large structures noticeably faster to create.

Note: if you are generating with AI, turning this off means any learning-design details the AI produced (durations, learning types, instructions) will not be added to the course — only the section structure is created.';

// AI prompt configuration.
$string['aipromptheading'] = 'AI Generation Settings';
$string['aipromptheading_desc'] = 'Configure the pedagogical guidance and institutional context sent to the AI for module generation. The JSON schema and technical requirements are managed by the system and cannot be modified here.';
$string['baseprompt'] = 'Pedagogical Guidance';
$string['baseprompt_desc'] = 'This guidance is sent to the AI to establish pedagogical context, institutional approach, and quality standards. Include information about your institution\'s teaching philosophy, any mandatory pedagogical frameworks, accessibility requirements, or specific learning design principles. The system automatically appends the technical JSON schema requirements to this guidance.';

// Validation error strings.
$string['generationfailed'] = 'Generation Failed';
$string['validationerrorhelp'] = 'The AI response was malformed and cannot be used to create content. This sometimes happens when the AI double-encodes the response or returns an incorrect structure. Please try generating again with the same or modified prompt.';
$string['tryagain'] = 'Try Again';

// Module preview display strings.
$string['creatingstructurewait'] = 'Creating the structure... please wait.';
$string['previewnoactivities'] = 'No activities';
$string['previewnoactivitiesweek'] = 'No activities in this week';
$string['previewnostructure'] = 'No module structure was found in the response. It may be incomplete.';
$string['themes'] = 'Themes';
$string['weeks'] = 'Weeks';
$string['activities'] = 'Activities';
$string['regenerate'] = 'Regenerate';
$string['modulestructureinfo'] = 'This preview shows the structure and organisation of the module that will be created. It is a schematic representation and does not reflect how the content will appear in Moodle. Click "Create" below to proceed with creating the module in your course.';

// Quick add forms.
$string['title'] = 'Title';
$string['summary'] = 'Summary';
$string['newtheme'] = 'New Theme(s)';
$string['newweek'] = 'New Week(s)';
$string['quickadd'] = 'Quick Add';
$string['promptintro'] = 'Describe the module you want and the Assistant will suggest a structure. Nothing is added to your course until you have reviewed it.';
$string['promptplaceholder'] = 'For example: a 12-week introduction to marine biology for first-year undergraduates, with a field trip in week 6 and an assessed presentation at the end.';
$string['moduletypechoice_connected_weekly'] = 'A week-by-week module: each week has pre-session, session and post-session sections.';
$string['moduletypechoice_connected_theme'] = 'Themes, each holding its own weeks of pre-session, session and post-session sections.';
$string['quickaddthemeintro'] = 'Add themes to the top of your module. Each theme gets the weeks you choose, and each week gets pre-session, session and post-session sections.';
$string['quickaddweekintro'] = 'Add weeks to your module, or inside the section you are viewing. Each week gets pre-session, session and post-session sections.';
$string['quickaddsummarythemes'] = 'This will add {$a->themes} theme(s) with {$a->weeks} week(s) between them: {$a->sections} sections in all.';
$string['quickaddsummaryweeks'] = 'This will add {$a->weeks} week(s): {$a->sections} sections in all.';
$string['stepperdecrease'] = 'Decrease {$a}';
$string['stepperincrease'] = 'Increase {$a}';
$string['themecount'] = 'How many themes do you want to create?';
$string['themecount_help'] = 'Enter the number of themes to create. The maximum is set by your site administrator.';
$string['weekcount'] = 'How many weeks do you want to create?';
$string['weekcount_help'] = 'Enter the number of weeks to create. The maximum is set by your site administrator.';
$string['weeksperTheme'] = 'How many weeks per theme?';
$string['weeksperTheme_help'] = 'Enter the number of weeks to create within each theme. The maximum is set by your site administrator.';
$string['invalidthemecount'] = 'Please enter a number between 1 and {$a}';
$string['invalidweekcount'] = 'Please enter a number between 1 and {$a}';
$string['invalidweeksperTheme'] = 'Please enter a number between 1 and {$a}';
$string['defaultthemename'] = 'Theme {$a}';
$string['defaultthemesummary'] = 'This is a new empty theme. Replace this text with a description of the content of this theme.';
$string['defaultweekname'] = 'Theme {$a->theme} - Week {$a->week}';
$string['defaultstandaloneweekname'] = 'Week {$a}';
$string['defaultweeksummary'] = 'This is a new empty Week. Replace this text with a description of the content of this week.';
$string['seedetails'] = 'See details';
$string['placementdisabled'] = 'The Module Assistant is currently disabled. An administrator can enable it in Site administration > General > AI > AI placements.';
$string['erroracquiringlock'] = 'Could not acquire course lock. Another user may be editing this course.';
$string['introductionsectionname'] = 'Introduction & General Information';
$string['assessmentssectionname'] = 'Assessments';
$string['newassessments'] = 'Assessments section';
$string['addassessmentsintro'] = 'This adds a section called "Assessments" at the top of the course, straight after section 0.';
$string['addassessmentsbutton'] = 'Add section';
$string['assessmentsadded'] = 'The "{$a}" section has been added.';
$string['assessmentstoplevelonly'] = 'The Assessments section can only be added from the top level of the module.';
$string['assessmentsexists'] = 'This course already has a section called "{$a}".';
$string['existingcontentwarning'] = 'This module already has some content. A new structure is added alongside what is already here, not in place of it, so we advise deleting the existing content before you continue.';

// Error messages for exceptions.
$string['erroroccurred'] = 'Something went wrong. Please try again.';
$string['errorcreatinglearningactivity'] = 'The section summary activity could not be created.';
$string['errorunexpected'] = 'Something unexpected happened. Please go back to the course and try again.';
$string['invalidparameters'] = 'The request is missing the job or course it is about.';
$string['sectionnotfound'] = 'Section not found in course';
$string['errorformatnotflexsections'] = 'The Module Assistant only works in courses that use the Flexible sections format.';
$string['errorflexsectionsmissingmethod'] = 'Required flexsections method is not available';
$string['invalidsectionparent'] = 'Invalid section parent number. Must be a non-negative integer.';
$string['invalidsectionname'] = 'Section name cannot be empty';

// Navigation and UI messages.
$string['creationinprogress'] = 'Creation in progress. Leaving this page will abandon your changes.';
$string['generatingcontent'] = 'Generating content... this may take a minute.';
$string['genericerror'] = 'An error occurred';

// Accessibility.
$string['successicon'] = 'Success';
$string['showdetailsaria'] = 'Show creation details';

$string['sectiontype'] = 'Type';
$string['themesection'] = 'Theme';
$string['parentsection'] = 'Parent';
$string['weeksection'] = 'Week';

// Help and Advice Links.
$string['helpandadvice'] = 'Help and Advice';
$string['helplinksheading'] = 'Help and Advice Links';
$string['helplinksheading_desc'] = 'Configure up to 5 external help links that will appear in the Module Assistant toolbar dropdown menu. Links will only appear if both text and URL are provided.';
$string['helplinktext'] = 'Help Link {$a} Text';
$string['helplinktext_desc'] = 'Display text for the help link';
$string['helplinkurl'] = 'Help Link {$a} URL';
$string['helplinkurl_desc'] = 'Full URL for the help resource (must start with http:// or https://)';

// Section Creation Limits.
$string['sectionlimitsheading'] = 'Section Creation Limits';
$string['sectionlimitsheading_desc'] = 'Configure maximum number of sections that can be created at once through quick-add forms and CSV file uploads. These limits help prevent accidental creation of excessive course structure.';
$string['featuretoggleheading'] = 'Feature availability';
$string['featuretoggleheading_desc'] = 'Turn individual Module Assistant features on or off for the whole site. Capabilities still apply on top of these: a feature switched off here is unavailable to everyone, regardless of role.';
$string['enablequickadd'] = 'Enable Quick add';
$string['enablequickadd_desc'] = 'Show the "New theme" and "New week" options in the Quick add menu, which create single sections directly without going through the generator. Turning this off removes both options and closes the forms behind them. The rest of the Quick add menu is unaffected.';
$string['enablestructurefromfile'] = 'Enable Structure from file';
$string['enablestructurefromfile_desc'] = 'Allow module structures to be built from a CSV file - the "Structure from file" option, the CSV template chooser and the file upload on the generator form. Turning this off leaves AI generation from a prompt available, which is controlled separately by the AI generation setting above.';
$string['quickadddisabled'] = 'Quick add is currently disabled. An administrator can enable it in the Module Assistant plugin settings.';
$string['aidisabled'] = 'AI generation is currently disabled. An administrator can enable it in the Module Assistant plugin settings.';
$string['generationdisabled'] = 'Building a structure is not available to you here: the routes you have permission for are disabled. An administrator can enable them in the Module Assistant plugin settings.';
$string['structurefromfiledisabled'] = 'Building a structure from a file is currently disabled. An administrator can enable it in the Module Assistant plugin settings.';
$string['maxquicksections'] = 'Maximum themes or weeks';
$string['maxquicksections_desc'] = 'Maximum number of themes OR weeks that can be created at once using the quick-add forms. This applies when creating standalone themes or standalone weeks. Default: 30';
$string['maxweeksperTheme'] = 'Maximum weeks per theme';
$string['maxweeksperTheme_desc'] = 'Maximum number of weeks that can be created within EACH theme when using the themed structure. For example, creating 10 themes with 5 weeks each would create 50 total sections (10 parent themes + 40 child weeks). Set this lower than the maximum themes/weeks to control total section creation. Default: 5';
$string['maxcsvsections'] = 'Maximum CSV sections';
$string['maxcsvsections_desc'] = 'Maximum total number of sections (themes + weeks) that can be created from a CSV file upload. Set to 0 for unlimited (not recommended). Default: 50';
$string['csvlimitexceeded'] = 'CSV file contains {$a->count} sections, which exceeds the maximum allowed limit of {$a->max}. Please reduce the number of sections in your CSV file.';
$string['csvweekbeforetheme'] = 'Row {$a->row} of the CSV file adds the week "{$a->value}" before any theme. Add a "Theme:" row above it, or use the weekly format.';
$string['maxtotalsections'] = 'Maximum total sections per course';
$string['maxtotalsections_desc'] = 'Hard safety limit on the total number of sections a course may contain (existing sections plus those a generation would add). The flexsections format renumbers and rebuilds the whole course on every section insert, so creation slows down quadratically as a course grows and very large courses can exhaust memory. Generation is refused before it starts if it would push the course past this limit. Raise it only if your server has ample memory and you accept slower generation. Default: 400';
$string['sectionlimitexceeded'] = 'That\'s too many sections to create at once. This would take the course to {$a->total} sections, but the limit is {$a->max} (it already has {$a->existing}). Please try again with fewer themes or weeks.';

// Loading indicator strings.
$string['processingrequest'] = 'Processing your request...';
$string['loadingform'] = 'Loading form...';

$string['modgen:managestructure'] = 'Manage course structure (themes, weeks)';
$string['modgen:generatewithprompt'] = 'Generate content with AI prompt';
$string['modgen:generatefromtemplate'] = 'Generate content from templates';
$string['modgen:managetemplates'] = 'Manage site-wide templates';

// Admin tools page.
$string['courseidlabel'] = 'Course ID: {$a}';
$string['admintools'] = 'Admin Diagnostic Tools';
$string['admintoolsdesc'] = 'This page provides administrative tools for testing, diagnosing, and maintaining the modgen plugin. These tools help ensure database integrity and verify that security and performance improvements are working correctly.';
$string['testing'] = 'Testing';
$string['databaseintegrity'] = 'Database Integrity';
$string['integritychecker'] = 'Integrity Checker';
$string['integritycheckerdesc'] = 'Check for and optionally fix orphaned sections, invalid parent references, and other database inconsistencies.';
$string['courseid'] = 'Course ID';
$string['entercourseid'] = 'Enter course ID';
$string['findcourseid'] = 'How do I find a course ID?';
$string['selectcourse'] = 'Select a course';
$string['checkintegrity'] = 'Check Integrity';
$string['fixintegrity'] = 'Repair Invalid Parents';
$string['cleanup'] = 'Delete Hidden Empty Sections';
$string['integritycheck'] = 'Integrity Check Results';
$string['fixingintegrity'] = 'Fixing Integrity Issues';
$string['cleaningup'] = 'Cleaning Up Orphaned Sections';
$string['confirmfixintegrity'] = 'Fix integrity issues in this course? This sets invalid parent sections to top-level and deletes orphaned format options.';
$string['confirmcleanup'] = 'Clean up orphaned sections? This permanently deletes hidden sections with no activities.';
$string['noissuesfound'] = 'No integrity issues found.';
$string['nosectionstoclean'] = 'No orphaned sections found to clean up.';
$string['sectionsdeleted'] = '{$a} orphaned section(s) deleted.';
$string['backtomainpage'] = 'Back to Admin Tools';
$string['statistics'] = 'Statistics';
$string['pluginstatistics'] = 'Plugin Usage Statistics';
$string['flexsectionscourses'] = 'Courses using flexsections';
$string['estimatedsections'] = 'Estimated modgen-created sections';

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

// Check Structure page (course-editor diagnostic tool).
$string['checkstructure_notflexsections'] = 'Check Structure works on courses in the Flexible sections format. This course uses a different format, so there is nothing to check.';
$string['checkstructure'] = 'Check Structure';
$string['modgen:checkstructure'] = 'View and fix course structure diagnostics';
$string['checkstructuredesc'] = 'Checks how this course\'s sections are nested and linked, and can fix common problems automatically. Some, like circular references, can stop the course from loading; others, like orphaned sections, can silently block actions like deleting or duplicating the course.';
$string['checkstructurepage'] = 'Course Structure Diagnostics';
$string['checkstructurerun'] = 'Run Check';
$string['checkstructureresults'] = 'Diagnostic Results';
$string['checkstructure_noissues'] = 'No structural issues detected.';
$string['checkstructure_nosectionissues'] = 'No sections have issues. Tick "Show all sections" above to see every section.';
$string['checkstructure_issuesfound'] = '{$a} issue type(s) detected. Review the details below before applying any fixes.';
$string['checkstructure_backtocourse'] = 'Back to Course';
$string['checkstructure_circularwarning'] = 'Circular reference(s) found: some sections\' parent links loop back on themselves (e.g. A is B\'s parent, and B is A\'s parent). Moodle cannot resolve this. Reparent the affected sections manually below, or use the automatic fix button.';
$string['checkstructure_orphanwarning'] = 'Orphaned section(s) found: hidden, empty sections disconnected from the course structure. They don\'t appear on the course page but can still block actions like deleting or duplicating the course. Removing them clears the blockage.';
$string['checkstructure_orderwarning'] = 'Section numbering is out of order: the stored section numbers no longer match the order the sections appear in the hierarchy. This is not only cosmetic. Deleting a section while the course is in this state can remove the wrong content — either taking unrelated sections with it, or leaving a deleted section\'s subsections behind, attached to whatever later occupies that number. Moodle reports neither case as an error. The usual cause is reparenting sections with the dropdowns above, which does not renumber them. Use "Renumber Sections" below before deleting or reorganising anything further on this course.';
$string['checkstructure_wipewarning'] = 'Every section is missing its parent record — a sign this course was switched away from Flexsections and back. Moodle deletes Flexsections\' section data on switch-away and does not restore it, so the original nesting is genuinely gone, not just unset. "Repair Structure Data" below will set every section to top-level, the only safe automatic fix; to recover the original hierarchy, restore a course backup taken before the format was changed.';

// Fix action labels and descriptions.
$string['fixintegrity_label'] = 'Repair Structure Data';
$string['fixintegrity_label_help'] = 'Removes a wrongly-set parent on section 0, deletes leftover format options for sections that no longer exist, and resets invalid, empty, or missing parent references to top-level.

If every section is missing its parent, the course was likely switched away from Flexsections and back. The original nesting can\'t be recovered here — restore from a backup if you need it.';
$string['fixintegrity_desc'] = 'Resets invalid, null, or missing parent references to top-level and removes orphaned format options.';
$string['fixintegrity_confirm'] = 'Repair structure data for this course? Resets invalid parent references to top-level and deletes orphaned format options. This cannot be undone.';
$string['fixintegrity_done'] = 'Structure data repaired. {$a} item(s) fixed.';
$string['fixintegrity_none'] = 'No structural data issues found to repair.';
$string['fixdetails'] = 'Details: {$a}';

$string['fixcleanup_label'] = 'Remove Orphaned Sections';
$string['fixcleanup_desc'] = 'Permanently deletes hidden, empty sections no longer connected to the course structure.';
$string['fixcleanup_confirm'] = 'Remove orphaned sections from this course? This permanently deletes hidden sections that have no activities and no summary. This cannot be undone.';
$string['fixcleanup_done'] = '{$a} orphaned section(s) removed.';
$string['fixcleanup_none'] = 'No orphaned sections found.';

$string['fixcircular_label'] = 'Attempt Circular Reference Auto Fix';
$string['fixcircular_label_help'] = 'This button fixes the affected section(s) by moving them out to the main course page (the top level), so the course works again.

It can\'t always know exactly where a section was originally meant to go, so afterwards it makes a best guess at a new home for each moved section, and shows you that suggestion so you can review it. It guesses by looking at the section that comes right before the moved one in the course and checking that section is itself in good order: if so, that\'s the suggestion; if not, it keeps looking further back until it finds one that is. This is only ever a guess based on position, not a certainty, so always check it before accepting it.';
$string['fixcircular_desc'] = 'Breaks parent-reference loops by resetting all affected sections to top-level. You\'ll likely need to move them afterwards.';
$string['fixcircular_confirm'] = 'Fix circular parent references in this course? Left unresolved, these loops can prevent the course from loading correctly. Sections in a loop will be reset to top-level (parent=0), appearing directly under the course homepage — you\'ll need to move or delete them afterwards as required. This cannot be undone.';
$string['fixcircular_done'] = '{$a} circular reference(s) fixed.';
$string['fixcircular_none'] = 'No circular references found.';
$string['fixsectionorder_label'] = 'Renumber Sections';
$string['fixsectionorder_label_help'] = 'Flexsections numbers sections by their position in the hierarchy: section 0 first, then each section followed immediately by its own subsections. Moving a section in the course normally keeps that numbering in step, but reparenting a section with the dropdowns on this page only changes who its parent is — the numbers stay where they were.

This matters because deleting a section relies on that numbering. Moodle deletes the chosen section and everything numbered after it, on the assumption that what follows a section is its own subsections. When the numbering is out of order that assumption is wrong, and the delete either removes unrelated sections or leaves the chosen section\'s subsections behind. Neither is reported as an error.

This button renumbers every section back into hierarchy order. Nothing moves and no section changes parent: only the numbers change, along with the parent references that point at them.

Section numbers appear in section links, so any bookmark or link that names a section number may point somewhere different afterwards.';
$string['fixsectionorder_desc'] = 'Renumbers sections so each one is followed by its own subsections, matching the hierarchy. No section changes parent or position. Run this before deleting sections on this course.';
$string['fixsectionorder_confirm'] = 'Renumber this course\'s sections into hierarchy order? Sections keep their parents, their contents and their position on the course page — only the section numbers change. Doing this makes section deletion behave correctly again. Links that reference a section by number may point elsewhere afterwards. This cannot be undone.';
$string['fixsectionorder_done'] = '{$a} section(s) renumbered.';
$string['fixsectionorder_none'] = 'Section numbering is already in hierarchy order.';
$string['fixsectionorder_blocked_brokenhierarchy'] = 'Sections were not renumbered: the course hierarchy has to be sound before there is a correct order to renumber into. Fix the parent reference problems reported above first, then run this again.';
$string['fixsectionorder_blocked_notflexsections'] = 'Sections were not renumbered: this course does not use the Flexsections format, so hierarchy-based section numbering does not apply.';

// Events.
$string['event_sectionrenumberfailed'] = 'Section renumber after generation failed';

// Diagnostic issue labels for results table.
$string['diag_section0_with_parent'] = 'Section 0 with parent';
$string['diag_orphaned_options'] = 'Orphaned format options';
$string['diag_invalid_parents'] = 'Invalid parent references';
$string['diag_null_parents'] = 'Null/empty parent values';
$string['diag_missing_parents'] = 'Missing parent records';
$string['diag_duplicate_sections'] = 'Duplicate section numbers';
$string['diag_circular_refs'] = 'Circular references';
$string['diag_orphaned_sections'] = 'Orphaned empty sections';
$string['diag_section_order'] = 'Section numbering out of order';

// Check Structure page table column headers and markers.
$string['checkstructure_col_check'] = 'Check';
$string['checkstructure_col_issuesfound'] = 'Issues found';
$string['checkstructure_col_secnum'] = 'Sec #';
$string['checkstructure_col_depth'] = 'Depth';
$string['checkstructure_col_dbid'] = 'DB ID';
$string['checkstructure_col_issues'] = 'Issues';
$string['checkstructure_ok'] = 'None ✓';
$string['checkstructure_unnamed'] = '(unnamed)';
$string['checkstructure_missing'] = 'missing';
$string['checkstructure_showall'] = 'Show all sections (including clean ones)';

// Integrity checker row-issue and fix-detail messages.
$string['row_section0hasparent'] = 'Section 0 should not have a parent';
$string['row_invalidparent'] = 'Invalid parent: {$a}';
$string['row_nullparent'] = 'Null/empty parent value';
$string['row_missingparent'] = 'Missing parent option';
$string['row_circularref'] = 'Circular reference';
$string['row_wrongorder'] = 'Out of order (expected number: {$a})';
$string['row_orphaned'] = 'Orphaned (hidden, no activities)';
$string['detail_removedsection0parent'] = 'Removed parent from section 0';
$string['detail_removedorphanedoptions'] = 'Removed {$a} orphaned format options';
$string['detail_resetinvalidparents'] = 'Reset {$a} invalid parent references to top-level';
$string['detail_fixednullparents'] = 'Fixed {$a} null/empty parent values';
$string['detail_insertedmissingparents'] = 'Inserted {$a} missing parent options';
$string['detail_brokecircular'] = 'Broke circular ref at section "{$a->name}" (sec {$a->section})';

// Sections reparented to top-level by the circular-reference / invalid-parent fixes.
$string['reparented_heading'] = 'Sections moved to top level';
$string['reparented_desc'] = 'These sections had a broken parent reference and were reset to top-level so the structure could be repaired. Where possible, a likely original parent is suggested below — check it before applying, it\'s a guess, not a certainty.';
$string['reparented_col_suggested'] = 'Suggested parent';
$string['reparented_apply_suggestion'] = 'Apply';
$string['reparented_fixall'] = 'Fix All';
$string['reparented_no_suggestion'] = 'No confident suggestion, pick manually below';
$string['reparented_col_action'] = 'Action';
$string['reparented_jumplink'] = 'Jump to section';
$string['setparentall_done'] = '{$a} section(s) reparented to their suggested parent.';
$string['setparentall_done_withfailures'] = '{$a->applied} section(s) reparented to their suggested parent; {$a->failed} could not be applied.';

// Per-section parent picker.
$string['checkstructure_toplevel'] = '(Top level)';
$string['setparent_current'] = 'Current: {$a}';
$string['setparent_apply'] = 'Set';
$string['setparent_done'] = 'Section {$a} reparented.';
$string['setparent_error_section0'] = 'Section 0 cannot have a parent.';
$string['setparent_error_selfparent'] = 'A section cannot be its own parent.';
$string['setparent_error_sectionnotfound'] = 'That section could not be found.';
$string['setparent_error_parentnotfound'] = 'The chosen parent section could not be found.';
$string['setparent_error_wouldcreatecycle'] = 'That change would create a new circular reference, so it was not applied.';
$string['setparent_error_maxdepthexceeded'] = 'That change would nest the section deeper than this course format allows, so it was not applied.';

// Settings page external link.
$string['admintools_link'] = 'Admin Diagnostic Tools';
$string['admintools_link_desc'] = 'Open the admin-level diagnostic and repair tools for the Module Generator plugin. <a href="{$a}">Admin Diagnostic Tools</a>';

// Export strings.
$string['quickexport'] = 'Quick Hierarchy Export';
$string['exporthierarchy'] = 'Export Hierarchy Data';
$string['exporthierarchydesc'] = 'Export course section hierarchy without running full analysis. Useful for sharing diagnostic data when reporting issues.';
$string['exportoptions'] = 'Download Hierarchy Data:';
$string['downloadjson'] = 'Download as JSON (Machine Readable)';
$string['downloadhtml'] = 'Download as HTML (Human Readable)';
$string['downloadtext'] = 'Download as Text (Plain Text)';

// Repair action strings.
$string['repairactions'] = 'Repair Actions:';
$string['recheck'] = 'Run Integrity Check Again';
$string['reanalyzehierarchy'] = 'View Hierarchy Report Again';
$string['fixcircular'] = 'Break Circular Reference Loops';
$string['flattenhierarchy'] = 'Reset All to Top Level (Destructive)';
$string['confirmfixcircular'] = 'Fix circular parent references? This breaks cycles by setting affected sections to top-level.';
$string['confirmflattenhierarchy'] = 'WARNING: This will set ALL sections to top-level (parent=0), destroying the entire hierarchy structure. This is a destructive operation that cannot be undone. Are you absolutely sure you want to continue?';
$string['fixingcircular'] = 'Fixing Circular References';
$string['flatteninghierarchy'] = 'Flattening Hierarchy';
$string['flatteningcourse'] = 'Flattening hierarchy for course: {$a}';
$string['circularfixed'] = 'Fixed {$a} circular references';
$string['nocircularfound'] = 'No circular references found';
$string['circularfixerror'] = 'Error fixing circular references: {$a}';
$string['hierarchyflattened'] = 'Hierarchy successfully flattened - all sections are now top-level';
$string['flattenerror'] = 'Error flattening hierarchy: {$a}';

// Privacy API strings.
$string['privacy:metadata:core_ai'] = 'Prompts entered in the Module Assistant are sent to the AI subsystem, which records each request and the text generated for it.';
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

// Section numbering safety.
$string['parentsectiongone'] = 'The section these were to be added inside no longer exists, so they have been created at the top level instead.';

// AI service errors.
$string['aierror'] = 'AI error: {$a}';
$string['aierror_empty'] = 'The AI service returned an empty response. The service may be unavailable or not configured.';
$string['aierror_actionunavailable'] = 'Text generation is not available. An administrator needs to enable an AI provider that offers it, and allow it for the Module Assistant placement.';
$string['aierror_nosubsystem'] = 'AI subsystem not available';
$string['aierror_notext'] = 'The AI service did not return any generated text. Response keys: {$a}';
$string['aierror_policy'] = 'User has not accepted the AI User Policy.';
$string['aierror_prompt'] = 'Prompt construction failed';
$string['aierrorheading'] = 'AI error';
$string['aigeneratedvia'] = 'Generated via AI subsystem';
$string['aigeneratedvia_nonjson'] = 'Generated via AI subsystem (non-JSON response)';
$string['airatelimitexceeded'] = 'Rate limit exceeded. You have made too many AI requests in the past hour. Please try again later.';
$string['aivalidation_itemnotarray'] = 'Invalid structure: theme/section is not an array';
$string['aivalidation_missingsections'] = 'Response missing "sections" array';
$string['aivalidation_missingthemes'] = 'Response missing "themes" array';
$string['aivalidation_missingtitle'] = 'Theme/section missing title';
$string['aivalidation_missingweeks'] = 'Theme missing "weeks" array';
$string['aivalidation_noitems'] = 'Response contains no themes/sections';
$string['aivalidation_weeknotarray'] = 'Week structure is not an array';
$string['aivalidation_weeksnotarray'] = 'Theme "weeks" is not an array';
$string['templateprocessorempty'] = 'The template processor did not return a usable structure.';
$string['themetoplevelonly'] = 'New themes can only be added from the top level of the module.';
$string['modulestructureheading'] = 'Module Structure';
$string['previewkey'] = 'Key:';
$string['togglenav'] = 'Toggle navigation';

// Creation warnings and job results.
$string['warning_themedisappeared'] = 'The theme "{$a}" was removed while its weeks were being added, so the remaining weeks were skipped.';
$string['warning_themefailed'] = 'The theme "{$a->title}" could not be created: {$a->error}';
$string['warning_weekfailed'] = 'The week "{$a->title}" could not be created: {$a->error}';
$string['jobcompletedwarnings'] = 'Completed with problems';
$string['jobcompletedwarnings_desc'] = 'Some items could not be created. The rest of the structure was built.';
$string['jobwarningsnotice'] = 'The background job finished, but {$a} item(s) could not be created. Check the course structure.';
$string['failorphanedjobs'] = 'Fail interrupted background jobs';

// Job page, notifications and generator steps (text previously hardcoded in JavaScript).
$string['jobstatus_redirect_note'] = 'This page will automatically redirect back to the course when creation is complete.';
$string['jobcompletednotice'] = 'Background job completed successfully';
$string['stepenterprompt'] = 'Enter prompt';
$string['stepgenerating'] = 'Generating';
$string['stepreview'] = 'Review';
$string['stepcreating'] = 'Creating';

// Errors raised while reading a structure file or preparing the course.
$string['csvempty'] = 'The CSV file is empty.';
$string['csvfilenotfound'] = 'The CSV file could not be found in file storage.';
$string['csvnodata'] = 'No data was found in the CSV file.';
$string['csvnodraft'] = 'No CSV file was found in the upload area.';
$string['templateloaderror'] = 'The template could not be loaded: {$a}';

// Event names.
$string['eventstructure_job_completed'] = 'Structure job completed';
$string['eventstructure_job_failed'] = 'Structure job failed';
$string['eventstructure_job_queued'] = 'Structure job queued';
$string['eventstructure_repaired'] = 'Course structure repaired';
$string['eventtemplate_created'] = 'Structure template added';
$string['eventtemplate_deleted'] = 'Structure template deleted';
$string['eventtemplate_moved'] = 'Structure template moved';
$string['eventtemplate_updated'] = 'Structure template updated';
$string['sessionactivityname'] = '{$a} activity';
$string['numberhint'] = 'Enter a number between {$a->min} and {$a->max}';
$string['templatefile'] = 'CSV structure file';
$string['newparentfor'] = 'New parent for section {$a}';
