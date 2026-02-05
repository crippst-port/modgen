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
 * Provides secure access to:
 * - PHPUnit test execution
 * - Database integrity checking
 * - Cleanup utilities
 * - Performance monitoring
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

// Handle actions.
if ($action && confirm_sesskey()) {
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
                        'Re-check',
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
                    'Re-check Integrity',
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
                    'Re-check Integrity',
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

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'checkintegrity',
        'class' => 'btn btn-info mr-2'
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixintegrity',
        'class' => 'btn btn-warning mr-2'
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'cleanup',
        'class' => 'btn btn-danger'
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
        'hasIssues' => false
    ];

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
                'Section "' . format_string($section->name) . '" (ID: ' . $section->id . ') missing parent option'
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
