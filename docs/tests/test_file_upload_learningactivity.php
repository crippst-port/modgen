#!/usr/bin/env php
<?php
/**
 * Test script to verify learningactivity creation from template/file upload.
 * 
 * This simulates the file upload workflow to verify learningactivities are created.
 * 
 * Usage: php test_file_upload_learningactivity.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\section_creation_service;

echo "=== Testing Learning Activity Creation from Template ===\n\n";

// Set up admin user for CLI context (required for capability checks)
$admin = get_admin();
if (!$admin) {
    echo "✗ Could not find admin user\n";
    exit(1);
}
\core\session\manager::set_user($admin);
echo "Running as user: {$admin->username} (ID: {$admin->id})\n\n";

// Test course ID
$courseid = 2;

// Sample JSON structure (minimal theme with one week and sessions)
$json = [
    'themes' => [
        [
            'title' => 'Test Theme for Learning Activities',
            'summary' => 'Testing learningactivity creation',
            'weeks' => [
                [
                    'title' => 'Test Week 1',
                    'summary' => 'Week 1 summary',
                    'learningactivity_metadata' => [
                        'name' => 'Week 1 Overview',
                        'instructions' => 'This is a test week for learning activities',
                        'duration' => '2 hours',
                        'learningmode' => 'Online'
                    ],
                    'sessions' => [
                        'presession' => [
                            'description' => 'Pre-session work',
                            'learningactivity_metadata' => [
                                'name' => 'Pre-session Reading',
                                'instructions' => 'Read chapter 1',
                                'duration' => '30',
                                'learningmode' => 'Asynchronous'
                            ]
                        ],
                        'session' => [
                            'description' => 'Live session',
                            'learningactivity_metadata' => [
                                'name' => 'Live Discussion',
                                'instructions' => 'Participate in discussion',
                                'duration' => '60',
                                'learningmode' => 'Synchronous'
                            ]
                        ],
                        'postsession' => [
                            'description' => 'Post-session reflection',
                            'learningactivity_metadata' => [
                                'name' => 'Reflection Activity',
                                'instructions' => 'Write reflection',
                                'duration' => '45',
                                'learningmode' => 'Asynchronous'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]
];

try {
    echo "Creating sections from JSON template...\n\n";
    
    $service = new section_creation_service();
    $result = $service->create_sections_from_json(
        $json,
        $courseid,
        'connected_theme',
        false, // generatethemeintroductions
        false, // createsuggestedactivities - THIS IS THE KEY SETTING
        false  // hideexistingsections
    );
    
    echo "✓ Sections created\n";
    echo "\nResults:\n";
    foreach ($result['results'] as $msg) {
        echo "  - $msg\n";
    }
    
    if (!empty($result['warnings'])) {
        echo "\nWarnings:\n";
        foreach ($result['warnings'] as $warning) {
            echo "  - $warning\n";
        }
    }
    
    // Now check for learningactivity modules
    echo "\n\n=== Checking for Learning Activity Modules ===\n";
    
    $course = $DB->get_record('course', ['id' => $courseid]);
    $modinfo = get_fast_modinfo($course);
    
    $learningactivities = [];
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname === 'learningactivity') {
            $learningactivities[] = [
                'cmid' => $cm->id,
                'section' => $cm->sectionnum,
                'name' => $cm->name,
                'sectionname' => $modinfo->get_section_info($cm->sectionnum)->name
            ];
        }
    }
    
    echo "\nFound " . count($learningactivities) . " learningactivity modules:\n";
    
    if (count($learningactivities) > 0) {
        echo "✓ SUCCESS - Learning activities were created!\n\n";
        foreach ($learningactivities as $la) {
            echo "  CMID: {$la['cmid']}\n";
            echo "  Name: {$la['name']}\n";
            echo "  Section: {$la['section']} ({$la['sectionname']})\n";
            echo "  ---\n";
        }
    } else {
        echo "✗ FAILURE - NO learning activities found!\n";
        echo "\nThis confirms learningactivities are not being created.\n";
    }
    
    // Check error logs
    echo "\n=== Check Error Logs ===\n";
    echo "Run: tail -100 /var/log/apache2/error.log | grep MODGEN\n";
    echo "Or: docker-compose exec moodle tail -100 /var/log/apache2/error.log | grep MODGEN\n";
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
