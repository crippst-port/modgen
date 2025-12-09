<?php
/**
 * Test script to detect course layout type.
 * 
 * This script demonstrates the detect_course_layout() method which identifies:
 * - Theme-based layouts (themes containing weeks)
 * - Week-based layouts (themes with sessions directly, treated as weeks)
 * - Flat layouts (standalone weeks/topics)
 * 
 * Run from command line:
 * php test_layout_detection.php <courseid>
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

// Get course ID from command line.
$courseid = isset($argv[1]) ? (int)$argv[1] : null;

if (!$courseid) {
    echo "Usage: php test_layout_detection.php <courseid>\n";
    echo "Example: php test_layout_detection.php 2\n";
    exit(1);
}

// Verify course exists.
$course = $DB->get_record('course', ['id' => $courseid]);
if (!$course) {
    echo "Error: Course with ID {$courseid} not found.\n";
    exit(1);
}

echo "=== Course Layout Detection ===\n";
echo "Course: {$course->fullname} (ID: {$courseid})\n";
echo "Format: {$course->format}\n\n";

// Detect layout.
$layout = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid);

echo "Layout Type: {$layout['type']}\n";
echo "Description: {$layout['description']}\n\n";

echo "Details:\n";
foreach ($layout['details'] as $key => $value) {
    $displaykey = str_replace('_', ' ', ucfirst($key));
    $displayvalue = is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
    echo "  {$displaykey}: {$displayvalue}\n";
}

// Show section structure for context.
echo "\n=== Section Structure ===\n";
$modinfo = get_fast_modinfo($courseid);
$sections = $modinfo->get_section_info_all();

$sessionnames = [
    get_string('presession', 'aiplacement_modgen'),
    get_string('session', 'aiplacement_modgen'),
    get_string('postsession', 'aiplacement_modgen')
];

foreach ($sections as $section) {
    if ($section->section == 0) {
        continue;
    }
    
    // Skip intro/assessments sections.
    $introsectionname = get_string('introductionsectionname', 'aiplacement_modgen');
    $assessmentssectionname = get_string('assessmentssectionname', 'aiplacement_modgen');
    if ($section->name === $introsectionname || $section->name === $assessmentssectionname) {
        continue;
    }
    
    $issession = in_array($section->name, $sessionnames);
    $indent = empty($section->parent) ? '' : (in_array($section->name, $sessionnames) ? '      ' : '  ');
    $marker = $issession ? '🔹' : (empty($section->parent) ? '📂' : '📅');
    
    echo sprintf(
        "%s%s Section %2d: %-40s (Parent: %s)\n",
        $indent,
        $marker,
        $section->section,
        substr($section->name, 0, 40),
        empty($section->parent) ? 'None' : $section->parent
    );
}

echo "\n=== Legend ===\n";
echo "📂 = Top-level section (theme)\n";
echo "📅 = Week section\n";
echo "🔹 = Session subsection (pre/session/post)\n";
