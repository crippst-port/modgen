<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin-only diagnostic and testing tools for modgen plugin.
 *
 * SECURITY MEASURES:
 * - require_login() + require_capability('moodle/site:config') - Admin only
 * - require_sesskey() on ALL actions - CSRF protection
 * - All SQL uses $DB API with parameter binding - SQL injection prevention
 * - context_system::instance() - System-level context
 * - format_string() on all user-generated content - XSS prevention
 * - Capability checks in AJAX endpoints - Authorization
 *
 * Provides secure access to:
 * - Course integrity checking and repair
 * - Hierarchy analysis and visualization
 * - Circular reference detection and fixing
 * - Section cleanup utilities
 * - Data export (JSON/HTML/Text)
 *
 * @package    aiplacement_modgen
 * @copyright  2025 University of Portsmouth
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security: Require admin login.
require_login();
require_capability('moodle/site:config', context_system::instance());

// Set up page.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/ai/placement/modgen/admin_tools.php');
$PAGE->set_title(get_string('admintools', 'aiplacement_modgen'));
$PAGE->set_heading(get_string('admintools', 'aiplacement_modgen'));
$PAGE->set_pagelayout('admin');

// Get action parameter.
$action = optional_param('action', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

echo $OUTPUT->header();

// Handle actions - SECURITY: All actions require valid sesskey.
if ($action) {
    // Validate sesskey for ALL actions (CSRF protection).
    require_sesskey();
    
    switch ($action) {
        case 'checkintegrity':
            if ($courseid > 0) {
                echo $OUTPUT->heading(get_string('integritycheck', 'aiplacement_modgen'), 3);
                $result = check_course_integrity($courseid, false);

                // Show action buttons if there are issues to fix
                if ($result['hasIssues']) {
                    echo html_writer::start_div('mt-3');
                    echo html_writer::tag('p', 'Course ID: ' . $courseid, ['class' => 'font-weight-bold']);

                    echo html_writer::link(
                        new moodle_url('/ai/placement/modgen/admin_tools.php', [
                            'action' => 'fixintegrity',
                            'courseid' => $courseid,
                            'sesskey' => sesskey(),
                            'sesskey' => sesskey()
                        ]),
                        get_string('fixintegrity', 'aiplacement_modgen'),
                        ['class' => 'btn btn-warning mr-2']
                    );

                    echo html_writer::link(
                        new moodle_url('/ai/placement/modgen/admin_tools.php', [
                            'action' => 'cleanup',
                            'courseid' => $courseid,
                            'sesskey' => sesskey()
                        ]),
                        get_string('cleanup', 'aiplacement_modgen'),
                        ['class' => 'btn btn-danger mr-2']
                    );

                    echo html_writer::link(
                        new moodle_url('/ai/placement/modgen/admin_tools.php', [
                            'action' => 'checkintegrity',
                            'courseid' => $courseid,
                            'sesskey' => sesskey()
                        ]),
                        get_string('recheck', 'aiplacement_modgen'),
                        ['class' => 'btn btn-info']
                    );

                    echo html_writer::end_div();
                }
            } else {
                echo $OUTPUT->notification(get_string('selectcourse', 'aiplacement_modgen'), 'error');
            }
            break;

        case 'fixintegrity':
            if ($courseid > 0 && $confirm) {
                echo $OUTPUT->heading(get_string('fixingintegrity', 'aiplacement_modgen'), 3);
                check_course_integrity($courseid, true);

                // Show action buttons after fixing
                echo html_writer::start_div('mt-3');
                echo html_writer::link(
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'checkintegrity',
                        'courseid' => $courseid,
                        'sesskey' => sesskey()
                    ]),
                    get_string('recheck', 'aiplacement_modgen'),
                    ['class' => 'btn btn-info mr-2']
                );

                echo html_writer::link(
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'cleanup',
                        'courseid' => $courseid,
                        'sesskey' => sesskey()
                    ]),
                    get_string('cleanup', 'aiplacement_modgen'),
                    ['class' => 'btn btn-danger']
                );
                echo html_writer::end_div();
            } else if ($courseid > 0) {
                // Show confirmation.
                echo $OUTPUT->confirm(
                    get_string('confirmfixintegrity', 'aiplacement_modgen'),
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'fixintegrity',
                        'courseid' => $courseid,
                        'confirm' => 1,
                        'sesskey' => sesskey()
                    ]),
                    new moodle_url('/ai/placement/modgen/admin_tools.php')
                );
                echo $OUTPUT->footer();
                die();
            }
            break;

        case 'cleanup':
            if ($courseid > 0 && $confirm) {
                echo $OUTPUT->heading(get_string('cleaningup', 'aiplacement_modgen'), 3);
                cleanup_orphaned_sections($courseid);

                // Show action buttons after cleanup
                echo html_writer::start_div('mt-3');
                echo html_writer::link(
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'checkintegrity',
                        'courseid' => $courseid,
                        'sesskey' => sesskey()
                    ]),
                    get_string('recheck', 'aiplacement_modgen'),
                    ['class' => 'btn btn-info']
                );
                echo html_writer::end_div();
            } else if ($courseid > 0) {
                // Show confirmation.
                echo $OUTPUT->confirm(
                    get_string('confirmcleanup', 'aiplacement_modgen'),
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'cleanup',
                        'courseid' => $courseid,
                        'confirm' => 1,
                        'sesskey' => sesskey()
                    ]),
                    new moodle_url('/ai/placement/modgen/admin_tools.php')
                );
                echo $OUTPUT->footer();
                die();
            }
            break;

        case 'analyzehierarchy':
            if ($courseid > 0) {
                echo $OUTPUT->heading(get_string('hierarchyanalysis', 'aiplacement_modgen'), 3);
                display_hierarchy_analysis($courseid);
            } else {
                echo $OUTPUT->notification(get_string('selectcourse', 'aiplacement_modgen'), 'error');
            }
            break;

        case 'exporthierarchy':
            if ($courseid > 0) {
                $format = optional_param('format', 'json', PARAM_ALPHA);
                export_hierarchy_data($courseid, $format);
                die(); // Export sends file and exits
            } else {
                echo $OUTPUT->notification(get_string('selectcourse', 'aiplacement_modgen'), 'error');
            }
            break;

        case 'fixcircular':
            if ($courseid > 0 && $confirm) {
                echo $OUTPUT->heading(get_string('fixingcircular', 'aiplacement_modgen'), 3);
                fix_circular_references($courseid);

                // Show action buttons after fixing
                echo html_writer::start_div('mt-3');
                echo html_writer::link(
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'analyzehierarchy',
                        'courseid' => $courseid,
                        'sesskey' => sesskey()
                    ]),
                    get_string('reanalyzehierarchy', 'aiplacement_modgen'),
                    ['class' => 'btn btn-info']
                );
                echo html_writer::end_div();
            } else if ($courseid > 0) {
                // Show confirmation.
                echo $OUTPUT->confirm(
                    get_string('confirmfixcircular', 'aiplacement_modgen'),
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'fixcircular',
                        'courseid' => $courseid,
                        'confirm' => 1,
                        'sesskey' => sesskey()
                    ]),
                    new moodle_url('/ai/placement/modgen/admin_tools.php')
                );
                echo $OUTPUT->footer();
                die();
            }
            break;

        case 'flattenhierarchy':
            if ($courseid > 0 && $confirm) {
                echo $OUTPUT->heading(get_string('flatteninghierarchy', 'aiplacement_modgen'), 3);
                flatten_hierarchy_to_toplevel($courseid);

                // Show action buttons after flattening
                echo html_writer::start_div('mt-3');
                echo html_writer::link(
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'analyzehierarchy',
                        'courseid' => $courseid,
                        'sesskey' => sesskey()
                    ]),
                    get_string('reanalyzehierarchy', 'aiplacement_modgen'),
                    ['class' => 'btn btn-info']
                );
                echo html_writer::end_div();
            } else if ($courseid > 0) {
                // Show confirmation.
                echo $OUTPUT->confirm(
                    get_string('confirmflattenhierarchy', 'aiplacement_modgen'),
                    new moodle_url('/ai/placement/modgen/admin_tools.php', [
                        'action' => 'flattenhierarchy',
                        'courseid' => $courseid,
                        'confirm' => 1,
                        'sesskey' => sesskey()
                    ]),
                    new moodle_url('/ai/placement/modgen/admin_tools.php')
                );
                echo $OUTPUT->footer();
                die();
            }
            break;
    }

    echo html_writer::tag('div',
        html_writer::link(
            new moodle_url('/ai/placement/modgen/admin_tools.php'),
            get_string('backtomainpage', 'aiplacement_modgen'),
            ['class' => 'btn btn-secondary']
        ),
        ['class' => 'mt-3']
    );
}

// Display main dashboard.
if (!$action || !confirm_sesskey()) {
    display_dashboard($courseid);
}

echo $OUTPUT->footer();

/**
 * Display the main admin tools dashboard.
 *
 * @param int $courseid Optional course ID to pre-populate the form
 */
function display_dashboard($courseid = 0) {
    global $OUTPUT, $DB;

    echo html_writer::tag('p', get_string('admintoolsdesc', 'aiplacement_modgen'), ['class' => 'alert alert-info']);

    // Database integrity section.
    echo $OUTPUT->heading(get_string('databaseintegrity', 'aiplacement_modgen'), 3);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('integritychecker', 'aiplacement_modgen'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('integritycheckerdesc', 'aiplacement_modgen'), ['class' => 'card-text']);

    // Course selector form.
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => new moodle_url('/ai/placement/modgen/admin_tools.php'),
        'class' => 'form-inline'
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('form-group mr-2');
    echo html_writer::tag('label', get_string('courseid', 'aiplacement_modgen') . ':', ['for' => 'courseid', 'class' => 'mr-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'courseid',
        'id' => 'courseid',
        'class' => 'form-control',
        'placeholder' => get_string('entercourseid', 'aiplacement_modgen'),
        'min' => '1',
        'style' => 'width: 150px;',
        'value' => $courseid > 0 ? $courseid : ''
    ]);
    echo html_writer::end_div();

    // Add a helper link to find course IDs.
    echo html_writer::start_div('form-group mr-2');
    echo html_writer::tag('small',
        html_writer::link(
            new moodle_url('/course/management.php'),
            get_string('findcourseid', 'aiplacement_modgen'),
            ['target' => '_blank', 'class' => 'text-muted']
        ),
        ['class' => 'form-text text-muted']
    );
    echo html_writer::end_div();

    echo html_writer::tag('button', get_string('checkintegrity', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'checkintegrity',
        'class' => 'btn btn-info mr-2'
    ]);
    echo html_writer::tag('button', get_string('fixintegrity', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixintegrity',
        'class' => 'btn btn-warning mr-2'
    ]);
    echo html_writer::tag('button', get_string('cleanup', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'cleanup',
        'class' => 'btn btn-danger mr-2'
    ]);
    echo html_writer::tag('button', get_string('analyzehierarchy', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'analyzehierarchy',
        'class' => 'btn btn-primary'
    ]);

    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Quick hierarchy export section.
    echo $OUTPUT->heading(get_string('quickexport', 'aiplacement_modgen'), 3);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('exporthierarchy', 'aiplacement_modgen'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('exporthierarchydesc', 'aiplacement_modgen'), ['class' => 'card-text']);

    // Export form.
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => new moodle_url('/ai/placement/modgen/admin_tools.php'),
        'class' => 'form-inline'
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'exporthierarchy']);

    echo html_writer::start_div('form-group mr-2');
    echo html_writer::tag('label', get_string('courseid', 'aiplacement_modgen') . ':', ['for' => 'exportcourseid', 'class' => 'mr-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'courseid',
        'id' => 'exportcourseid',
        'class' => 'form-control',
        'placeholder' => get_string('entercourseid', 'aiplacement_modgen'),
        'min' => '1',
        'style' => 'width: 150px;',
        'value' => $courseid > 0 ? $courseid : ''
    ]);
    echo html_writer::end_div();

    echo html_writer::tag('button', get_string('downloadjson', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'format',
        'value' => 'json',
        'class' => 'btn btn-success mr-2'
    ]);
    echo html_writer::tag('button', get_string('downloadhtml', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'format',
        'value' => 'html',
        'class' => 'btn btn-info mr-2'
    ]);
    echo html_writer::tag('button', get_string('downloadtext', 'aiplacement_modgen'), [
        'type' => 'submit',
        'name' => 'format',
        'value' => 'text',
        'class' => 'btn btn-secondary'
    ]);

    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Quick stats section.
    display_statistics();
}

/**
 * Display statistics about the plugin usage.
 */
function display_statistics() {
    global $OUTPUT, $DB;

    echo $OUTPUT->heading(get_string('statistics', 'aiplacement_modgen'), 3);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('pluginstatistics', 'aiplacement_modgen'), ['class' => 'card-title']);

    // Count courses using flexsections.
    $flexcoursescount = $DB->count_records('course', ['format' => 'flexsections']);

    // Count sections created by modgen (rough estimate based on naming patterns).
    $themessql = "SELECT COUNT(*) FROM {course_sections} WHERE name LIKE 'Theme %' OR name LIKE 'Week %'";
    $modgensections = $DB->count_records_sql($themessql);

    echo html_writer::start_tag('dl', ['class' => 'row']);
    echo html_writer::tag('dt', get_string('flexsectionscourses', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $flexcoursescount, ['class' => 'col-sm-6']);
    echo html_writer::tag('dt', get_string('estimatedsections', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $modgensections, ['class' => 'col-sm-6']);
    echo html_writer::end_tag('dl');

    echo html_writer::end_div();
    echo html_writer::end_div();
}

/**
 * Check course integrity for orphaned sections and invalid parents.
 *
 * @param int $courseid Course ID
 * @param bool $fix Whether to fix issues automatically
 * @return array Array with counts of issues found/fixed
 */
function check_course_integrity($courseid, $fix = false) {
    global $DB, $OUTPUT;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    echo html_writer::tag('p',
        get_string('checkingcourse', 'aiplacement_modgen', format_string($course->fullname)),
        ['class' => 'alert alert-info']
    );

    $result = [
        'orphaned' => 0,
        'invalid' => 0,
        'nullparents' => 0,
        'emptyparents' => 0,
        'missingparents' => 0,
        'duplicatesections' => 0,
        'section0withparent' => 0,
        'hasIssues' => false
    ];

    // Check for Section 0 having a parent (should NEVER happen).
    $section0sql = "SELECT cs.*, cfo.value as parentval
                    FROM {course_sections} cs
                    JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                    WHERE cs.course = ?
                      AND cs.section = 0
                      AND cfo.value IS NOT NULL";
    $section0issues = $DB->get_records_sql($section0sql, [$courseid]);

    if (!empty($section0issues)) {
        $result['section0withparent'] = count($section0issues);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            'Section 0 (general section) has a parent value - this should never happen!',
            'error'
        );

        echo html_writer::start_tag('ul');
        foreach ($section0issues as $section) {
            echo html_writer::tag('li',
                'Section 0 "' . format_string($section->name) . '" (ID: ' . $section->id . ') has parent: ' . $section->parentval
            );
        }
        echo html_writer::end_tag('ul');

        if ($fix) {
            foreach ($section0issues as $section) {
                // Delete the parent option for section 0.
                $DB->delete_records('course_format_options', [
                    'sectionid' => $section->id,
                    'name' => 'parent'
                ]);
            }
            echo $OUTPUT->notification('Removed parent from Section 0', 'success');
        }
    }

    // Check for orphaned format options.
    $orphanedsql = "SELECT cfo.*
                    FROM {course_format_options} cfo
                    WHERE cfo.courseid = ?
                      AND cfo.sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = ?)";
    $orphaned = $DB->get_records_sql($orphanedsql, [$courseid, $courseid]);

    if (!empty($orphaned)) {
        $result['orphaned'] = count($orphaned);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            get_string('orphanedoptions', 'aiplacement_modgen', count($orphaned)),
            'warning'
        );

        if ($fix) {
            foreach ($orphaned as $option) {
                $DB->delete_records('course_format_options', ['id' => $option->id]);
            }
            echo $OUTPUT->notification(get_string('fixedorphaned', 'aiplacement_modgen', count($orphaned)), 'success');
        }
    }

    // Check for invalid parent references.
    // Note: cfo.value is stored as TEXT, so we need to cast it to INTEGER for comparison.
    $invalidsql = "SELECT cs.*, cfo.value as parentnum
                   FROM {course_sections} cs
                   JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                   WHERE cs.course = ?
                     AND " . $DB->sql_cast_char2int('cfo.value') . " > 0
                     AND " . $DB->sql_cast_char2int('cfo.value') . " NOT IN (SELECT section FROM {course_sections} WHERE course = ?)";
    $invalidsections = $DB->get_records_sql($invalidsql, [$courseid, $courseid]);

    if (!empty($invalidsections)) {
        $result['invalid'] = count($invalidsections);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            get_string('invalidparents', 'aiplacement_modgen', count($invalidsections)),
            'warning'
        );

        // Show details of invalid sections
        echo html_writer::start_tag('ul');
        foreach ($invalidsections as $section) {
            echo html_writer::tag('li',
                'Section "' . format_string($section->name) . '" (ID: ' . $section->id . ') has invalid parent: ' . $section->parentnum
            );
        }
        echo html_writer::end_tag('ul');

        if ($fix) {
            foreach ($invalidsections as $section) {
                // Set parent to 0 (top level).
                $DB->set_field('course_format_options', 'value', 0, [
                    'sectionid' => $section->id,
                    'name' => 'parent'
                ]);
            }
            echo $OUTPUT->notification(get_string('fixedinvalid', 'aiplacement_modgen', count($invalidsections)), 'success');

            // Rebuild cache after fixing.
            rebuild_course_cache($courseid, false, true);
        }
    }

    // Check for NULL or empty parent values.
    $nullparentsql = "SELECT cs.*, cfo.value as parentval
                      FROM {course_sections} cs
                      JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                      WHERE cs.course = ?
                        AND cs.section > 0
                        AND (cfo.value IS NULL OR cfo.value = '')";
    $nullparents = $DB->get_records_sql($nullparentsql, [$courseid]);

    if (!empty($nullparents)) {
        $result['nullparents'] = count($nullparents);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            count($nullparents) . ' sections with NULL or empty parent values',
            'warning'
        );

        echo html_writer::start_tag('ul');
        foreach ($nullparents as $section) {
            echo html_writer::tag('li',
                'Section "' . format_string($section->name) . '" (ID: ' . $section->id . ') has NULL/empty parent'
            );
        }
        echo html_writer::end_tag('ul');

        if ($fix) {
            foreach ($nullparents as $section) {
                $DB->set_field('course_format_options', 'value', '0', [
                    'sectionid' => $section->id,
                    'name' => 'parent'
                ]);
            }
            echo $OUTPUT->notification('Fixed ' . count($nullparents) . ' NULL/empty parent values', 'success');
        }
    }

    // Check for sections missing parent format option entirely.
    $missingparentsql = "SELECT cs.*
                         FROM {course_sections} cs
                         WHERE cs.course = ?
                           AND cs.section > 0
                           AND NOT EXISTS (
                               SELECT 1 FROM {course_format_options} cfo
                               WHERE cfo.sectionid = cs.id AND cfo.name = 'parent'
                           )";
    $missingparents = $DB->get_records_sql($missingparentsql, [$courseid]);

    if (!empty($missingparents)) {
        $result['missingparents'] = count($missingparents);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            count($missingparents) . ' sections missing parent format option',
            'warning'
        );

        echo html_writer::start_tag('ul');
        foreach ($missingparents as $section) {
            echo html_writer::tag('li',
                'Section #' . $section->section . ' "' . format_string($section->name) . '" (ID: ' . $section->id . ') missing parent option'
            );
        }
        echo html_writer::end_tag('ul');

        if ($fix) {
            foreach ($missingparents as $section) {
                $DB->insert_record('course_format_options', (object)[
                    'courseid' => $courseid,
                    'format' => 'flexsections',
                    'sectionid' => $section->id,
                    'name' => 'parent',
                    'value' => '0'
                ]);
            }
            echo $OUTPUT->notification('Fixed ' . count($missingparents) . ' missing parent options', 'success');
        }
    }

    // Check for duplicate section numbers.
    $duplicatesql = "SELECT section, COUNT(*) as count
                     FROM {course_sections}
                     WHERE course = ?
                     GROUP BY section
                     HAVING COUNT(*) > 1";
    $duplicates = $DB->get_records_sql($duplicatesql, [$courseid]);

    if (!empty($duplicates)) {
        $result['duplicatesections'] = count($duplicates);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            count($duplicates) . ' duplicate section numbers found',
            'error'
        );

        echo html_writer::start_tag('ul');
        foreach ($duplicates as $dup) {
            echo html_writer::tag('li',
                'Section number ' . $dup->section . ' appears ' . $dup->count . ' times'
            );
        }
        echo html_writer::end_tag('ul');

        echo html_writer::tag('p',
            'Duplicate section numbers require manual resolution - automatic fix not safe.',
            ['class' => 'alert alert-danger']
        );
    }

    // Check for circular references (recursive up to 10 levels).
    // PostgreSQL-compatible recursive CTE for circular reference detection.
    // Use CAST AS INTEGER instead of UNSIGNED for cross-database compatibility.
    $circularsql = "WITH RECURSIVE section_tree AS (
                        SELECT cs.id, cs.section, cs.course,
                               CAST(cfo.value AS INTEGER) as parent,
                               cs.section as root_section,
                               1 as depth,
                               CAST(cs.section AS VARCHAR(1000)) as path
                        FROM {course_sections} cs
                        JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                        WHERE cs.course = ? AND cs.section > 0
                        
                        UNION ALL
                        
                        SELECT cs.id, cs.section, cs.course,
                               CAST(cfo.value AS INTEGER) as parent,
                               st.root_section,
                               st.depth + 1,
                               CAST(st.path || '->' || cs.section AS VARCHAR(1000))
                        FROM {course_sections} cs
                        JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                        JOIN section_tree st ON cs.section = st.parent
                        WHERE st.depth < 10 AND st.course = ?
                    )
                    SELECT DISTINCT root_section, path
                    FROM section_tree
                    WHERE section = root_section AND depth > 1";
    
    $circularrefs = $DB->get_records_sql($circularsql, [$courseid, $courseid]);

    if (!empty($circularrefs)) {
        $result['circularrefs'] = count($circularrefs);
        $result['hasIssues'] = true;

        echo $OUTPUT->notification(
            count($circularrefs) . ' sections with circular parent references detected',
            'error'
        );

        echo html_writer::start_tag('ul');
        foreach ($circularrefs as $circular) {
            echo html_writer::tag('li',
                'Circular reference found: ' . $circular->path
            );
        }
        echo html_writer::end_tag('ul');

        echo html_writer::tag('p',
            'Use "Fix Circular References" action from Analyze Hierarchy to resolve.',
            ['class' => 'alert alert-warning']
        );
    }

    // Check for ID vs number confusion (parent values much larger than max section number).
    $maxsectionsql = "SELECT MAX(section) as maxsection FROM {course_sections} WHERE course = ?";
    $maxsection = $DB->get_record_sql($maxsectionsql, [$courseid]);
    
    if ($maxsection) {
        $maxparentval = $maxsection->maxsection * 2; // Threshold: 2x max section number.
        
        $idconfusionsql = "SELECT cs.*, cfo.value as parentval
                           FROM {course_sections} cs
                           JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                           WHERE cs.course = ?
                             AND " . $DB->sql_cast_char2int('cfo.value') . " > ?";
        $idconfusion = $DB->get_records_sql($idconfusionsql, [$courseid, $maxparentval]);

        if (!empty($idconfusion)) {
            $result['idconfusion'] = count($idconfusion);
            $result['hasIssues'] = true;

            echo $OUTPUT->notification(
                count($idconfusion) . ' sections with possible ID/number confusion',
                'warning'
            );

            echo html_writer::start_tag('ul');
            foreach ($idconfusion as $section) {
                echo html_writer::tag('li',
                    'Section "' . format_string($section->name) . '" (ID: ' . $section->id . ') has parent value: ' . $section->parentval . ' (max section number is ' . $maxsection->maxsection . ')'
                );
            }
            echo html_writer::end_tag('ul');

            echo html_writer::tag('p',
                'Parent values should be section numbers (0-' . $maxsection->maxsection . '), not section IDs.',
                ['class' => 'alert alert-info']
            );
        }
    }

    // Check for depth violations (if flexsections format is active).
    if ($course->format === 'flexsections') {
        try {
            $courseformat = course_get_format($courseid);
            if (method_exists($courseformat, 'get_max_section_depth')) {
                $maxdepth = $courseformat->get_max_section_depth();
                
                // Build depth calculation query.
                $depthsql = "WITH RECURSIVE section_depth AS (
                                SELECT cs.id, cs.section, cs.name,
                                       CAST(cfo.value AS UNSIGNED) as parent,
                                       1 as depth
                                FROM {course_sections} cs
                                LEFT JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                                WHERE cs.course = ? AND (cfo.value = '0' OR cfo.value IS NULL)
                                
                                UNION ALL
                                
                                SELECT cs.id, cs.section, cs.name,
                                       CAST(cfo.value AS UNSIGNED) as parent,
                                       sd.depth + 1
                                FROM {course_sections} cs
                                JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                                JOIN section_depth sd ON cs.section = CAST(cfo.value AS UNSIGNED)
                                WHERE sd.depth < 20
                            )
                            SELECT id, section, name, depth
                            FROM section_depth
                            WHERE depth > ?";
                
                $depthviolations = $DB->get_records_sql($depthsql, [$courseid, $maxdepth]);

                if (!empty($depthviolations)) {
                    $result['depthviolations'] = count($depthviolations);
                    $result['hasIssues'] = true;

                    echo $OUTPUT->notification(
                        count($depthviolations) . ' sections exceed maximum depth of ' . $maxdepth,
                        'warning'
                    );

                    echo html_writer::start_tag('ul');
                    foreach ($depthviolations as $section) {
                        echo html_writer::tag('li',
                            'Section "' . format_string($section->name) . '" is at depth ' . $section->depth
                        );
                    }
                    echo html_writer::end_tag('ul');
                }
            }
        } catch (Exception $e) {
            // Silently continue if depth check fails.
        }
    }

    // Check for hidden subsections with no activities.
    $hiddensubsectionssql = "SELECT cs.id, cs.section, cs.name
                             FROM {course_sections} cs
                             JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                             WHERE cs.course = ?
                               AND cs.visible = 0
                               AND cs.section > 0
                               AND " . $DB->sql_cast_char2int('cfo.value') . " > 0
                               AND NOT EXISTS (
                                   SELECT 1 FROM {course_modules} cm WHERE cm.section = cs.id
                               )";
    $hiddensubsections = $DB->get_records_sql($hiddensubsectionssql, [$courseid]);

    if (!empty($hiddensubsections)) {
        $result['hiddensubsections'] = count($hiddensubsections);
        // Not marking as hasIssues since these might be intentional.

        echo $OUTPUT->notification(
            count($hiddensubsections) . ' hidden subsections with no activities found',
            'info'
        );

        echo html_writer::start_tag('ul');
        foreach ($hiddensubsections as $section) {
            echo html_writer::tag('li',
                'Section "' . format_string($section->name) . '" (ID: ' . $section->id . ')'
            );
        }
        echo html_writer::end_tag('ul');

        echo html_writer::tag('p',
            'These can be cleaned up using the "Clean Up" action if they are not needed.',
            ['class' => 'alert alert-info']
        );
    }

    // Display results.
    if (!$result['hasIssues']) {
        echo $OUTPUT->notification(get_string('noissuesfound', 'aiplacement_modgen'), 'success');
    } else if (!$fix) {
        echo html_writer::tag('p', get_string('usefixbutton', 'aiplacement_modgen'), ['class' => 'alert alert-warning']);
    } else {
        // Rebuild cache after all fixes.
        rebuild_course_cache($courseid, false, true);
        echo $OUTPUT->notification('Cache rebuilt', 'info');
    }

    return $result;
}

/**
 * Cleanup orphaned sections in a course.
 *
 * @param int $courseid Course ID
 */
function cleanup_orphaned_sections($courseid) {
    global $DB, $OUTPUT;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    echo html_writer::tag('p',
        get_string('cleaningcourse', 'aiplacement_modgen', format_string($course->fullname)),
        ['class' => 'alert alert-info']
    );

    // Find hidden sections with no activities.
    $sql = "SELECT cs.id, cs.section, cs.name
            FROM {course_sections} cs
            WHERE cs.course = ?
              AND cs.visible = 0
              AND cs.section > 0
              AND NOT EXISTS (
                  SELECT 1 FROM {course_modules} cm WHERE cm.section = cs.id
              )";
    $sections = $DB->get_records_sql($sql, [$courseid]);

    if (empty($sections)) {
        echo $OUTPUT->notification(get_string('nosectionstoclean', 'aiplacement_modgen'), 'info');
        return;
    }

    $count = 0;
    foreach ($sections as $section) {
        // Delete format options first.
        $DB->delete_records('course_format_options', ['sectionid' => $section->id]);
        // Delete section.
        $DB->delete_records('course_sections', ['id' => $section->id]);
        $count++;
    }

    echo $OUTPUT->notification(get_string('sectionsdeleted', 'aiplacement_modgen', $count), 'success');

    // Rebuild cache.
    rebuild_course_cache($courseid, false, true);
}

/**
 * Display detailed hierarchy analysis for a course.
 *
 * @param int $courseid Course ID
 */
function display_hierarchy_analysis($courseid) {
    global $DB, $OUTPUT;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    echo html_writer::tag('p',
        get_string('analyzingcourse', 'aiplacement_modgen', format_string($course->fullname)),
        ['class' => 'alert alert-info']
    );

    // Build complete section hierarchy.
    // SECURITY: Using parameter binding (?) to prevent SQL injection.
    $sql = "SELECT cs.id, cs.course, cs.section, cs.name, cs.visible, cs.sequence,
                   cfo.value as parent
            FROM {course_sections} cs
            LEFT JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
            WHERE cs.course = ?
            ORDER BY cs.section ASC";
    $sections = $DB->get_records_sql($sql, [$courseid]);

    // Index sections by section number for O(1) lookups.
    $sectionsbynum = [];
    foreach ($sections as $section) {
        if (empty($section->sequence)) {
            $section->activitycount = 0;
        } else {
            $section->activitycount = count(explode(',', $section->sequence));
        }
        // Section 0 should NEVER have a parent.
        if ($section->section == 0) {
            $section->parent = null;
        } else {
            // Preserve actual parent value (including NULL) for accurate orphan detection.
            // Default to '0' only for display purposes later.
            $section->parent = $section->parent !== null ? $section->parent : '0';
        }
        $sectionsbynum[$section->section] = $section;
    }

    // Pre-calculate all depths once (cached).
    $depthcache = [];
    foreach ($sections as $section) {
        $depthcache[$section->section] = calculate_section_depth_cached($section->section, $sectionsbynum, $depthcache);
    }

    // Build tree structure.
    $tree = build_section_tree($sections);

    // Display tree visualization.
    echo $OUTPUT->heading(get_string('hierarchytree', 'aiplacement_modgen'), 4);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::start_tag('pre', ['style' => 'font-family: monospace; background: #f5f5f5; padding: 15px; border-radius: 5px;']);
    
    // Display section 0 at the root (it's special and has no parent).
    if (isset($sectionsbynum[0])) {
        $section0 = $sectionsbynum[0];
        echo htmlspecialchars("Section 0: " . format_string($section0->name) . " (ID: {$section0->id}, Activities: {$section0->activitycount}) [GENERAL SECTION]\n");
    }
    
    // Display top-level sections and their children.
    display_tree_recursive($tree, $sectionsbynum, '0', []);
    echo html_writer::end_tag('pre');
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Display detailed section table.
    echo $OUTPUT->heading(get_string('sectiondetails', 'aiplacement_modgen'), 4);
    echo html_writer::start_div('table-responsive');
    echo html_writer::start_tag('table', ['class' => 'table table-striped table-bordered']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Section #');
    echo html_writer::tag('th', 'Name');
    echo html_writer::tag('th', 'Parent');
    echo html_writer::tag('th', 'Depth');
    echo html_writer::tag('th', 'Visible');
    echo html_writer::tag('th', 'Activities');
    echo html_writer::tag('th', 'DB ID');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    // Pre-detect circular references for each section.
    $circularSections = [];
    foreach ($sections as $section) {
        if ($section->section == 0 || $section->parent === '0') {
            continue;
        }
        
        $visited = [];
        $current = $section;
        $loopcount = 0;
        
        while ($current && $current->parent !== '0' && $loopcount < 20) {
            if (isset($visited[$current->section])) {
                $circularSections[$section->section] = true;
                break;
            }
            $visited[$current->section] = true;
            
            $parentnum = $current->parent;
            if (!isset($sectionsbynum[$parentnum])) {
                break;
            }
            $current = $sectionsbynum[$parentnum];
            $loopcount++;
        }
    }

    foreach ($sections as $section) {
        $depth = $depthcache[$section->section];
        $rowclass = '';
        $issues = [];
        
        // Check for section 0 with parent.
        if ($section->section == 0 && $section->parent !== null) {
            $rowclass = 'table-danger';
            $issues[] = 'Section 0 should not have a parent';
        }
        // Check for circular reference.
        else if (isset($circularSections[$section->section])) {
            $rowclass = 'table-danger';
            $issues[] = 'Circular parent reference';
        }
        // Check for orphaned parent (parent points to non-existent section).
        else if ($section->section != 0 && $section->parent !== '0' && $section->parent !== null) {
            if (is_numeric($section->parent)) {
                $parentnum = (int)$section->parent;
                if (!isset($sectionsbynum[$parentnum])) {
                    $rowclass = 'table-danger';
                    $issues[] = 'Parent does not exist';
                }
            } else {
                // Non-numeric parent value.
                $rowclass = 'table-danger';
                $issues[] = 'Invalid parent value';
            }
        }
        // Check for hidden subsection with no activities.
        else if ($section->visible == 0 && $section->activitycount == 0 && $section->parent !== '0') {
            $rowclass = 'table-warning';
            $issues[] = 'Hidden, no activities';
        }

        echo html_writer::start_tag('tr', $rowclass ? ['class' => $rowclass] : []);
        echo html_writer::tag('td', $section->section);
        $nameCell = format_string($section->name);
        if (!empty($issues)) {
            $nameCell .= ' <span class="badge badge-danger">' . implode(', ', $issues) . '</span>';
        }
        echo html_writer::tag('td', $nameCell);
        echo html_writer::tag('td', $section->parent === null ? '(none)' : $section->parent);
        echo html_writer::tag('td', $depth);
        echo html_writer::tag('td', $section->visible ? 'Yes' : 'No');
        echo html_writer::tag('td', $section->activitycount);
        echo html_writer::tag('td', $section->id);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    // Summary statistics.
    $stats = [
        'total' => count($sections),
        'toplevel' => 0,
        'maxdepth' => 0,
        'hidden' => 0,
        'orphaned' => 0,
        'circular' => 0,
        'section0withparent' => 0
    ];

    // Check if section 0 has a parent value.
    if (isset($sectionsbynum[0])) {
        $section0 = $sectionsbynum[0];
        $section0parent = $DB->get_record('course_format_options', [
            'sectionid' => $section0->id,
            'name' => 'parent'
        ]);
        if ($section0parent) {
            $stats['section0withparent'] = 1;
        }
    }

    foreach ($sections as $section) {
        if ($section->parent === '0') {
            $stats['toplevel']++;
        }
        if ($section->visible == 0) {
            $stats['hidden']++;
        }
        // Orphaned: parent value points to non-existent section (excluding top-level '0').
        if ($section->section != 0 && $section->parent !== '0' && $section->parent !== null) {
            // Check if parent is numeric and if that section exists.
            if (is_numeric($section->parent)) {
                $parentnum = (int)$section->parent;
                if (!isset($sectionsbynum[$parentnum])) {
                    $stats['orphaned']++;
                }
            } else {
                // Non-numeric parent value is also invalid/orphaned.
                $stats['orphaned']++;
            }
        }
        $depth = $depthcache[$section->section];
        if ($depth > $stats['maxdepth']) {
            $stats['maxdepth'] = $depth;
        }
    }

    // Check for circular references.
    foreach ($sections as $section) {
        if ($section->section == 0 || $section->parent === '0') {
            continue;
        }
        
        $visited = [];
        $current = $section;
        $loopcount = 0;
        
        while ($current && $current->parent !== '0' && $loopcount < 20) {
            if (isset($visited[$current->section])) {
                // Found a circular reference.
                $stats['circular']++;
                break;
            }
            $visited[$current->section] = true;
            
            $parentnum = $current->parent;
            if (!isset($sectionsbynum[$parentnum])) {
                break;
            }
            $current = $sectionsbynum[$parentnum];
            $loopcount++;
        }
    }

    echo html_writer::start_div('card mt-3 mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('hierarchystats', 'aiplacement_modgen'), ['class' => 'card-title']);
    echo html_writer::start_tag('dl', ['class' => 'row']);
    echo html_writer::tag('dt', get_string('totalsections', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $stats['total'], ['class' => 'col-sm-6']);
    echo html_writer::tag('dt', get_string('toplevelsections', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $stats['toplevel'], ['class' => 'col-sm-6']);
    echo html_writer::tag('dt', get_string('maxdepth', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $stats['maxdepth'], ['class' => 'col-sm-6']);
    echo html_writer::tag('dt', get_string('hiddensections', 'aiplacement_modgen'), ['class' => 'col-sm-6']);
    echo html_writer::tag('dd', $stats['hidden'], ['class' => 'col-sm-6']);
    if ($stats['orphaned'] > 0) {
        echo html_writer::tag('dt', get_string('orphanedsections', 'aiplacement_modgen'), ['class' => 'col-sm-6 text-danger']);
        echo html_writer::tag('dd', $stats['orphaned'], ['class' => 'col-sm-6 text-danger font-weight-bold']);
    }
    if ($stats['circular'] > 0) {
        echo html_writer::tag('dt', get_string('circularreferences', 'aiplacement_modgen'), ['class' => 'col-sm-6 text-danger']);
        echo html_writer::tag('dd', $stats['circular'], ['class' => 'col-sm-6 text-danger font-weight-bold']);
    }
    if ($stats['section0withparent'] > 0) {
        echo html_writer::tag('dt', 'Section 0 has parent', ['class' => 'col-sm-6 text-danger']);
        echo html_writer::tag('dd', 'Yes (INVALID)', ['class' => 'col-sm-6 text-danger font-weight-bold']);
    }
    echo html_writer::end_tag('dl');
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Action buttons.
    echo html_writer::start_div('mt-3');
    
    // Export buttons.
    echo html_writer::tag('p', get_string('exportoptions', 'aiplacement_modgen'), ['class' => 'font-weight-bold']);
    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/admin_tools.php', [
            'action' => 'exporthierarchy',
            'courseid' => $courseid,
            'format' => 'json',
            'sesskey' => sesskey()
        ]),
        get_string('downloadjson', 'aiplacement_modgen'),
        ['class' => 'btn btn-success mr-2']
    );
    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/admin_tools.php', [
            'action' => 'exporthierarchy',
            'courseid' => $courseid,
            'format' => 'html',
            'sesskey' => sesskey()
        ]),
        get_string('downloadhtml', 'aiplacement_modgen'),
        ['class' => 'btn btn-info mr-2']
    );
    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/admin_tools.php', [
            'action' => 'exporthierarchy',
            'courseid' => $courseid,
            'format' => 'text',
            'sesskey' => sesskey()
        ]),
        get_string('downloadtext', 'aiplacement_modgen'),
        ['class' => 'btn btn-secondary mr-2']
    );

    echo html_writer::tag('br', '');
    echo html_writer::tag('br', '');
    
    // Repair buttons (only if issues found).
    if ($stats['orphaned'] > 0 || $stats['circular'] > 0 || $stats['section0withparent'] > 0) {
        echo html_writer::tag('p', get_string('repairactions', 'aiplacement_modgen'), ['class' => 'font-weight-bold mt-3']);
        echo html_writer::link(
            new moodle_url('/ai/placement/modgen/admin_tools.php', [
                'action' => 'fixcircular',
                'courseid' => $courseid,
                'sesskey' => sesskey()
            ]),
            get_string('fixcircular', 'aiplacement_modgen'),
            ['class' => 'btn btn-warning mr-2']
        );
    }

    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/admin_tools.php', [
            'action' => 'flattenhierarchy',
            'courseid' => $courseid,
            'sesskey' => sesskey()
        ]),
        get_string('flattenhierarchy', 'aiplacement_modgen'),
        ['class' => 'btn btn-danger mr-2']
    );

    echo html_writer::link(
        new moodle_url('/ai/placement/modgen/admin_tools.php'),
        get_string('backtomainpage', 'aiplacement_modgen'),
        ['class' => 'btn btn-secondary']
    );

    echo html_writer::end_div();
}

/**
 * Build a tree structure from flat section list.
 *
 * @param array $sections Array of section objects
 * @return array Tree structure with parent => children mapping
 */
function build_section_tree($sections) {
    $tree = [];
    foreach ($sections as $section) {
        $parent = $section->parent;
        if (!isset($tree[$parent])) {
            $tree[$parent] = [];
        }
        $tree[$parent][] = $section->section;
    }
    return $tree;
}

/**
 * Build a tree structure from export data array.
 *
 * @param array $sections Array of section arrays from export
 * @return array Tree structure with parent => children mapping
 */
function build_section_tree_from_export($sections) {
    $tree = [];
    foreach ($sections as $section) {
        $parent = $section['parent'];
        if (!isset($tree[$parent])) {
            $tree[$parent] = [];
        }
        $tree[$parent][] = $section['section'];
    }
    return $tree;
}

/**
 * Display tree recursively with indentation.
 *
 * @param array $tree Tree structure
 * @param array $sectionsbynum Section objects indexed by section number
 * @param string $parent Current parent section number
 * @param array $prefix Prefix characters for tree drawing
 * @param array $visited Sections already visited in current path (for circular detection)
 */
function display_tree_recursive($tree, $sectionsbynum, $parent, $prefix, $visited = []) {
    if (!isset($tree[$parent])) {
        return;
    }

    $children = $tree[$parent];
    $childcount = count($children);

    foreach ($children as $index => $sectionnum) {
        // Check for circular reference - if we've already visited this section in current path, stop.
        if (isset($visited[$sectionnum])) {
            $islast = ($index === $childcount - 1);
            $connector = $islast ? '└── ' : '├── ';
            echo htmlspecialchars(implode('', $prefix) . $connector . "⚠ CIRCULAR REFERENCE: Section {$sectionnum} (already in path)\n");
            continue;
        }

        $islast = ($index === $childcount - 1);
        $connector = $islast ? '└── ' : '├── ';
        
        // O(1) lookup using indexed array.
        if (!isset($sectionsbynum[$sectionnum])) {
            echo htmlspecialchars(implode('', $prefix) . $connector . "⚠ ORPHANED: Section {$sectionnum} (does not exist)\n");
            continue;
        }
        $section = $sectionsbynum[$sectionnum];

        // Build display line.
        $line = implode('', $prefix) . $connector;
        $line .= "Section {$section->section}: " . format_string($section->name);
        $line .= " (ID: {$section->id}, Activities: {$section->activitycount})";

        // Check for issues.
        if ($section->parent !== '0' && !isset($sectionsbynum[$section->parent])) {
            $line .= " ⚠ ORPHANED PARENT";
        }
        if ($section->visible == 0) {
            $line .= " [HIDDEN]";
        }

        echo htmlspecialchars($line) . "\n";

        // Add current section to visited list for this path.
        $newvisited = $visited;
        $newvisited[$sectionnum] = true;

        // Recurse for children.
        $newprefix = $prefix;
        $newprefix[] = $islast ? '    ' : '│   ';
        display_tree_recursive($tree, $sectionsbynum, $sectionnum, $newprefix, $newvisited);
    }
}

/**
 * Calculate depth of a section in the hierarchy (with caching).
 *
 * @param int $sectionnum Section number
 * @param array $sectionsbynum All sections indexed by section number
 * @param array $cache Depth cache (pass by reference)
 * @return int Depth (1 = top level)
 */
function calculate_section_depth_cached($sectionnum, $sectionsbynum, &$cache) {
    // Return cached value if available.
    if (isset($cache[$sectionnum])) {
        return $cache[$sectionnum];
    }

    if (!isset($sectionsbynum[$sectionnum])) {
        $cache[$sectionnum] = 1;
        return 1;
    }

    $section = $sectionsbynum[$sectionnum];

    if ($section->parent === '0' || $section->parent === null) {
        $cache[$sectionnum] = 1;
        return 1;
    }

    // Prevent infinite recursion.
    $visited = [];
    $depth = 1;
    $current = $section;

    while ($current && $current->parent !== '0' && $depth < 20) {
        if (isset($visited[$current->section])) {
            $cache[$sectionnum] = $depth; // Circular reference detected.
            return $depth;
        }
        $visited[$current->section] = true;

        $parentnum = $current->parent;
        if (!isset($sectionsbynum[$parentnum])) {
            break;
        }
        $current = $sectionsbynum[$parentnum];
        $depth++;
    }

    $cache[$sectionnum] = $depth;
    return $depth;
}

/**
 * Calculate depth of a section in the hierarchy.
 *
 * @param int $sectionnum Section number
 * @param array $sections All sections
 * @return int Depth (1 = top level)
 * @deprecated Use calculate_section_depth_cached() for better performance
 */
function calculate_section_depth($sectionnum, $sections) {
    $section = null;
    foreach ($sections as $s) {
        if ($s->section == $sectionnum) {
            $section = $s;
            break;
        }
    }

    if (!$section || $section->parent === '0' || $section->parent === null) {
        return 1;
    }

    // Prevent infinite recursion.
    $visited = [];
    $depth = 1;
    $current = $section;

    while ($current && $current->parent !== '0' && $depth < 20) {
        if (isset($visited[$current->section])) {
            return $depth; // Circular reference detected.
        }
        $visited[$current->section] = true;

        $parentnum = $current->parent;
        $current = null;
        foreach ($sections as $s) {
            if ($s->section == $parentnum) {
                $current = $s;
                break;
            }
        }
        $depth++;
    }

    return $depth;
}

/**
 * Check if a section's parent exists.
 *
 * @param string $parentnum Parent section number
 * @param array $sections All sections
 * @return bool True if parent exists
 */
function section_parent_exists($parentnum, $sections) {
    if ($parentnum === '0') {
        return true;
    }

    foreach ($sections as $section) {
        if ($section->section == $parentnum) {
            return true;
        }
    }
    return false;
}

/**
 * Export hierarchy data in various formats.
 *
 * @param int $courseid Course ID
 * @param string $format Export format (json, html, text)
 */
function export_hierarchy_data($courseid, $format) {
    global $DB;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    // Build complete section hierarchy with format options.
    $sql = "SELECT cs.id, cs.course, cs.section, cs.name, cs.summary, cs.summaryformat,
                   cs.visible, cs.sequence, cs.availability
            FROM {course_sections} cs
            WHERE cs.course = ?
            ORDER BY cs.section ASC";
    $sections = $DB->get_records_sql($sql, [$courseid]);

    // Get all format options for these sections.
    $formatoptions = [];
    if (!empty($sections)) {
        list($insql, $params) = $DB->get_in_or_equal(array_keys($sections));
        $optionssql = "SELECT * FROM {course_format_options}
                       WHERE sectionid $insql
                       ORDER BY sectionid, name";
        $options = $DB->get_records_sql($optionssql, $params);
        
        foreach ($options as $option) {
            if (!isset($formatoptions[$option->sectionid])) {
                $formatoptions[$option->sectionid] = [];
            }
            $formatoptions[$option->sectionid][$option->name] = $option->value;
        }
    }

    // Count activities and calculate depth for each section.
    $sectionsbynum = [];
    foreach ($sections as $section) {
        if (empty($section->sequence)) {
            $section->activitycount = 0;
        } else {
            $section->activitycount = count(explode(',', $section->sequence));
        }
        $section->formatoptions = isset($formatoptions[$section->id]) ? $formatoptions[$section->id] : [];
        $section->parent = isset($section->formatoptions['parent']) ? $section->formatoptions['parent'] : '0';
        $sectionsbynum[$section->section] = $section;
    }

    // Pre-calculate depths once.
    $depthcache = [];
    foreach ($sections as $section) {
        $depthcache[$section->section] = calculate_section_depth_cached($section->section, $sectionsbynum, $depthcache);
    }

    // Run diagnostics.
    $issues = [];
    
    // Check for orphaned parents.
    foreach ($sections as $section) {
        if ($section->parent !== '0' && !isset($sectionsbynum[$section->parent])) {
            $issues[] = [
                'type' => 'orphaned_parent',
                'section' => $section->section,
                'name' => $section->name,
                'parent' => $section->parent,
                'message' => "Section {$section->section} has parent {$section->parent} which does not exist"
            ];
        }
    }

    // Check for circular references (simplified).
    foreach ($sections as $section) {
        if ($section->parent === '0') {
            continue;
        }
        
        $visited = [];
        $current = $section;
        $depth = 0;
        
        while ($current && $current->parent !== '0' && $depth < 20) {
            if (isset($visited[$current->section])) {
                $issues[] = [
                    'type' => 'circular_reference',
                    'section' => $section->section,
                    'name' => $section->name,
                    'message' => "Section {$section->section} has a circular parent reference"
                ];
                break;
            }
            $visited[$current->section] = true;
            
            $parentnum = $current->parent;
            if (!isset($sectionsbynum[$parentnum])) {
                break;
            }
            $current = $sectionsbynum[$parentnum];
            $depth++;
        }
    }

    // Export metadata.
    $export = [
        'course' => [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'format' => $course->format,
            'numsections' => $course->numsections ?? null
        ],
        'exported' => date('Y-m-d H:i:s'),
        'sections' => [],
        'issues' => $issues
    ];

    // Add sections with their complete data.
    foreach ($sections as $section) {
        $depth = $depthcache[$section->section];
        
        $export['sections'][] = [
            'id' => $section->id,
            'section' => $section->section,
            'name' => $section->name,
            'summary' => $section->summary,
            'visible' => $section->visible,
            'activitycount' => $section->activitycount,
            'parent' => $section->parent,
            'depth' => $depth,
            'formatoptions' => $section->formatoptions,
            'sequence' => $section->sequence
        ];
    }

    // Generate output based on format.
    $timestamp = date('Ymd_His');
    $filename = "hierarchy_export_course{$courseid}_{$timestamp}";

    switch ($format) {
        case 'json':
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            echo json_encode($export, JSON_PRETTY_PRINT);
            break;

        case 'html':
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.html"');
            echo generate_html_export($export, $sectionsbynum);
            break;

        case 'text':
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
            echo generate_text_export($export, $sectionsbynum);
            break;

        default:
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            echo json_encode($export, JSON_PRETTY_PRINT);
    }
}

/**
 * Generate HTML export format.
 *
 * @param array $export Export data
 * @param array $sectionsbynum Section objects indexed by section number
 * @return string HTML content
 */
function generate_html_export($export, $sectionsbynum) {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hierarchy Export - ' . htmlspecialchars($export['course']['fullname']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }
        h1 { color: #333; border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
        h2 { color: #0066cc; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #0066cc; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .error { color: #d00; font-weight: bold; }
        .warning { color: #f60; }
        .success { color: #090; }
        .tree { font-family: monospace; background: #f9f9f9; padding: 15px; border-radius: 5px; white-space: pre; }
        .metadata { background: #e8f4f8; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Course Hierarchy Export</h1>
    
    <div class="metadata">
        <h3>Course Information</h3>
        <p><strong>Course ID:</strong> ' . $export['course']['id'] . '</p>
        <p><strong>Full Name:</strong> ' . htmlspecialchars($export['course']['fullname']) . '</p>
        <p><strong>Short Name:</strong> ' . htmlspecialchars($export['course']['shortname']) . '</p>
        <p><strong>Format:</strong> ' . htmlspecialchars($export['course']['format']) . '</p>
        <p><strong>Exported:</strong> ' . $export['exported'] . '</p>
    </div>';

    // Issues section.
    if (!empty($export['issues'])) {
        $html .= '<h2 class="error">⚠ Issues Found (' . count($export['issues']) . ')</h2>';
        $html .= '<table>';
        $html .= '<thead><tr><th>Type</th><th>Section</th><th>Name</th><th>Message</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($export['issues'] as $issue) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($issue['type']) . '</td>';
            $html .= '<td>' . $issue['section'] . '</td>';
            $html .= '<td>' . htmlspecialchars($issue['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($issue['message']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p class="success">✓ No issues found</p>';
    }

    // Tree visualization.
    $html .= '<h2>Hierarchy Tree</h2>';
    $html .= '<div class="tree">';
    $tree = build_section_tree_from_export($export['sections']);
    ob_start();
    display_tree_recursive($tree, $sectionsbynum, '0', []);
    $html .= htmlspecialchars(ob_get_clean());
    $html .= '</div>';

    // Section table.
    $html .= '<h2>Section Details</h2>';
    $html .= '<table>';
    $html .= '<thead><tr><th>Section #</th><th>Name</th><th>Parent</th><th>Depth</th><th>Visible</th><th>Activities</th><th>DB ID</th></tr></thead>';
    $html .= '<tbody>';
    foreach ($export['sections'] as $section) {
        $html .= '<tr>';
        $html .= '<td>' . $section['section'] . '</td>';
        $html .= '<td>' . htmlspecialchars($section['name']) . '</td>';
        $html .= '<td>' . $section['parent'] . '</td>';
        $html .= '<td>' . $section['depth'] . '</td>';
        $html .= '<td>' . ($section['visible'] ? 'Yes' : 'No') . '</td>';
        $html .= '<td>' . $section['activitycount'] . '</td>';
        $html .= '<td>' . $section['id'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '</div></body></html>';
    return $html;
}

/**
 * Generate text export format.
 *
 * @param array $export Export data
 * @param array $sectionsbynum Section objects indexed by section number
 * @return string Text content
 */
function generate_text_export($export, $sectionsbynum) {
    $text = "===================================================================\n";
    $text .= "COURSE HIERARCHY EXPORT\n";
    $text .= "===================================================================\n\n";

    $text .= "Course: {$export['course']['fullname']}\n";
    $text .= "Course ID: {$export['course']['id']}\n";
    $text .= "Format: {$export['course']['format']}\n";
    $text .= "Exported: {$export['exported']}\n\n";

    // Issues.
    if (!empty($export['issues'])) {
        $text .= "-------------------------------------------------------------------\n";
        $text .= "ISSUES FOUND (" . count($export['issues']) . ")\n";
        $text .= "-------------------------------------------------------------------\n\n";
        foreach ($export['issues'] as $issue) {
            $text .= "* {$issue['type']}: Section {$issue['section']} - {$issue['name']}\n";
            $text .= "  {$issue['message']}\n\n";
        }
    } else {
        $text .= "✓ No issues found\n\n";
    }

    // Tree.
    $text .= "-------------------------------------------------------------------\n";
    $text .= "HIERARCHY TREE\n";
    $text .= "-------------------------------------------------------------------\n\n";
    $tree = build_section_tree_from_export($export['sections']);
    ob_start();
    display_tree_recursive($tree, $sectionsbynum, '0', []);
    $text .= ob_get_clean();

    // Section table.
    $text .= "\n-------------------------------------------------------------------\n";
    $text .= "SECTION DETAILS\n";
    $text .= "-------------------------------------------------------------------\n\n";
    $text .= sprintf("%-8s %-40s %-8s %-6s %-8s %-10s %-8s\n", 
                     "Section", "Name", "Parent", "Depth", "Visible", "Activities", "DB ID");
    $text .= str_repeat("-", 100) . "\n";
    
    foreach ($export['sections'] as $section) {
        $name = substr($section['name'], 0, 38);
        $text .= sprintf("%-8s %-40s %-8s %-6s %-8s %-10s %-8s\n",
                        $section['section'],
                        $name,
                        $section['parent'],
                        $section['depth'],
                        $section['visible'] ? 'Yes' : 'No',
                        $section['activitycount'],
                        $section['id']);
    }

    $text .= "\n===================================================================\n";
    $text .= "END OF EXPORT\n";
    $text .= "===================================================================\n";

    return $text;
}

/**
 * Fix circular parent references in a course.
 *
 * @param int $courseid Course ID
 */
function fix_circular_references($courseid) {
    global $DB, $OUTPUT;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    echo html_writer::tag('p',
        get_string('fixingcircularcourse', 'aiplacement_modgen', format_string($course->fullname)),
        ['class' => 'alert alert-info']
    );

    // Get all sections.
    $sql = "SELECT cs.id, cs.course, cs.section, cs.name, cfo.value as parent
            FROM {course_sections} cs
            LEFT JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
            WHERE cs.course = ?
            ORDER BY cs.section ASC";
    $sections = $DB->get_records_sql($sql, [$courseid]);

    $fixed = 0;
    $transaction = $DB->start_delegated_transaction();

    try {
        // First, fix section 0 if it has a parent.
        if (isset($sectionsbynum[0])) {
            $section0 = $sectionsbynum[0];
            $section0parent = $DB->get_record('course_format_options', [
                'sectionid' => $section0->id,
                'name' => 'parent'
            ]);
            if ($section0parent) {
                $DB->delete_records('course_format_options', [
                    'sectionid' => $section0->id,
                    'name' => 'parent'
                ]);
                $fixed++;
                echo html_writer::tag('p',
                    'Removed invalid parent from Section 0',
                    ['class' => 'text-success']
                );
            }
        }

        foreach ($sections as $section) {
            if (!$section->parent || $section->parent === '0') {
                continue;
            }

            // Check for circular reference.
            $visited = [];
            $current = $section;
            $depth = 0;
            $hascircular = false;

            while ($current && $current->parent !== '0' && $depth < 20) {
                if (isset($visited[$current->section])) {
                    $hascircular = true;
                    break;
                }
                $visited[$current->section] = true;

                $parentnum = $current->parent;
                $current = null;
                foreach ($sections as $s) {
                    if ($s->section == $parentnum) {
                        $current = $s;
                        break;
                    }
                }
                $depth++;
            }

            if ($hascircular) {
                // Break the cycle by setting this section to top level.
                $DB->set_field('course_format_options', 'value', '0', [
                    'sectionid' => $section->id,
                    'name' => 'parent'
                ]);
                $fixed++;
                
                echo html_writer::tag('p',
                    'Fixed circular reference for section "' . format_string($section->name) . '" (ID: ' . $section->id . ')',
                    ['class' => 'text-success']
                );
            }
        }

        $transaction->allow_commit();

        if ($fixed > 0) {
            echo $OUTPUT->notification(get_string('circularfixed', 'aiplacement_modgen', $fixed), 'success');
            rebuild_course_cache($courseid, false, true);
        } else {
            echo $OUTPUT->notification(get_string('nocircularfound', 'aiplacement_modgen'), 'info');
        }

    } catch (Exception $e) {
        $transaction->rollback($e);
        echo $OUTPUT->notification(get_string('circularfixerror', 'aiplacement_modgen', $e->getMessage()), 'error');
    }
}

/**
 * Flatten all sections to top level (nuclear option).
 *
 * @param int $courseid Course ID
 */
function flatten_hierarchy_to_toplevel($courseid) {
    global $DB, $OUTPUT;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

    echo html_writer::tag('p',
        get_string('flatteningcourse', 'aiplacement_modgen', format_string($course->fullname)),
        ['class' => 'alert alert-warning']
    );

    $transaction = $DB->start_delegated_transaction();

    try {
        // Set all parent values to '0'.
        $sql = "UPDATE {course_format_options}
                SET value = '0'
                WHERE courseid = ?
                  AND name = 'parent'
                  AND value != '0'";
        
        $count = $DB->execute($sql, [$courseid]);

        $transaction->allow_commit();

        echo $OUTPUT->notification(get_string('hierarchyflattened', 'aiplacement_modgen'), 'success');
        rebuild_course_cache($courseid, false, true);

    } catch (Exception $e) {
        $transaction->rollback($e);
        echo $OUTPUT->notification(get_string('flattenerror', 'aiplacement_modgen', $e->getMessage()), 'error');
    }
}
