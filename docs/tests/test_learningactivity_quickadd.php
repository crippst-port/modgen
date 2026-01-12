#!/usr/bin/env php
<?php
/**
 * Test script to verify learningactivity creation in quick add workflow.
 * 
 * Usage: php test_learningactivity_quickadd.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\theme_builder;

echo "=== Testing Learning Activity Creation in Quick Add ===\n\n";

// Set up admin user for CLI context
$admin = get_admin();
if (!$admin) {
    echo "✗ Could not find admin user\n";
    exit(1);
}
\core\session\manager::set_user($admin);
echo "Running as user: {$admin->username} (ID: {$admin->id})\n\n";

// Use course ID 2 as test course
$courseid = 2;

try {
    // Test 1: Create a single week
    echo "Test 1: Creating a single week...\n";
    $result = theme_builder::create_weeks($courseid, 1, 0);
    
    if ($result['success']) {
        echo "✓ Week created successfully\n";
        echo "Messages: " . implode("\n", $result['messages']) . "\n";
    } else {
        echo "✗ Week creation failed\n";
    }
    
    echo "\nCheck the logs:\n";
    echo "  tail -50 /var/log/apache2/error.log | grep MODGEN\n";
    echo "  OR\n";
    echo "  tail -50 /tmp/modgen_debug.log\n\n";
    
    // Test 2: Verify learningactivity was created
    echo "\nTest 2: Checking for learningactivity modules...\n";
    $course = $DB->get_record('course', ['id' => $courseid]);
    $modinfo = get_fast_modinfo($course);
    
    $learningactivities = [];
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname === 'learningactivity') {
            $learningactivities[] = $cm;
        }
    }
    
    echo "Found " . count($learningactivities) . " learningactivity modules\n";
    
    if (count($learningactivities) > 0) {
        echo "✓ Learning activities exist\n";
        foreach ($learningactivities as $cm) {
            echo "  - CMID: {$cm->id}, Section: {$cm->sectionnum}, Name: {$cm->name}\n";
        }
    } else {
        echo "✗ NO learning activities found!\n";
        echo "This confirms the bug - learningactivities are not being created.\n";
    }
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";

