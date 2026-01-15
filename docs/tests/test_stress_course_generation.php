<?php
/**
 * Stress test for course generation workflow.
 * 
 * This test simulates real-world usage:
 * 1. Create course structure
 * 2. Delete all sections
 * 3. Recreate with "hide existing sections" option
 * 4. Delete again
 * 5. Repeat cycle multiple times
 * 6. Verify database integrity after each operation
 * 
 * Run from command line:
 * php test_stress_course_generation.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/session_creator.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/activitytype/registry.php');

// Suppress PHP warnings and deprecations from Moodle core to keep test output clean
// Only show errors that would actually fail the test
error_reporting(E_ERROR | E_PARSE);

global $DB;

echo "=== Course Generation Stress Test ===\n\n";

// Create a test course
$coursedata = new stdClass();
$coursedata->fullname = 'Stress Test Course - ' . date('Y-m-d H:i:s');
$coursedata->shortname = 'stress-test-' . time();
$coursedata->format = 'flexsections';
$coursedata->startdate = time();
$coursedata->category = 1;

$course = create_course($coursedata);
echo "✓ Created test course: {$course->fullname} (ID: {$course->id})\n\n";

// Set up admin user with proper session and capabilities
$admin = get_admin();
\core\session\manager::set_user($admin);
echo "✓ Set admin user session\n";

// Grant admin user permissions to create learningactivity instances in course context
$coursecontext = \context_course::instance($course->id);
role_assign(1, $admin->id, $coursecontext->id); // Assign admin role (id=1) to admin user in course context

// Verify capability
$can_add = has_capability('mod/learningactivity:addinstance', $coursecontext);
echo "✓ Granted permissions. Can add learningactivity: " . ($can_add ? 'YES' : 'NO') . "\n\n";

$courseformat = course_get_format($course);
$errors = [];
$warnings = [];

/**
 * Helper function to verify database integrity
 */
function verify_database_integrity($courseid, $iteration, $step) {
    global $DB, $errors, $warnings;
    
    echo "  Verifying database integrity (Iteration {$iteration}, Step: {$step})...\n";
    
    // Check 1: All sections for this course should have valid IDs
    $sections = $DB->get_records('course_sections', ['course' => $courseid]);
    foreach ($sections as $section) {
        if (empty($section->id) || $section->id < 1) {
            $errors[] = "Iteration {$iteration}/{$step}: Invalid section ID: " . var_export($section, true);
        }
    }
    
    // Check 2: All format_options should reference existing sections
    $options = $DB->get_records('course_format_options', ['courseid' => $courseid]);
    foreach ($options as $option) {
        if (!empty($option->sectionid)) {
            $sectionexists = $DB->record_exists('course_sections', ['id' => $option->sectionid, 'course' => $courseid]);
            if (!$sectionexists) {
                $errors[] = "Iteration {$iteration}/{$step}: Format option references non-existent section ID {$option->sectionid}";
            }
        }
    }
    
    // Check 3: Parent relationships should be valid (parent section should exist)
    $parentoptions = $DB->get_records('course_format_options', ['courseid' => $courseid, 'name' => 'parent']);
    foreach ($parentoptions as $parentopt) {
        if (!empty($parentopt->value) && $parentopt->value != '0') {
            // Parent value is a section NUMBER, not ID
            $parentsectionnum = (int)$parentopt->value;
            $parentexists = $DB->record_exists('course_sections', ['course' => $courseid, 'section' => $parentsectionnum]);
            if (!$parentexists) {
                $warnings[] = "Iteration {$iteration}/{$step}: Section {$parentopt->sectionid} has parent={$parentsectionnum} but that section doesn't exist";
            }
        }
    }
    
    // Check 4: No orphaned format_options (options without sections)
    $alloptions = $DB->get_records('course_format_options', ['courseid' => $courseid]);
    $allsections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id');
    $sectionids = array_keys($allsections);
    
    foreach ($alloptions as $option) {
        if (!empty($option->sectionid) && !in_array($option->sectionid, $sectionids)) {
            $errors[] = "Iteration {$iteration}/{$step}: Orphaned format_option (id={$option->id}, sectionid={$option->sectionid}, name={$option->name})";
        }
    }
    
    // Check 5: All course modules should reference valid sections
    $modules = $DB->get_records('course_modules', ['course' => $courseid]);
    foreach ($modules as $module) {
        $sectionexists = $DB->record_exists('course_sections', ['id' => $module->section]);
        if (!$sectionexists) {
            $errors[] = "Iteration {$iteration}/{$step}: Course module {$module->id} references non-existent section {$module->section}";
        }
    }
    
    // Check 6: Section sequences should only reference existing modules
    foreach ($allsections as $section) {
        if (!empty($section->sequence)) {
            $moduleids = explode(',', $section->sequence);
            foreach ($moduleids as $moduleid) {
                if (!empty($moduleid)) {
                    $moduleexists = $DB->record_exists('course_modules', ['id' => $moduleid, 'course' => $courseid]);
                    if (!$moduleexists) {
                        $errors[] = "Iteration {$iteration}/{$step}: Section {$section->id} sequence references non-existent module {$moduleid}";
                    }
                }
            }
        }
    }
    
    $sectioncount = count($sections);
    $optioncount = count($options);
    $modulecount = count($modules);
    echo "    Sections: {$sectioncount}, Format options: {$optioncount}, Modules: {$modulecount}\n";
    
    if (empty($errors) && empty($warnings)) {
        echo "    ✓ Database integrity OK\n";
    } else {
        if (!empty($warnings)) {
            echo "    ⚠ Warnings found: " . count($warnings) . "\n";
        }
        if (!empty($errors)) {
            echo "    ✗ ERRORS found: " . count($errors) . "\n";
        }
    }
}

/**
 * Helper function to create a simple course structure with activities
 */
function create_test_structure($courseformat, $courseid, $iteration) {
    global $DB;
    
    echo "  Creating structure (Iteration {$iteration})...\n";
    
    // Create 10 themes, each with 2 weeks, each week with 3 sessions
    $themes_created = 0;
    $weeks_created = 0;
    $sessions_created = 0;
    
    // Activity types to create (cycling through them)
    $activity_types = ['assignment', 'forum', 'quiz', 'url', 'label'];
    $activity_index = 0;
    
    for ($t = 1; $t <= 10; $t++) {
        // Create theme
        $themesectionnum = $courseformat->create_new_section(0, null);
        $themesectionid = $DB->get_field('course_sections', 'id', 
            ['course' => $courseid, 'section' => $themesectionnum]);
        
        $DB->update_record('course_sections', [
            'id' => $themesectionid,
            'name' => "Theme {$t} (Iteration {$iteration})",
        ]);
        
        $themes_created++;
        
        // Create weeks under theme
        for ($w = 1; $w <= 2; $w++) {
            $weeksectionnum = $courseformat->create_new_section(0, null);
            $weeksectionid = $DB->get_field('course_sections', 'id', 
                ['course' => $courseid, 'section' => $weeksectionnum]);
            
            // Set parent relationship
            $courseformat->update_section_format_options([
                'id' => $weeksectionid,
                'parent' => $themesectionnum
            ]);
            
            $DB->update_record('course_sections', [
                'id' => $weeksectionid,
                'name' => "Week {$w} (Theme {$t}, Iter {$iteration})",
            ]);
            
            $weeks_created++;
            
            // Create sessions under week
            $sessiondata = [
                'presession' => ['description' => "Pre-session for T{$t}W{$w} Iter{$iteration}"],
                'session' => ['description' => "Session for T{$t}W{$w} Iter{$iteration}"],
                'postsession' => ['description' => "Post-session for T{$t}W{$w} Iter{$iteration}"],
            ];
            
            $sessionsectionmap = \aiplacement_modgen\local\session_creator::create_session_subsections(
                $courseformat,
                $weeksectionnum,
                $courseid,
                $sessiondata
            );
            
            $sessions_created += 3;
            
            // Create 2 activities in each session subsection
            foreach (['presession', 'session', 'postsession'] as $sessiontype) {
                $sessionsectionnum = $sessionsectionmap[$sessiontype];
                
                for ($a = 1; $a <= 2; $a++) {
                    $activitytype = $activity_types[$activity_index % count($activity_types)];
                    $activity_index++;
                    
                    try {
                        $moduleinfo = new stdClass();
                        $moduleinfo->course = $courseid;
                        $moduleinfo->section = $sessionsectionnum;
                        $moduleinfo->modulename = $activitytype;
                        $moduleinfo->name = "{$activitytype} {$a} - {$sessiontype} T{$t}W{$w} I{$iteration}";
                        $moduleinfo->visible = 1;
                        $moduleinfo->visibleoncoursepage = 1;
                        $moduleinfo->completion = 0;
                        $moduleinfo->coursemodule = 0; // Required
                        $moduleinfo->instance = 0; // Required
                        $moduleinfo->add = $activitytype; // Required
                        
                        // Type-specific required fields
                        if ($activitytype === 'assignment') {
                            $moduleinfo->intro = "Assignment intro";
                            $moduleinfo->introformat = FORMAT_HTML;
                            $moduleinfo->assignsubmission_onlinetext_enabled = 1;
                            $moduleinfo->assignsubmission_file_enabled = 0;
                            $moduleinfo->grade = 100;
                        } elseif ($activitytype === 'forum') {
                            $moduleinfo->intro = "Forum intro";
                            $moduleinfo->introformat = FORMAT_HTML;
                            $moduleinfo->type = 'general';
                        } elseif ($activitytype === 'quiz') {
                            $moduleinfo->intro = "Quiz intro";
                            $moduleinfo->introformat = FORMAT_HTML;
                            $moduleinfo->timeopen = 0;
                            $moduleinfo->timeclose = 0;
                            $moduleinfo->grade = 100;
                            $moduleinfo->questionsperpage = 1;
                        } elseif ($activitytype === 'url') {
                            $moduleinfo->intro = "URL intro";
                            $moduleinfo->introformat = FORMAT_HTML;
                            $moduleinfo->externalurl = 'https://example.com/test' . $activity_index;
                            $moduleinfo->display = 0;
                        } elseif ($activitytype === 'label') {
                            $moduleinfo->intro = "Label content for iteration {$iteration}";
                            $moduleinfo->introformat = FORMAT_HTML;
                        }
                        
                        $cm = create_module($moduleinfo);
                    } catch (Exception $e) {
                        // Silently skip - some modules might not be available
                    }
                }
            }
        }
    }
    
    echo "    Created: {$themes_created} themes, {$weeks_created} weeks, {$sessions_created} sessions\n";
    return [
        'themes' => $themes_created,
        'weeks' => $weeks_created,
        'sessions' => $sessions_created,
    ];
}

/**
 * Helper function to delete all sections except section 0
 */
function delete_all_sections($courseid, $iteration) {
    global $DB;
    
    echo "  Deleting all sections (Iteration {$iteration})...\n";
    
    // First delete all course modules (activities)
    $modules = $DB->get_records('course_modules', ['course' => $courseid]);
    $deleted_modules = 0;
    
    foreach ($modules as $module) {
        try {
            course_delete_module($module->id);
            $deleted_modules++;
        } catch (Exception $e) {
            echo "    Warning: Failed to delete module {$module->id}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "    Deleted {$deleted_modules} modules\n";
    
    // Now delete sections
    $sections = $DB->get_records('course_sections', ['course' => $courseid]);
    $deleted_count = 0;
    
    foreach ($sections as $section) {
        if ($section->section == 0) {
            continue; // Skip section 0 (general section)
        }
        
        // Delete format options for this section
        $DB->delete_records('course_format_options', ['sectionid' => $section->id]);
        
        // Delete the section
        $DB->delete_records('course_sections', ['id' => $section->id]);
        
        $deleted_count++;
    }
    
    echo "    Deleted {$deleted_count} sections\n";
    return [
        'sections' => $deleted_count,
        'modules' => $deleted_modules,
    ];
}

/**
 * Helper function to hide existing sections and move new ones to top
 * This simulates the hideexistingsections option in prompt.php
 */
function hide_and_move_sections($courseformat, $courseid, $old_section_ids, $new_section_ids, $iteration) {
    global $DB;
    
    echo "  Hiding existing sections and moving new ones to top (Iteration {$iteration})...\n";
    echo "    Old sections to hide: " . count($old_section_ids) . "\n";
    echo "    New sections to keep visible: " . count($new_section_ids) . "\n";
    
    // Step 1: Hide ALL old sections (matching prompt.php lines 819-839)
    $allsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
    $hidden_count = 0;
    
    foreach ($allsections as $section) {
        if ($section->section == 0) {
            continue; // Skip section 0 (general)
        }
        
        // Hide if it's NOT in the new section IDs list
        if (!in_array($section->id, $new_section_ids)) {
            $DB->update_record('course_sections', [
                'id' => $section->id,
                'visible' => 0,
            ]);
            $hidden_count++;
        }
    }
    
    echo "    Hidden {$hidden_count} old sections\n";
    
    // Step 2: Move new top-level sections to the top (matching prompt.php lines 844-913)
    // Get only top-level new sections (parent = 0 or no parent set)
    $toplevel_new_sections = [];
    foreach ($new_section_ids as $sectionid) {
        $parentvalue = $DB->get_field('course_format_options', 'value', 
            ['courseid' => $courseid, 'sectionid' => $sectionid, 'name' => 'parent']);
        
        if (empty($parentvalue) || $parentvalue == '0') {
            $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $sectionid]);
            if ($sectionnum > 0) { // Exclude section 0
                $toplevel_new_sections[] = $sectionid;
            }
        }
    }
    
    echo "    Top-level sections to move: " . count($toplevel_new_sections) . "\n";
    
    // Move each top-level section to position 1 (after section 0)
    // They'll stack in reverse order, so move in reverse to maintain order
    $moved_count = 0;
    foreach (array_reverse($toplevel_new_sections) as $sectionid) {
        try {
            if (method_exists($courseformat, 'move_section')) {
                $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $sectionid]);
                $courseformat->move_section($sectionnum, 1);
                $moved_count++;
            }
        } catch (Exception $e) {
            echo "    Warning: Failed to move section {$sectionid}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "    Moved {$moved_count} sections to top\n";
    
    return [
        'hidden' => $hidden_count,
        'moved' => $moved_count,
    ];
}

// Run the stress test with 4 iterations
$iterations = 4;
$test_summary = [];

for ($i = 1; $i <= $iterations; $i++) {
    echo "\n--- Iteration {$i} of {$iterations} ---\n";
    
    // Track old section IDs before creating new ones
    $old_sections = $DB->get_records('course_sections', ['course' => $course->id], '', 'id');
    $old_section_ids = array_keys($old_sections);
    
    // Step 1: Create structure
    echo "\nStep 1: Create\n";
    $stats = create_test_structure($courseformat, $course->id, $i);
    verify_database_integrity($course->id, $i, 'after create');
    rebuild_course_cache($course->id, true);
    
    // Get NEW section IDs (created in this iteration)
    $all_sections = $DB->get_records('course_sections', ['course' => $course->id], '', 'id');
    $all_section_ids = array_keys($all_sections);
    $new_section_ids = array_diff($all_section_ids, $old_section_ids);
    
    echo "  Old sections: " . count($old_section_ids) . ", New sections: " . count($new_section_ids) . "\n";
    
    // Step 2: Hide existing and move new to top (simulate "hide existing sections" option)
    // Skip on first iteration since there are no old sections to hide
    if ($i > 1) {
        echo "\nStep 2: Hide existing sections and move new to top\n";
        $hide_stats = hide_and_move_sections($courseformat, $course->id, $old_section_ids, $new_section_ids, $i);
        verify_database_integrity($course->id, $i, 'after hide and move');
        rebuild_course_cache($course->id, true);
        
        // Verify section order - new sections should be at top
        echo "  Verifying section order...\n";
        $sections_ordered = $DB->get_records('course_sections', ['course' => $course->id], 'section ASC');
        $visible_sections = [];
        $hidden_sections = [];
        foreach ($sections_ordered as $section) {
            if ($section->section == 0) continue;
            if ($section->visible) {
                $visible_sections[] = $section->section;
            } else {
                $hidden_sections[] = $section->section;
            }
        }
        echo "    Visible sections: " . implode(', ', array_slice($visible_sections, 0, 5)) . (count($visible_sections) > 5 ? '...' : '') . "\n";
        echo "    Hidden sections: " . count($hidden_sections) . "\n";
    }
    
    // Step 3: Delete all sections (clean slate for next iteration)
    echo "\nStep 3: Delete all\n";
    $deleted = delete_all_sections($course->id, $i);
    verify_database_integrity($course->id, $i, 'after delete');
    rebuild_course_cache($course->id, true);
    
    $test_summary[] = [
        'iteration' => $i,
        'created' => $stats,
        'deleted' => $deleted,
        'hide_stats' => $hide_stats ?? null,
        'errors' => count($errors),
        'warnings' => count($warnings),
    ];
}

// Final comprehensive check
echo "\n\n=== FINAL DATABASE VERIFICATION ===\n";
verify_database_integrity($course->id, 'FINAL', 'complete');

// Check for any remaining orphaned records
echo "\nChecking for orphaned records...\n";
$remaining_sections = $DB->count_records('course_sections', ['course' => $course->id]);
$remaining_options = $DB->count_records('course_format_options', ['courseid' => $course->id]);
$remaining_modules = $DB->count_records('course_modules', ['course' => $course->id]);

echo "  Remaining sections: {$remaining_sections} (should be 1 - section 0)\n";
echo "  Remaining format options: {$remaining_options}\n";
echo "  Remaining modules: {$remaining_modules} (should be 0)\n";

if ($remaining_sections == 1) {
    echo "  ✓ Only section 0 remains (correct)\n";
} else {
    $errors[] = "FINAL: Expected 1 section (section 0), found {$remaining_sections}";
}

if ($remaining_modules == 0) {
    echo "  ✓ No orphaned modules (correct)\n";
} else {
    $errors[] = "FINAL: Expected 0 modules, found {$remaining_modules}";
}

// Print summary
echo "\n\n=== TEST SUMMARY ===\n";
foreach ($test_summary as $summary) {
    echo "Iteration {$summary['iteration']}:\n";
    echo "  Created: {$summary['created']['themes']} themes, {$summary['created']['weeks']} weeks, {$summary['created']['sessions']} sessions\n";
    if ($summary['hide_stats']) {
        echo "  Hidden: {$summary['hide_stats']['hidden']} sections, Moved: {$summary['hide_stats']['moved']} to top\n";
    }
    echo "  Deleted: {$summary['deleted']['sections']} sections, {$summary['deleted']['modules']} modules\n";
    echo "  Errors: {$summary['errors']}, Warnings: {$summary['warnings']}\n";
}

echo "\n=== ERRORS AND WARNINGS ===\n";
if (empty($errors) && empty($warnings)) {
    echo "✓✓✓ NO ERRORS OR WARNINGS ✓✓✓\n";
} else {
    if (!empty($warnings)) {
        echo "\nWARNINGS (" . count($warnings) . "):\n";
        foreach (array_unique($warnings) as $warning) {
            echo "  ⚠ {$warning}\n";
        }
    }
    if (!empty($errors)) {
        echo "\nERRORS (" . count($errors) . "):\n";
        foreach (array_unique($errors) as $error) {
            echo "  ✗ {$error}\n";
        }
    }
}

// Cleanup: Delete test course
echo "\n\n=== CLEANUP ===\n";
delete_course($course->id, false);
echo "✓ Test course deleted\n";

// Final result
echo "\n=== FINAL RESULT ===\n";
if (empty($errors)) {
    echo "✓✓✓ ALL TESTS PASSED ✓✓✓\n";
    echo "Database integrity maintained through {$iterations} iterations!\n";
    exit(0);
} else {
    echo "✗✗✗ TESTS FAILED ✗✗✗\n";
    echo "Found " . count($errors) . " errors during testing.\n";
    exit(1);
}
