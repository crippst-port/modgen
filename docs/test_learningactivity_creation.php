<?php
/**
 * Test script to demonstrate learningactivity creation.
 * 
 * This shows how to programmatically create learning activity instances
 * when generating course structure (themes/weeks).
 * 
 * Usage: php test_learningactivity_creation.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\activitytype\registry;

// Example 1: Create via registry (recommended)
echo "=== Example 1: Creating via Registry ===\n";

$courseid = 2; // Your test course
$course = get_course($courseid);
$sectionnumber = 1; // Section to add to

// Get the learningactivity handler
$handler = registry::get_handler('learningactivity');

if ($handler) {
    echo "✓ Handler found: $handler\n";
    
    // Create activity data
    $activitydata = new stdClass();
    $activitydata->sectiontype = 'section'; // 'section' for theme/week, 'activity' for detailed
    $activitydata->name = 'Test Week 1'; // Optional for sections
    $activitydata->duration = '2 hours';
    $activitydata->learningmode = 'Online';
    $activitydata->instructions = 'This week covers the fundamentals...';
    $activitydata->learningtypes = ['Acquisition', 'Practice'];
    $activitydata->learningoutcomes_weekly = 'Students will understand basic concepts';
    
    // Instantiate and create
    $instance = new $handler();
    $result = $instance->create($activitydata, $course, $sectionnumber);
    
    if ($result) {
        echo "✓ Created successfully!\n";
        echo "  CM ID: {$result['cmid']}\n";
        echo "  Instance ID: {$result['instance']}\n";
        echo "  Message: {$result['message']}\n";
    } else {
        echo "✗ Creation failed\n";
    }
} else {
    echo "✗ Handler not found - ensure learningactivity.php exists\n";
}

echo "\n";

// Example 2: Create using create_activities (batch)
echo "=== Example 2: Batch Creation via Registry ===\n";

$activities = [
    (object)[
        'type' => 'learningactivity',
        'sectiontype' => 'section',
        'name' => 'Week 2',
        'duration' => '3 hours',
        'learningmode' => 'Blended',
        'learningtypes' => ['Discussion', 'Collaboration'],
    ],
];

$outcome = registry::create_activities($activities, $course, $sectionnumber);

echo "Created: " . count($outcome['results']) . " activities\n";
echo "Warnings: " . count($outcome['warnings']) . "\n";

foreach ($outcome['results'] as $result) {
    echo "  ✓ CM ID: {$result['cmid']}\n";
}

foreach ($outcome['warnings'] as $warning) {
    echo "  ⚠ $warning\n";
}

echo "\n";

// Example 3: Verify AI doesn't see it
echo "=== Example 3: Verify AI Exclusion ===\n";

$aimetadata = registry::get_supported_activity_metadata();

if (isset($aimetadata['learningactivity'])) {
    echo "✗ PROBLEM: learningactivity is visible to AI (should be hidden)\n";
} else {
    echo "✓ learningactivity correctly hidden from AI\n";
}

echo "\nAI-visible activity types:\n";
foreach (array_keys($aimetadata) as $type) {
    echo "  - $type\n";
}

echo "\n=== Test Complete ===\n";
