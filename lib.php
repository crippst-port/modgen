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
 * Plugin callbacks and navigation hooks.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extends course navigation to load Module Assistant toolbar.
 *
 * Note: This hook loads the toolbar AMD module when in edit mode.
 * The toolbar is rendered via Fragment API for proper form initialization.
 *
 * @param navigation_node $navigation Navigation node to extend
 * @param stdClass $course Course object
 * @param context_course $context Course context
 * @return void
 */
function aiplacement_modgen_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    global $PAGE;

    // Master switch: Site admin > AI > AI placements. Nothing of this plugin's UI is
    // injected while it is off.
    if (!\aiplacement_modgen\local\settings_helper::is_placement_enabled()) {
        return;
    }

    // Check if user has any modgen capabilities.
    $canmanagestructure = has_capability('aiplacement/modgen:managestructure', $context);
    $cangenerateprompt = has_capability('aiplacement/modgen:generatewithprompt', $context);
    $cangeneratetemplate = has_capability('aiplacement/modgen:generatefromtemplate', $context);
    $cancheckstructure = has_capability('aiplacement/modgen:checkstructure', $context);

    // Hide toolbar if user has no modgen capabilities.
    if (
        !$canmanagestructure && !$cangenerateprompt
            && !$cangeneratetemplate && !$cancheckstructure
    ) {
        return;
    }

    // Navigation bar - only show in edit mode and on the course page.
    if ($PAGE->user_is_editing() && $PAGE->pagetype === 'course-view-flexsections') {
        // Feature-specific visibility (combine capability + admin setting).
        $aienabled = \aiplacement_modgen\local\settings_helper::is_ai_enabled();
        $showgenerator = $cangenerateprompt && $aienabled;

        // Load CSS.
        $PAGE->requires->css('/ai/placement/modgen/styles.css');

        // Load strings needed by JS modules (must be in main page context, not fragment).
        $PAGE->requires->strings_for_js([
            'approveandcreate',
            'aigenerated',
            'aigenmarker_tooltip',
        ], 'aiplacement_modgen');

        // Get current section from URL (for context-aware creation).
        $currentsection = optional_param('section', 0, PARAM_INT);

        // Initialize toolbar via AMD module using Fragment API.
        $PAGE->requires->js_call_amd('aiplacement_modgen/course_toolbar', 'init', [[
            'courseid' => $course->id,
            'contextid' => $context->id,
            'showgenerator' => $showgenerator,
            'showmanagestructure' => $canmanagestructure,
            'showquickadd' => \aiplacement_modgen\local\settings_helper::is_quickadd_enabled(),
            'showtemplatefromfile' => $cangeneratetemplate
                && \aiplacement_modgen\local\settings_helper::is_structurefromfile_enabled(),
            'showtemplatefromptompt' => $cangenerateprompt && $aienabled,
            'showcheckstructure' => $cancheckstructure,
            'currentsection' => $currentsection,
        ]]);

        // Initialize AI-generated activity markers (only if AI is enabled).
        if ($showgenerator) {
            $PAGE->requires->js_call_amd('aiplacement_modgen/aigen_marker', 'init', [$course->id]);
        }
    }

    // Navigation menu link removed - using toolbar only.
}

/**
 * Fragment callback to render the course toolbar.
 *
 * Renders the Module Assistant toolbar buttons based on plugin configuration
 * and user permissions.
 *
 * @param array $args Fragment arguments containing:
 *                    - courseid: Course ID (required)
 *                    - showgenerator: Whether to show generator button
 *                    - currentsection: Section number being viewed (0 at the top of the course)
 * @return string Rendered HTML
 */
function aiplacement_modgen_output_fragment_course_toolbar(array $args): string {
    global $PAGE;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();

    // Validate and clean parameters.
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $contextid = clean_param($args['contextid'] ?? 0, PARAM_INT);

    // Verify course exists and get context.
    $course = get_course($courseid);
    $context = aiplacement_modgen_fragment_context($courseid, $contextid);

    // This is a Fragment API callback, reachable directly over AJAX by any logged-in user -
    // the client-supplied show* args are never trusted for visibility. Each button's
    // capability is re-checked here, mirroring aiplacement_modgen_extend_navigation_course().
    $canmanagestructure = has_capability('aiplacement/modgen:managestructure', $context);
    $cangenerateprompt = has_capability('aiplacement/modgen:generatewithprompt', $context);
    $cangeneratetemplate = has_capability('aiplacement/modgen:generatefromtemplate', $context);
    $cancheckstructure = has_capability('aiplacement/modgen:checkstructure', $context);

    if (
        !$canmanagestructure && !$cangenerateprompt
            && !$cangeneratetemplate && !$cancheckstructure
    ) {
        throw new required_capability_exception(
            $context,
            'aiplacement/modgen:managestructure',
            'nopermissions',
            ''
        );
    }

    $aienabled = \aiplacement_modgen\local\settings_helper::is_ai_enabled();
    $showtemplatefromptompt = $cangenerateprompt && $aienabled;

    $showgenerator = $showtemplatefromptompt; // Legacy support.
    $showmanagestructure = $canmanagestructure;
    $showtemplatefromfile = $cangeneratetemplate && \aiplacement_modgen\local\settings_helper::is_structurefromfile_enabled();
    $showquickadd = \aiplacement_modgen\local\settings_helper::is_quickadd_enabled();
    $showcheckstructure = $cancheckstructure;

    // Themes are top-level containers, so New Theme is only offered from the top of the course.
    $showaddtheme = clean_param($args['currentsection'] ?? 0, PARAM_INT) <= 0;

    // Create the toolbar renderable with all capability flags.
    $toolbar = new \aiplacement_modgen\output\course_toolbar(
        $courseid,
        $showgenerator,
        $showmanagestructure,
        $showtemplatefromfile,
        $showtemplatefromptompt,
        $showcheckstructure,
        $showquickadd,
        $showaddtheme
    );

    // Get the plugin renderer and render the toolbar.
    $renderer = $PAGE->get_renderer('aiplacement_modgen');
    return $renderer->render($toolbar);
}

/**
 * Fragment callback to render the generator form in a modal.
 *
 * This properly handles filemanager initialization and other form JavaScript.
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_generator_form(array $args): string {
    global $PAGE, $USER, $CFG;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();

    // Ensure required libraries are loaded.
    require_once($CFG->libdir . '/formslib.php');
    require_once($CFG->libdir . '/filelib.php');

    // Validate parameters.
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $context = context_course::instance($courseid);

    // Verify permission - need either generation method.
    $hasprompt = has_capability('aiplacement/modgen:generatewithprompt', $context);
    $hastemplate = has_capability('aiplacement/modgen:generatefromtemplate', $context);
    if (!$hasprompt && !$hastemplate) {
        throw new required_capability_exception(
            $context,
            'aiplacement/modgen:generatefromtemplate',
            'nopermissions',
            'error'
        );
    }

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Check AI policy acceptance.
    $manager = \core\di::get(\core_ai\manager::class);
    if (!$manager->get_user_policy_status($USER->id)) {
        return $PAGE->get_renderer('aiplacement_modgen')->render_from_template(
            'aiplacement_modgen/ai_policy',
            [
                'courseid' => $courseid,
                'policytext' => get_string('aipolicyinfo', 'aiplacement_modgen'),
            ]
        );
    }

    // Create form with embedded flag.
    require_once(__DIR__ . '/classes/form/generator_form.php');

    // Create a draft file area for supporting files.
    $draftitemid = file_get_unused_draft_itemid();

    $formdata = [
        'courseid' => $courseid,
        'embedded' => 1,
        'contextid' => $context->id,
        'supportingfiles' => $draftitemid,
    ];
    $form = new \aiplacement_modgen_generator_form(null, $formdata);

    // Set the draft area data.
    $formdefaults = new stdClass();
    $formdefaults->supportingfiles = $draftitemid;
    $form->set_data($formdefaults);

    // Return rendered form.
    // Fragment API automatically handles JavaScript initialization.
    return $form->render();
}

/**
 * Resolve the context a fragment callback should authorise against.
 *
 * Fragment arguments are client-supplied. A caller-chosen context id must not be allowed to
 * stand in for the course the fragment then reads from, otherwise a capability held in one
 * course would unlock another course's data. The context must be the course's own.
 *
 * @param int $courseid Course the fragment reads from
 * @param int $contextid Optional context id supplied by the caller (0 for none)
 * @return context_course The course context to check capabilities in
 * @throws moodle_exception If the supplied context is not that course's context
 */
function aiplacement_modgen_fragment_context(int $courseid, int $contextid = 0): context_course {
    $coursecontext = context_course::instance($courseid);
    if ($contextid && (int) $contextid !== (int) $coursecontext->id) {
        throw new moodle_exception('invalidcontext', 'error');
    }
    return $coursecontext;
}

/**
 * Fragment callback to render the add_theme form in a modal.
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
/**
 * Fragment callback to render the add_theme form in a modal.
 *
 * This only renders the form HTML using moodleform.
 * Submission is handled via AJAX (ajax/create_sections.php).
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_form_add_theme(array $args): string {
    global $PAGE, $CFG;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();
    \aiplacement_modgen\local\settings_helper::require_quickadd_enabled();

    // Ensure required libraries are loaded.
    require_once($CFG->libdir . '/formslib.php');

    // Validate parameters.
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $contextid = clean_param($args['contextid'] ?? 0, PARAM_INT);

    $context = aiplacement_modgen_fragment_context($courseid, $contextid);

    // Verify permission.
    require_capability('aiplacement/modgen:managestructure', $context);

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Create form using moodleform.
    require_once(__DIR__ . '/classes/form/add_theme_form.php');
    $maxsections = get_config('aiplacement_modgen', 'maxquicksections') ?: 30;
    $formdata = ['courseid' => $courseid, 'maxsections' => $maxsections];
    $form = new \aiplacement_modgen_add_theme_form(null, $formdata);

    // Set default data.
    $form->set_data((object)$formdata);

    // Return rendered form HTML.
    // Submission will be handled by JavaScript AJAX to create_sections.php.
    return $form->render();
}

/**
 * Fragment callback to render the add_week form in a modal.
 *
 * This only renders the form HTML using moodleform.
 * Submission is handled via AJAX (ajax/create_sections.php).
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_form_add_week(array $args): string {
    global $PAGE, $CFG;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();
    \aiplacement_modgen\local\settings_helper::require_quickadd_enabled();

    // Ensure required libraries are loaded.
    require_once($CFG->libdir . '/formslib.php');

    // Validate parameters.
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $contextid = clean_param($args['contextid'] ?? 0, PARAM_INT);

    $context = aiplacement_modgen_fragment_context($courseid, $contextid);

    // Verify permission.
    require_capability('aiplacement/modgen:managestructure', $context);

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Create form using moodleform.
    require_once(__DIR__ . '/classes/form/add_week_form.php');
    $maxsections = get_config('aiplacement_modgen', 'maxquicksections') ?: 30;
    $formdata = ['courseid' => $courseid, 'maxsections' => $maxsections];
    $form = new \aiplacement_modgen_add_week_form(null, $formdata);

    // Set default data.
    $form->set_data((object)$formdata);

    // Return rendered form HTML.
    // Submission will be handled by JavaScript AJAX to create_sections.php.
    return $form->render();
}

/**
 * Fragment callback to render the template_from_prompt form in a modal.
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_form_template_from_prompt(array $args): string {
    global $PAGE, $CFG;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();

    // Ensure required libraries are loaded.
    require_once($CFG->libdir . '/formslib.php');

    // Validate parameters.
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $contextid = clean_param($args['contextid'] ?? 0, PARAM_INT);

    $context = aiplacement_modgen_fragment_context($courseid, $contextid);

    // Verify permission.
    require_capability('aiplacement/modgen:generatewithprompt', $context);

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Create form using moodleform.
    require_once(__DIR__ . '/classes/form/prompt_form.php');
    $formdata = ['courseid' => $courseid];
    $form = new \aiplacement_modgen_prompt_form(null, $formdata);

    // Set default data.
    $form->set_data((object)$formdata);

    // Return rendered form HTML.
    return $form->render();
}

/**
 * Serves files from the plugin's file areas.
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param context $context Context object
 * @param string $filearea File area name
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return bool|void False if file not found, nothing if file sent
 */
function aiplacement_modgen_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Check capability - users must be able to update courses to download templates.
    if (!has_capability('moodle/course:update', $context)) {
        return false;
    }

    // Only serve files from csvtemplates filearea.
    if ($filearea !== 'csvtemplates') {
        return false;
    }

    // Extract file info from args.
    $itemid = array_shift($args);
    $filename = array_pop($args);

    if (empty($args)) {
        $filepath = '/';
    } else {
        $filepath = '/' . implode('/', $args) . '/';
    }

    // Retrieve file.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'aiplacement_modgen', $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        return false;
    }

    // Send the file.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
