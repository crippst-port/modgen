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

// Page setup.
$PAGE->set_course($course);
$PAGE->set_context($context);
$PAGE->set_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid]);
$PAGE->set_title(get_string('checkstructurepage', 'aiplacement_modgen'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('course');
$PAGE->requires->css('/ai/placement/modgen/styles.css');

// Handle fix actions — all require sesskey.
if ($action && $confirm) {
    require_sesskey();

    switch ($action) {
        case 'fixintegrity':
            $result = integrity_checker::fix_integrity($courseid);
            $msg = $result['fixed'] > 0
                ? get_string('fixintegrity_done', 'aiplacement_modgen', $result['fixed'])
                : get_string('fixintegrity_none', 'aiplacement_modgen');
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1]),
                $msg,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'fixcleanup':
            $result = integrity_checker::cleanup_orphaned($courseid);
            $msg = $result['deleted'] > 0
                ? get_string('fixcleanup_done', 'aiplacement_modgen', $result['deleted'])
                : get_string('fixcleanup_none', 'aiplacement_modgen');
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1]),
                $msg,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'fixcircular':
            $result = integrity_checker::fix_circular($courseid);
            $msg = $result['fixed'] > 0
                ? get_string('fixcircular_done', 'aiplacement_modgen', $result['fixed'])
                : get_string('fixcircular_none', 'aiplacement_modgen');
            $reparentedparam = implode(',', array_column($result['reparented'], 'section'));
            redirect(
                new moodle_url('/ai/placement/modgen/check_structure.php', [
                    'id' => $courseid, 'check' => 1, 'reparented' => $reparentedparam,
                ]),
                $msg,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

// Show confirmation page for fix actions (not yet confirmed).
if ($action && !$confirm) {
    require_sesskey();

    $confirmstrings = [
        'fixintegrity' => 'fixintegrity_confirm',
        'fixcleanup'   => 'fixcleanup_confirm',
        'fixcircular'  => 'fixcircular_confirm',
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('checkstructurepage', 'aiplacement_modgen'), 2);
echo html_writer::tag('p', get_string('checkstructuredesc', 'aiplacement_modgen'));

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

        echo html_writer::start_div('table-responsive mb-4');
        echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered table-warning']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('checkstructure_col_secnum', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('name'));
        echo html_writer::tag('th', get_string('checkstructure_col_dbid', 'aiplacement_modgen'));
        echo html_writer::tag('th', get_string('reparented_col_action', 'aiplacement_modgen'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $sectionsbynum = [];
        foreach ($diag['sections'] as $sectionrow) {
            $sectionsbynum[$sectionrow->section] = $sectionrow;
        }

        foreach ($reparentedsections as $secnum) {
            $sectionrow = $sectionsbynum[$secnum] ?? null;
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', $secnum);
            echo html_writer::tag('td', $sectionrow
                ? format_string($sectionrow->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen'))
                : get_string('checkstructure_unnamed', 'aiplacement_modgen'));
            echo html_writer::tag('td', $sectionrow->id ?? '—');
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
        echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
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
            'orphaned_sections',
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
    if ($diag['counts']['circular_refs'] > 0) {
        echo $OUTPUT->notification(get_string('checkstructure_circularwarning', 'aiplacement_modgen'), 'error');
    }
    if ($diag['counts']['orphaned_sections'] > 0) {
        echo $OUTPUT->notification(get_string('checkstructure_orphanwarning', 'aiplacement_modgen'), 'warning');
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

    echo html_writer::tag('div', get_string('checkstructure_nosectionissues', 'aiplacement_modgen'), [
        'id'    => 'checkstructure-noissuesmsg',
        'class' => 'alert alert-info',
        'style' => 'display:none;',
    ]);

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

    echo html_writer::start_div('table-responsive mb-4', ['id' => 'checkstructure-sectiontablewrap']);
    echo html_writer::start_tag('table', [
        'id'    => 'checkstructure-sectiontable',
        'class' => 'table table-striped table-bordered table-sm',
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

    foreach ($diag['sections'] as $s) {
        $rowclass = $s->has_row_issues ? 'table-warning' : '';
        echo html_writer::start_tag('tr', [
            'class'      => $rowclass,
            'data-issue' => $s->has_row_issues ? '1' : '0',
        ]);
        echo html_writer::tag('td', $s->section);
        echo html_writer::tag('td', format_string($s->name ?? get_string('checkstructure_unnamed', 'aiplacement_modgen')));
        $parentcell = $s->section == 0 ? '—' : ($s->parent ?? get_string('checkstructure_missing', 'aiplacement_modgen'));
        echo html_writer::tag('td', $parentcell);
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
    echo html_writer::end_div();

    // Fix sections — shown only when relevant issues exist, always last on the page so the
    // admin sees the full diagnostic picture before being offered a destructive action.
    $fixintegrity = $diag['counts']['section0_with_parent'] > 0
        || $diag['counts']['orphaned_options'] > 0
        || $diag['counts']['invalid_parents'] > 0
        || $diag['counts']['null_parents'] > 0
        || $diag['counts']['missing_parents'] > 0;

    if ($fixintegrity) {
        echo $OUTPUT->heading(get_string('fixintegrity_label', 'aiplacement_modgen'), 4);
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

    if ($diag['counts']['orphaned_sections'] > 0) {
        echo $OUTPUT->heading(get_string('fixcleanup_label', 'aiplacement_modgen'), 4);
        echo html_writer::tag('p', get_string('fixcleanup_desc', 'aiplacement_modgen'), ['class' => 'text-muted']);
        echo html_writer::link(
            new moodle_url('/ai/placement/modgen/check_structure.php', [
                'id'      => $courseid,
                'action'  => 'fixcleanup',
                'sesskey' => sesskey(),
            ]),
            get_string('fixcleanup_label', 'aiplacement_modgen'),
            ['class' => 'btn btn-danger mb-4']
        );
    }

    if ($diag['counts']['circular_refs'] > 0) {
        echo $OUTPUT->heading(get_string('fixcircular_label', 'aiplacement_modgen'), 4);
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
