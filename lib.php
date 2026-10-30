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

    // Navigation bar - only show in edit mode and on the course page, in a format the plugin builds in.
    $formats = \aiplacement_modgen\local\course_formats::supported_formats();
    $coursepages = array_map(fn($format) => 'course-view-' . $format, $formats);
    if (!$PAGE->user_is_editing() || !in_array($PAGE->pagetype, $coursepages, true)) {
        return;
    }

    // Get current section from URL (for context-aware creation).
    $currentsection = optional_param('section', 0, PARAM_INT);

    // No toolbar at all unless it has an entry this user can use here: a bar with nothing to press
    // (or only the help links) is just in the way.
    $entries = aiplacement_modgen_toolbar_entries($course->id, $context, $currentsection);
    if (!in_array(true, $entries, true)) {
        return;
    }

    // Load CSS.
    $PAGE->requires->css('/ai/placement/modgen/styles.css');

    // Load strings needed by JS modules (must be in main page context, not fragment).
    $PAGE->requires->strings_for_js([
        'approveandcreate',
    ], 'aiplacement_modgen');

    // Initialize toolbar via AMD module using Fragment API. The fragment works out the entries again
    // itself: these flags only come back from the browser.
    $PAGE->requires->js_call_amd('aiplacement_modgen/course_toolbar', 'init', [[
        'courseid' => $course->id,
        'contextid' => $context->id,
        'showgenerator' => $entries['fromprompt'],
        'showmanagestructure' => has_capability('aiplacement/modgen:managestructure', $context),
        'showquickadd' => \aiplacement_modgen\local\settings_helper::is_quickadd_enabled(),
        'showtemplatefromfile' => $entries['fromfile'],
        'showtemplatefromptompt' => $entries['fromprompt'],
        'showcheckstructure' => $entries['checkstructure'],
        'currentsection' => $currentsection,
    ]]);
}

/**
 * Which toolbar entries a user can use in a course, from where the toolbar is shown.
 *
 * The one place the toolbar's contents are decided: the course page adds the toolbar only when one
 * of these is true, and the fragment renders exactly these. Each entry needs its capability and its
 * switch, and some need the course's format to hold what they build.
 *
 * @param int $courseid Course ID.
 * @param context_course $context Course context.
 * @param int $currentsection Section the toolbar is shown in, 0 at the top of the course.
 * @return bool[] Keyed newtheme, newweek, assessments, fromfile, fromprompt, checkstructure.
 */
function aiplacement_modgen_toolbar_entries(int $courseid, context_course $context, int $currentsection): array {
    $settings = \aiplacement_modgen\local\settings_helper::class;
    $flexsections = \aiplacement_modgen\local\course_formats::is_flexsections($courseid);
    $attop = $currentsection <= 0;
    $canstructure = has_capability('aiplacement/modgen:managestructure', $context);
    $structure = $settings::is_quickadd_enabled() && $canstructure;

    return [
        // Themes are top-level containers, and only Flexible sections can hold them.
        'newtheme' => $structure && $attop && $flexsections,
        // In a core format a week's sessions are subsections, which cannot go inside another section.
        'newweek' => $structure && ($attop || $flexsections),
        // Its own switch; added at the top of the course.
        'assessments' => $settings::is_assessments_enabled() && $canstructure && $attop,
        'fromfile' => $settings::is_structurefromfile_enabled()
            && has_capability('aiplacement/modgen:generatefromtemplate', $context),
        'fromprompt' => $settings::is_ai_enabled() && has_capability('aiplacement/modgen:generatewithprompt', $context),
        // Check Structure repairs Flexible sections hierarchies; core formats keep their own.
        'checkstructure' => $flexsections && has_capability('aiplacement/modgen:checkstructure', $context),
    ];
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
 * @return string Rendered HTML, empty when the user has no entry to use here
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
    // capability is re-checked here, as aiplacement_modgen_extend_navigation_course() does.
    $cancheckstructure = has_capability('aiplacement/modgen:checkstructure', $context);
    if (
        !has_capability('aiplacement/modgen:managestructure', $context)
            && !has_capability('aiplacement/modgen:generatewithprompt', $context)
            && !has_capability('aiplacement/modgen:generatefromtemplate', $context)
            && !$cancheckstructure
    ) {
        throw new required_capability_exception(
            $context,
            'aiplacement/modgen:managestructure',
            'nopermissions',
            ''
        );
    }

    $currentsection = clean_param($args['currentsection'] ?? 0, PARAM_INT);
    $entries = aiplacement_modgen_toolbar_entries($courseid, $context, $currentsection);
    // Nothing to use here: no toolbar, as on the course page.
    if (!in_array(true, $entries, true)) {
        return '';
    }

    $toolbar = new \aiplacement_modgen\output\course_toolbar($courseid, $entries);

    // A course the plugin cannot build in (subsections switched off, say) gets the reason in place of the menus,
    // rather than entries that would each fail with it.
    $reason = \aiplacement_modgen\local\course_formats::unsupported_reason($courseid);
    if ($reason !== null) {
        $toolbar->set_unavailable(get_string($reason, 'aiplacement_modgen'));
    }

    // Get the plugin renderer and render the toolbar.
    $renderer = $PAGE->get_renderer('aiplacement_modgen');
    return $renderer->render($toolbar);
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
    \aiplacement_modgen\local\course_formats::require_supported($courseid);
    \aiplacement_modgen\local\course_formats::require_themes($courseid);

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Create form using moodleform.
    require_once(__DIR__ . '/classes/form/add_theme_form.php');
    $maxsections = get_config('aiplacement_modgen', 'maxquicksections') ?: 30;
    $formdata = ['courseid' => $courseid, 'maxsections' => $maxsections];
    $form = new \aiplacement_modgen_add_theme_form(
        null,
        $formdata,
        'post',
        '',
        ['class' => 'aiplacement-modgen-qa full-width-labels']
    );

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
    \aiplacement_modgen\local\course_formats::require_supported($courseid);

    // Set page context for proper JS/CSS loading.
    $PAGE->set_context($context);

    // Create form using moodleform.
    require_once(__DIR__ . '/classes/form/add_week_form.php');
    $maxsections = get_config('aiplacement_modgen', 'maxquicksections') ?: 30;
    $formdata = ['courseid' => $courseid, 'maxsections' => $maxsections];
    $form = new \aiplacement_modgen_add_week_form(
        null,
        $formdata,
        'post',
        '',
        ['class' => 'aiplacement-modgen-qa full-width-labels']
    );

    // Set default data.
    $form->set_data((object)$formdata);

    // Return rendered form HTML.
    // Submission will be handled by JavaScript AJAX to create_sections.php.
    return $form->render();
}

/**
 * Fragment callback to render the add_assessments form in a modal.
 *
 * Submission is handled via AJAX (ajax/create_sections.php).
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_form_add_assessments(array $args): string {
    global $PAGE, $CFG;

    // Fragments are reachable directly, so they enforce the placement switch too.
    \aiplacement_modgen\local\settings_helper::require_placement_enabled();
    \aiplacement_modgen\local\settings_helper::require_assessments_enabled();

    require_once($CFG->libdir . '/formslib.php');

    $courseid = clean_param($args['courseid'], PARAM_INT);
    $contextid = clean_param($args['contextid'] ?? 0, PARAM_INT);

    $context = aiplacement_modgen_fragment_context($courseid, $contextid);
    require_capability('aiplacement/modgen:managestructure', $context);
    \aiplacement_modgen\local\course_formats::require_supported($courseid);
    $PAGE->set_context($context);

    require_once(__DIR__ . '/classes/form/add_assessments_form.php');
    $form = new \aiplacement_modgen_add_assessments_form(
        null,
        ['courseid' => $courseid],
        'post',
        '',
        ['class' => 'aiplacement-modgen-qa full-width-labels']
    );
    $form->set_data((object) ['courseid' => $courseid]);

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
    \aiplacement_modgen\local\course_formats::require_supported($courseid);
    \aiplacement_modgen\local\settings_helper::require_ai_enabled();

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
