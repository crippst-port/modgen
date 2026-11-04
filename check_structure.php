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
 * Course structure diagnostic and repair tool for editors.
 *
 * SECURITY MEASURES:
 * - require_login() — authentication required
 * - require_capability('aiplacement/modgen:checkstructure') — course-level gate
 * - require_sesskey() on ALL write actions — CSRF protection
 * - required_param('id', PARAM_INT) — no user string in SQL
 * - All user-generated names passed through format_string() — XSS prevention
 *
 * @package    aiplacement_modgen
 * @copyright  2025 University of Portsmouth
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use aiplacement_modgen\local\integrity_checker;

// Required: course ID.
$courseid = required_param('id', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$confirm  = optional_param('confirm', 0, PARAM_INT);

// Security: require login and capability in course context.
require_login($courseid);
$course  = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('aiplacement/modgen:checkstructure', $context);

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

// Page setup.
$PAGE->set_course($course);
$PAGE->set_context($context);
$PAGE->set_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid]);
$PAGE->set_title(get_string('checkstructurepage', 'aiplacement_modgen'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('course');
$PAGE->requires->css('/ai/placement/modgen/styles.css');

// The checks and repairs are about Flexible sections' parent links: other formats have none.
if ($course->format !== 'flexsections') {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('checkstructure_notflexsections', 'aiplacement_modgen'), 'info');
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
    echo $OUTPUT->footer();
    exit;
}

// Actions that apply immediately without a separate confirmation click: each one only
// touches a single, explicitly-chosen section and is trivially reversible by picking a
// different parent again, unlike the bulk fix actions below.
$noconfirmactions = ['setparent', 'setparentall', 'setparents'];

/**
 * Renumber straight after a repair that changed parents, and carry the redirect's section numbers across.
 *
 * The flash table of just-reparented sections rides the redirect as section numbers, which the
 * renumber changes: they are translated so each row still names the same section and suggestion.
 *
 * @param int $courseid Course ID
 * @param array $params Redirect URL parameters, possibly with reparented and reparentedsuggestions
 * @return array The parameters, with those numbers translated
 */
function aiplacement_modgen_renumber_after_repair(int $courseid, array $params): array {
    $moved = \aiplacement_modgen\local\section_reorder_queue::renumber_now($courseid);
    foreach (['reparented', 'reparentedsuggestions'] as $key) {
        if (empty($params[$key])) {
            continue;
        }
        // 0 is top level, or no suggestion: section 0 never moves.
        $params[$key] = implode(',', array_map(
            fn($num) => (string) ($moved[(int) $num] ?? (int) $num),
            explode(',', $params[$key])
        ));
    }
    return $params;
}

// Handle fix actions — all require sesskey.
if ($action && ($confirm || in_array($action, $noconfirmactions, true))) {
    require_sesskey();

    switch ($action) {
        case 'setparent':
            $sectionnum = required_param('section', PARAM_INT);
            $newparent  = required_param('newparent', PARAM_INT);
            $result = integrity_checker::set_parent($courseid, $sectionnum, $newparent);
            if ($result['success']) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'setparent', 1)->trigger();
                $msg = get_string('setparent_done', 'aiplacement_modgen', $sectionnum);
                $type = \core\output\notification::NOTIFY_SUCCESS;
            } else {
                $msg = get_string('setparent_error_' . $result['error'], 'aiplacement_modgen');
                $type = \core\output\notification::NOTIFY_ERROR;
            }

            // If this row was submitted from the "just reparented by fix" flash table, carry
            // the remaining rows forward on the redirect so the table survives applying one
            // suggestion at a time instead of vanishing after the first click.
            $redirecturlparams = ['id' => $courseid, 'check' => 1];
            $remreparented = optional_param('remreparented', '', PARAM_SEQUENCE);
            if ($remreparented !== '') {
                $redirecturlparams['reparented'] = $remreparented;
                $redirecturlparams['reparentedsuggestions'] = optional_param(
                    'remreparentedsuggestions',
                    '',
                    PARAM_SEQUENCE
                );
            }
            if ($result['success']) {
                $redirecturlparams = aiplacement_modgen_renumber_after_repair($courseid, $redirecturlparams);
            }

            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                $msg,
                null,
                $type
            );
            break;
        case 'setparentall':
            // Bulk counterpart to 'setparent' above: applies every suggested-parent row from
            // the flash table in one go instead of one submit per row.
            $sections = required_param_array('sections', PARAM_INT);
            $newparents = required_param_array('newparents', PARAM_INT);
            $batchresult = integrity_checker::set_parents_bulk($courseid, $sections, $newparents);
            $applied = $batchresult['applied'];
            $failed = $batchresult['failed'];
            if ($applied > 0) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'setparentall', (int) $applied)
                    ->trigger();
            }

            if ($failed > 0) {
                $msg = get_string('setparentall_done_withfailures', 'aiplacement_modgen', (object) [
                    'applied' => $applied, 'failed' => $failed,
                ]);
                $type = \core\output\notification::NOTIFY_WARNING;
            } else {
                $msg = get_string('setparentall_done', 'aiplacement_modgen', $applied);
                $type = \core\output\notification::NOTIFY_SUCCESS;
            }

            // Rows that had no suggestion weren't touched by this action, so carry them forward
            // on the redirect so they stay visible for manual handling, same as 'setparent'.
            $redirecturlparams = ['id' => $courseid, 'check' => 1];
            $remreparented = optional_param('remreparented', '', PARAM_SEQUENCE);
            if ($remreparented !== '') {
                $redirecturlparams['reparented'] = $remreparented;
                $redirecturlparams['reparentedsuggestions'] = optional_param(
                    'remreparentedsuggestions',
                    '',
                    PARAM_SEQUENCE
                );
            }
            if ($applied > 0) {
                $redirecturlparams = aiplacement_modgen_renumber_after_repair($courseid, $redirecturlparams);
            }

            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                $msg,
                null,
                $type
            );
            break;
        case 'setparents':
            // The details table: every row's dropdown in one submit. Only rows moved off what their
            // dropdown opened on are changed, so an untouched row whose stored parent the dropdown
            // cannot show (missing, or itself) is not quietly sent to the top level.
            $newparents = optional_param_array('newparents', [], PARAM_INT);
            $shownparents = optional_param_array('shownparents', [], PARAM_INT);
            $changed = [];
            foreach ($newparents as $sectionnum => $newparent) {
                if (array_key_exists($sectionnum, $shownparents) && (int) $shownparents[$sectionnum] !== (int) $newparent) {
                    $changed[(int) $sectionnum] = (int) $newparent;
                }
            }

            $redirecturlparams = ['id' => $courseid, 'check' => 1];
            if (!$changed) {
                redirect(
                    new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                    get_string('setparents_none', 'aiplacement_modgen'),
                    null,
                    \core\output\notification::NOTIFY_INFO
                );
            }

            $batchresult = integrity_checker::set_parents_bulk($courseid, array_keys($changed), array_values($changed));
            $applied = $batchresult['applied'];
            $failed = $batchresult['failed'];
            if ($applied > 0) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'setparent', (int) $applied)->trigger();
                aiplacement_modgen_renumber_after_repair($courseid, $redirecturlparams);
            }
            if ($failed > 0) {
                $msg = get_string('setparents_done_withfailures', 'aiplacement_modgen', (object) [
                    'applied' => $applied, 'failed' => $failed,
                ]);
                $type = \core\output\notification::NOTIFY_WARNING;
            } else {
                $msg = get_string('setparents_done', 'aiplacement_modgen', $applied);
                $type = \core\output\notification::NOTIFY_SUCCESS;
            }
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                $msg,
                null,
                $type
            );
            break;
        case 'fixintegrity':
            $result = integrity_checker::fix_integrity($courseid);
            if ($result['fixed'] > 0) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'fixintegrity', (int) $result['fixed'])
                    ->trigger();
            }
            $msg = $result['fixed'] > 0
                ? get_string('fixintegrity_done', 'aiplacement_modgen', $result['fixed'])
                : get_string('fixintegrity_none', 'aiplacement_modgen');
            $redirecturlparams = [
                'id' => $courseid, 'check' => 1,
                'reparented' => implode(',', array_column($result['reparented'], 'section')),
                'reparentedsuggestions' => implode(',', array_map(
                    fn($r) => (string) ($r['suggestedparent'] ?? 0),
                    $result['reparented']
                )),
            ];
            if ($result['fixed'] > 0) {
                $redirecturlparams = aiplacement_modgen_renumber_after_repair($courseid, $redirecturlparams);
            }
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                $msg,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'fixcircular':
            $result = integrity_checker::fix_circular($courseid);
            if ($result['fixed'] > 0) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'fixcircular', (int) $result['fixed'])
                    ->trigger();
            }
            $msg = $result['fixed'] > 0
                ? get_string('fixcircular_done', 'aiplacement_modgen', $result['fixed'])
                : get_string('fixcircular_none', 'aiplacement_modgen');
            $reparentedparam = implode(',', array_column($result['reparented'], 'section'));
            $reparentedsuggestionsparam = implode(',', array_map(
                fn($r) => (string) ($r['suggestedparent'] ?? 0),
                $result['reparented']
            ));
            $redirecturlparams = [
                'id' => $courseid, 'check' => 1, 'reparented' => $reparentedparam,
                'reparentedsuggestions' => $reparentedsuggestionsparam,
            ];
            if ($result['fixed'] > 0) {
                $redirecturlparams = aiplacement_modgen_renumber_after_repair($courseid, $redirecturlparams);
            }
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', $redirecturlparams),
                $msg,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'fixsectionorder':
            $result = integrity_checker::fix_section_order($courseid);
            if (!empty($result['checkable']) && $result['fixed'] > 0) {
                \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'fixsectionorder', (int) $result['fixed'])
                    ->trigger();
            }
            if (!$result['checkable']) {
                // The hierarchy itself is unsound, so there is no correct order to renumber
                // into and nothing was written — point at the checks that have to be cleared
                // first rather than reporting a fix that did not happen.
                $msg = get_string('fixsectionorder_blocked_' . $result['blockedby'], 'aiplacement_modgen');
                $type = \core\output\notification::NOTIFY_ERROR;
            } else if ($result['fixed'] > 0) {
                $msg = get_string('fixsectionorder_done', 'aiplacement_modgen', $result['fixed']);
                $type = \core\output\notification::NOTIFY_SUCCESS;
            } else {
                $msg = get_string('fixsectionorder_none', 'aiplacement_modgen');
                $type = \core\output\notification::NOTIFY_SUCCESS;
            }
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1]),
                $msg,
                null,
                $type
            );
            break;
    }
}

// Show confirmation page for fix actions (not yet confirmed).
if ($action && !$confirm) {
    require_sesskey();

    $confirmstrings = [
        'fixintegrity' => 'fixintegrity_confirm',
        'fixcircular'  => 'fixcircular_confirm',
        'fixsectionorder' => 'fixsectionorder_confirm',
    ];

    if (isset($confirmstrings[$action])) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string($confirmstrings[$action], 'aiplacement_modgen'),
            new moodle_url('/ai/placement/modgen/check_structure.php', [
                'id'      => $courseid,
                'action'  => $action,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1])
        );
        echo $OUTPUT->footer();
        die();
    }
}

// Run check if requested.
$check   = optional_param('check', 0, PARAM_INT);
$diag    = null;
if ($check) {
    $diag = integrity_checker::check($courseid);
}

// Sections reparented to top-level by a just-completed circular-reference fix. This rides the
// redirect URL as a one-time flash — nothing is persisted — so the table below only appears
// immediately after the fix runs, and disappears again once the admin navigates elsewhere.
$reparentedraw = optional_param('reparented', '', PARAM_SEQUENCE);
$reparentedsections = $reparentedraw !== '' ? explode(',', $reparentedraw) : [];

// Best-guess replacement parent for each section above, same order, computed from the state
// just before the fix ran. A sentinel of '0' means no confident suggestion was found (0 can
// never itself be a suggestion: suggest_parent_from_map() only ever proposes section numbers
// above 0, since a genuine top-level answer and "nothing to suggest" would look identical
// downstream otherwise).
$reparentedsuggestionsraw = optional_param('reparentedsuggestions', '', PARAM_SEQUENCE);
$reparentedsuggestionslist = $reparentedsuggestionsraw !== '' ? explode(',', $reparentedsuggestionsraw) : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('checkstructurepage', 'aiplacement_modgen'), 2);
echo html_writer::tag('p', get_string('checkstructuredesc', 'aiplacement_modgen'));

// The automatic renumber's last outcome, however old: site administrators only, as on the toolbar.
if (\aiplacement_modgen\local\section_reorder_status::can_view($courseid)) {
    $reorderstatus = \aiplacement_modgen\local\section_reorder_status::get_for_course($courseid, false);
    echo html_writer::start_div(
        'aiplacement-modgen-reorder-panel d-flex align-items-center mb-4 p-3 border rounded',
        ['data-region' => 'aiplacement-modgen-reorder-panel']
    );
    if ($reorderstatus !== null) {
        echo html_writer::div(
            $OUTPUT->render_from_template('aiplacement_modgen/reorder_status_icon', $reorderstatus),
            'aiplacement-modgen-reorder-panel__icon mr-3'
        );
    }
    echo html_writer::start_div('mr-3');
    echo html_writer::tag('strong', get_string('reorderpanel_heading', 'aiplacement_modgen'), ['class' => 'd-block']);
    echo html_writer::span(
        $reorderstatus !== null ? $reorderstatus['summary'] : get_string('reorderpanel_none', 'aiplacement_modgen')
    );
    echo html_writer::end_div();
    // Runs the same renumber as the Renumber Sections button below, through its confirmation, without
    // waiting for the queue or for the check to have been run first.
    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/check_structure.php', [
            'id'      => $courseid,
            'action'  => 'fixsectionorder',
            'sesskey' => sesskey(),
        ]),
        get_string('reorderpanel_runnow', 'aiplacement_modgen'),
        ['class' => 'btn btn-secondary ml-auto flex-shrink-0 text-nowrap']
    );
    echo html_writer::end_div();
}

// Run check button.
echo html_writer::start_div('mb-4');
echo html_writer::link(
    new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1]),
    get_string('checkstructurerun', 'aiplacement_modgen'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

if ($diag !== null) {
    echo $OUTPUT->heading(get_string('checkstructureresults', 'aiplacement_modgen'), 3);

    if (!$diag['has_issues']) {
        echo $OUTPUT->notification(get_string('checkstructure_noissues', 'aiplacement_modgen'), 'success');
    } else {
        $issuecount = count(array_filter($diag['counts'], fn($v) => $v > 0));
        echo $OUTPUT->notification(
            get_string('checkstructure_issuesfound', 'aiplacement_modgen', $issuecount),
            'warning'
        );
    }

    // Sections just reparented to top-level by the circular-reference fix — shown once,
    // immediately after that fix runs, so the admin can find and reposition them.
    if (!empty($reparentedsections)) {
        echo $OUTPUT->heading(get_string('reparented_heading', 'aiplacement_modgen'), 3);
        echo html_writer::tag('p', get_string('reparented_desc', 'aiplacement_modgen'), ['class' => 'text-muted']);

        // Split into rows with a suggestion to apply and rows without one, so "Fix All" can
        // submit only the former and leave the latter in the flash table for manual handling.
        $applicablesections = [];
        $applicablesuggestions = [];
        $unsuggestedsections = [];
        foreach ($reparentedsections as $idx => $secnum) {
            $suggestion = isset($reparentedsuggestionslist[$idx]) ? (int) $reparentedsuggestionslist[$idx] : 0;
            if ($suggestion > 0) {
                $applicablesections[] = $secnum;
                $applicablesuggestions[] = $suggestion;
            } else {
                $unsuggestedsections[] = $secnum;
            }
        }

        if (!empty($applicablesections)) {
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/ai/placement/modgen/check_structure.php'),
                'class'  => 'mb-2',
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'setparentall']);
            foreach ($applicablesections as $i => $secnum) {
                echo html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'sections[]', 'value' => $secnum]
                );
                echo html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'newparents[]', 'value' => $applicablesuggestions[$i]]
                );
            }
            if (!empty($unsuggestedsections)) {
                echo html_writer::empty_tag('input', [
                    'type' => 'hidden', 'name' => 'remreparented', 'value' => implode(',', $unsuggestedsections),
                ]);
                echo html_writer::empty_tag('input', [
                    'type' => 'hidden', 'name' => 'remreparentedsuggestions',
                    'value' => implode(',', array_fill(0, count($unsuggestedsections), '0')),
                ]);
            }
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::tag('button', get_string('reparented_fixall', 'aiplacement_modgen'), [
                'type'  => 'submit',
                'class' => 'btn btn-sm btn-primary',
            ]);
            echo html_writer::end_tag('form');
        }

        echo html_writer::start_div('table-responsive mb-4');
        echo html_writer::start_tag('table', ['class' => 'generaltable table table-sm table-bordered table-warning']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('checkstructure_col_secnum', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('name'));
        echo html_writer::tag('th', get_string('checkstructure_col_dbid', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('reparented_col_suggested', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('reparented_col_action', 'aiplacement_modgen'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $sectionsbynum = [];
        foreach ($diag['sections'] as $sectionrow) {
            $sectionsbynum[$sectionrow->section] = $sectionrow;
        }

        foreach ($reparentedsections as $idx => $secnum) {
            $sectionrow = $sectionsbynum[$secnum] ?? null;
            $suggestion = isset($reparentedsuggestionslist[$idx]) ? (int) $reparentedsuggestionslist[$idx] : 0;

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', $secnum);
            echo html_writer::tag('td', $sectionrow
                ? format_string($sectionrow->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen'))
                : get_string('checkstructure_unnamed', 'aiplacement_modgen'));
            echo html_writer::tag('td', $sectionrow->id ?? '—');

            if ($suggestion > 0) {
                $suggestedrow = $sectionsbynum[$suggestion] ?? null;
                $suggestedname = $suggestedrow
                    ? format_string($suggestedrow->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen'))
                    : get_string('checkstructure_unnamed', 'aiplacement_modgen');

                // The other rows in this flash table, so the server can restore them on redirect
                // after this row's suggestion is applied, otherwise applying one row's suggestion
                // would wipe the whole table instead of just that row.
                $remainingsections = $reparentedsections;
                unset($remainingsections[$idx]);
                $remainingsuggestions = $reparentedsuggestionslist;
                unset($remainingsuggestions[$idx]);

                $suggestcell = html_writer::tag('span', $suggestion . ' - ' . shorten_text($suggestedname, 30), [
                    'class' => 'mr-2', 'title' => $suggestedname,
                ]);
                $suggestcell .= html_writer::start_tag('form', [
                    'method' => 'post',
                    'action' => new moodle_url('/ai/placement/modgen/check_structure.php'),
                    'class'  => 'form-inline d-inline',
                ]);
                $suggestcell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
                $suggestcell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'setparent']);
                $suggestcell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => $secnum]);
                $suggestcell .= html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'newparent', 'value' => $suggestion]
                );
                $suggestcell .= html_writer::empty_tag('input', [
                    'type' => 'hidden', 'name' => 'remreparented', 'value' => implode(',', $remainingsections),
                ]);
                $suggestcell .= html_writer::empty_tag('input', [
                    'type' => 'hidden', 'name' => 'remreparentedsuggestions',
                    'value' => implode(',', $remainingsuggestions),
                ]);
                $suggestcell .= html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]
                );
                $suggestcell .= html_writer::tag('button', get_string('reparented_apply_suggestion', 'aiplacement_modgen'), [
                    'type'  => 'submit',
                    'class' => 'btn btn-sm btn-outline-primary',
                ]);
                $suggestcell .= html_writer::end_tag('form');
                echo html_writer::tag('td', $suggestcell);
            } else {
                echo html_writer::tag(
                    'td',
                    get_string('reparented_no_suggestion', 'aiplacement_modgen'),
                    ['class' => 'text-muted small']
                );
            }

            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/course/view.php', ['id' => $courseid], 'section-' . $secnum),
                get_string('reparented_jumplink', 'aiplacement_modgen')
            ));
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }

    // Issue summary table — only shown when there's something to report; the green
    // "no structural issues" banner above already covers the all-clear case.
    if ($diag['has_issues']) {
        echo html_writer::start_div('table-responsive mb-4');
        echo html_writer::start_tag('table', ['class' => 'generaltable table table-sm table-bordered']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('checkstructure_col_check', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('checkstructure_col_issuesfound', 'aiplacement_modgen'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $checkkeys = [
            'section0_with_parent',
            'orphaned_options',
            'invalid_parents',
            'null_parents',
            'missing_parents',
            'duplicate_sections',
            'circular_refs',
            'section_order',
        ];
        foreach ($checkkeys as $key) {
            $count = $diag['counts'][$key];
            if ($count === 0) {
                continue;
            }
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', get_string('diag_' . $key, 'aiplacement_modgen'));
            $icon = html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle text-danger', 'aria-hidden' => 'true']);
            $countspan = html_writer::tag('span', $icon . ' ' . $count, ['class' => 'text-danger font-weight-bold']);
            echo html_writer::tag('td', $countspan);
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }

    // Plain-language impact warnings for the issues that matter most.
    if (!empty($diag['possible_format_switch_wipe'])) {
        echo $OUTPUT->notification(get_string('checkstructure_wipewarning', 'aiplacement_modgen'), 'error');
    }
    if ($diag['counts']['circular_refs'] > 0) {
        echo $OUTPUT->notification(get_string('checkstructure_circularwarning', 'aiplacement_modgen'), 'error');
    }
    if ($diag['counts']['section_order'] > 0) {
        echo $OUTPUT->notification(get_string('checkstructure_orderwarning', 'aiplacement_modgen'), 'error');
    }

    // Section detail table — filterable: defaults to issues-only (full listing is noisy on
    // large courses), with a toggle to reveal every section. When the issues-only view has
    // nothing to show, a message replaces the (otherwise empty-looking) table.
    // Shown before the fix actions below so the admin can see exactly what's wrong first.
    echo $OUTPUT->heading(get_string('sectiondetails', 'aiplacement_modgen'), 3);

    echo html_writer::start_div('form-check mb-2');
    echo html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'id'    => 'checkstructure-showall',
        'class' => 'form-check-input',
    ]);
    echo html_writer::tag('label', get_string('checkstructure_showall', 'aiplacement_modgen'), [
        'for'   => 'checkstructure-showall',
        'class' => 'form-check-label',
    ]);
    echo html_writer::end_div();

    echo html_writer::tag(
        'div',
        $OUTPUT->notification(
            get_string('checkstructure_nosectionissues', 'aiplacement_modgen'),
            \core\output\notification::NOTIFY_INFO,
            false
        ),
        ['id' => 'checkstructure-noissuesmsg', 'style' => 'display:none;']
    );

    $PAGE->requires->js_init_code(
        "var cb = document.getElementById('checkstructure-showall');
        var wrap = document.getElementById('checkstructure-sectiontablewrap');
        var table = document.getElementById('checkstructure-sectiontable');
        var msg = document.getElementById('checkstructure-noissuesmsg');
        if (cb && table) {
            var hasissues = table.querySelector(\"tbody tr[data-issue='1']\") !== null;
            var apply = function() {
                table.querySelectorAll('tbody tr').forEach(function(row) {
                    row.style.display = (cb.checked || row.dataset.issue === '1') ? '' : 'none';
                });
                var showmsg = !cb.checked && !hasissues;
                if (msg) { msg.style.display = showmsg ? '' : 'none'; }
                if (wrap) { wrap.style.display = showmsg ? 'none' : ''; }
            };
            cb.addEventListener('change', apply);
            apply();
        }",
        true
    );

    // One form for the whole table: parents are changed together and the course renumbered once,
    // so no row's section number moves while the others are still being chosen.
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/ai/placement/modgen/check_structure.php'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'setparents']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_div('table-responsive mb-4', ['id' => 'checkstructure-sectiontablewrap']);
    echo html_writer::start_tag('table', [
        'id'    => 'checkstructure-sectiontable',
        'class' => 'generaltable table table-striped table-bordered table-sm',
    ]);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('checkstructure_col_secnum', 'aiplacement_modgen'));
    echo html_writer::tag('th', get_string('name'));
    echo html_writer::tag('th', get_string('parentsection', 'aiplacement_modgen'));
    echo html_writer::tag('th', get_string('checkstructure_col_depth', 'aiplacement_modgen'));
    echo html_writer::tag('th', get_string('visible'));
    echo html_writer::tag('th', get_string('activities', 'aiplacement_modgen'));
    echo html_writer::tag('th', get_string('checkstructure_col_dbid', 'aiplacement_modgen'));
    echo html_writer::tag('th', get_string('checkstructure_col_issues', 'aiplacement_modgen'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    // Options for the per-row parent picker: every section number/name in the course, plus
    // top-level. Built once and reused for every row rather than requeried per section.
    // Names are truncated: some course sections carry very long titles that would otherwise
    // blow the <select> out to an unusable width, with the full name kept alongside for a
    // hover tooltip on each <option>.
    $toplevellabel = get_string('checkstructure_toplevel', 'aiplacement_modgen');
    $parentoptions = ['0' => ['short' => $toplevellabel, 'full' => $toplevellabel]];
    foreach ($diag['sections'] as $optionsection) {
        if ($optionsection->section == 0) {
            continue;
        }
        $fullname = format_string($optionsection->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen'));
        $parentoptions[(string) $optionsection->section] = [
            'short' => $optionsection->section . ': ' . shorten_text($fullname, 40),
            'full'  => $optionsection->section . ': ' . $fullname,
        ];
    }

    foreach ($diag['sections'] as $s) {
        $rowclass = $s->has_row_issues ? 'table-warning' : '';
        echo html_writer::start_tag('tr', [
            'class'      => $rowclass,
            'data-issue' => $s->has_row_issues ? '1' : '0',
        ]);
        echo html_writer::tag('td', $s->section);
        $fullsectionname = format_string($s->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen'));
        echo html_writer::tag('td', shorten_text($fullsectionname, 40), ['title' => $fullsectionname]);

        if ($s->section == 0) {
            echo html_writer::tag('td', '—');
        } else {
            $currentparent = $s->parent ?? get_string('checkstructure_missing', 'aiplacement_modgen');
            $rowoptions = $parentoptions;
            unset($rowoptions[(string) $s->section]); // A section can't be its own parent.

            $select = html_writer::start_tag('select', [
                'name'  => 'newparents[' . $s->section . ']',
                'aria-label' => get_string('newparentfor', 'aiplacement_modgen', $s->section),
                'class' => 'form-control form-control-sm d-inline-block',
                'style' => 'max-width: 260px;',
            ]);
            // What the dropdown opens on: the current parent, or the first option when the stored
            // value has none (missing, or the section itself). Only a row moved off it is changed.
            $shown = array_key_first($rowoptions);
            foreach ($rowoptions as $optionvalue => $option) {
                $optionattrs = ['value' => $optionvalue, 'title' => $option['full']];
                if ((string) $optionvalue === (string) $currentparent) {
                    $optionattrs['selected'] = 'selected';
                    $shown = $optionvalue;
                }
                $select .= html_writer::tag('option', $option['short'], $optionattrs);
            }
            $select .= html_writer::end_tag('select');
            $select .= html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'shownparents[' . $s->section . ']',
                'value' => $shown,
            ]);

            // Show the raw stored value as plain text too — the dropdown alone can't represent
            // a self-parent (its own section number is deliberately excluded from the options),
            // so this is the only place that state stays visible once the row has a picker.
            $currentlabel = html_writer::tag('div', get_string('setparent_current', 'aiplacement_modgen', $currentparent), [
                'class' => 'small text-muted',
            ]);

            echo html_writer::tag('td', $currentlabel . $select);
        }
        echo html_writer::tag('td', $s->depth);
        echo html_writer::tag('td', $s->visible ? get_string('yes') : get_string('no'));
        echo html_writer::tag('td', $s->activitycount);
        echo html_writer::tag('td', $s->id);
        if ($s->has_row_issues) {
            $icon = html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle text-danger', 'aria-hidden' => 'true']);
            $issuestext = s(implode('; ', $s->row_issues));
            $issuescell = $icon . ' ' . html_writer::tag('span', $issuestext, ['class' => 'text-danger small']);
        } else {
            $issuescell = get_string('checkstructure_ok', 'aiplacement_modgen');
        }
        echo html_writer::tag('td', $issuescell);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::tag('button', get_string('setparents_submit', 'aiplacement_modgen'), [
        'type'  => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // Fix sections — shown only when relevant issues exist, always last on the page so the
    // admin sees the full diagnostic picture before being offered a destructive action.
    $fixintegrity = $diag['counts']['section0_with_parent'] > 0
        || $diag['counts']['orphaned_options'] > 0
        || $diag['counts']['invalid_parents'] > 0
        || $diag['counts']['null_parents'] > 0
        || $diag['counts']['missing_parents'] > 0;

    if ($fixintegrity) {
        $fixintegrityheading = get_string('fixintegrity_label', 'aiplacement_modgen')
            . ' ' . $OUTPUT->help_icon('fixintegrity_label', 'aiplacement_modgen');
        echo $OUTPUT->heading($fixintegrityheading, 4);
        echo html_writer::tag('p', get_string('fixintegrity_desc', 'aiplacement_modgen'), ['class' => 'text-muted']);
        echo html_writer::link(
            new moodle_url('/ai/placement/modgen/check_structure.php', [
                'id'      => $courseid,
                'action'  => 'fixintegrity',
                'sesskey' => sesskey(),
            ]),
            get_string('fixintegrity_label', 'aiplacement_modgen'),
            ['class' => 'btn btn-warning mb-4']
        );
    }

    if ($diag['counts']['circular_refs'] > 0) {
        $fixcircularheading = get_string('fixcircular_label', 'aiplacement_modgen')
            . ' ' . $OUTPUT->help_icon('fixcircular_label', 'aiplacement_modgen');
        echo $OUTPUT->heading($fixcircularheading, 4);
        echo html_writer::tag('p', get_string('fixcircular_desc', 'aiplacement_modgen'), ['class' => 'text-muted']);
        echo html_writer::link(
            new moodle_url('/ai/placement/modgen/check_structure.php', [
                'id'      => $courseid,
                'action'  => 'fixcircular',
                'sesskey' => sesskey(),
            ]),
            get_string('fixcircular_label', 'aiplacement_modgen'),
            ['class' => 'btn btn-danger mb-4']
        );
    }

    if ($diag['counts']['section_order'] > 0) {
        $fixorderheading = get_string('fixsectionorder_label', 'aiplacement_modgen')
            . ' ' . $OUTPUT->help_icon('fixsectionorder_label', 'aiplacement_modgen');
        echo $OUTPUT->heading($fixorderheading, 4);
        echo html_writer::tag('p', get_string('fixsectionorder_desc', 'aiplacement_modgen'), ['class' => 'text-muted']);
        echo html_writer::link(
            new moodle_url('/ai/placement/modgen/check_structure.php', [
                'id'      => $courseid,
                'action'  => 'fixsectionorder',
                'sesskey' => sesskey(),
            ]),
            get_string('fixsectionorder_label', 'aiplacement_modgen'),
            ['class' => 'btn btn-warning mb-4']
        );
    }
}

// Back to course link.
echo html_writer::tag(
    'div',
    html_writer::link(
        new moodle_url('/course/view.php', ['id' => $courseid]),
        get_string('checkstructure_backtocourse', 'aiplacement_modgen'),
        ['class' => 'btn btn-secondary']
    ),
    ['class' => 'mt-3']
);

echo $OUTPUT->footer();
