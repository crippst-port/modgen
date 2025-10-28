<?php
/**
 * Direct test of HTML extraction from sections
 *
 * Usage: php test_section_html_direct.php <courseid>
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

// Get course ID from command line
$courseid = isset($argv[1]) ? (int)$argv[1] : 0;

if ($courseid <= 0) {
    echo "Usage: php test_section_html_direct.php <courseid>\n";
    echo "Example: php test_section_html_direct.php 3\n";
    exit(1);
}

echo "Testing HTML extraction from course ID: {$courseid}\n";
echo str_repeat("=", 80) . "\n\n";

// Check if course exists
$course = $DB->get_record('course', ['id' => $courseid]);
if (!$course) {
    echo "ERROR: Course {$courseid} not found\n";
    exit(1);
}

echo "Course: {$course->fullname}\n";
echo "Format: {$course->format}\n\n";

// Get sections directly from database
echo "SECTIONS FROM DATABASE (raw HTML from course_sections.summary field):\n";
echo str_repeat("-", 80) . "\n";
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,section,name,summary');
$section_count = 0;
$html_sections = 0;

foreach ($sections as $section) {
    $section_count++;
    $name = !empty($section->name) ? $section->name : "Section {$section->section}";
    $summary_length = strlen($section->summary ?? '');

    echo "\n{$section_count}. {$name} (Section #{$section->section}, ID: {$section->id})\n";
    echo "   Summary length: {$summary_length} chars\n";

    if (!empty($section->summary)) {
        $html_sections++;

        // Check for Bootstrap classes
        $bootstrap_matches = [];
        preg_match_all('/(row|col-\w+|card|nav|tab|accordion|btn|container|alert|badge)/i',
                      $section->summary, $bootstrap_matches);
        $bootstrap_classes = array_unique($bootstrap_matches[1]);

        if (!empty($bootstrap_classes)) {
            echo "   Bootstrap classes found: " . implode(', ', $bootstrap_classes) . "\n";
        } else {
            echo "   Bootstrap classes: NONE\n";
        }

        // Check for HTML tags
        $html_tags = [];
        preg_match_all('/<(\w+)[\s>]/i', $section->summary, $html_tags);
        $tags = array_unique($html_tags[1]);
        if (!empty($tags)) {
            echo "   HTML tags: " . implode(', ', $tags) . "\n";
        }

        // Show first 300 chars of raw HTML
        echo "\n   RAW HTML (first 300 chars):\n";
        echo "   " . str_repeat("-", 76) . "\n";
        $preview = substr($section->summary, 0, 300);
        $lines = explode("\n", $preview);
        foreach ($lines as $line) {
            echo "   " . $line . "\n";
        }
        if (strlen($section->summary) > 300) {
            echo "   ... [" . (strlen($section->summary) - 300) . " more chars]\n";
        }
        echo "   " . str_repeat("-", 76) . "\n";
    } else {
        echo "   (No summary/description - empty field)\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Summary:\n";
echo "  Total sections: {$section_count}\n";
echo "  Sections with HTML content: {$html_sections}\n";
echo "  Sections with empty content: " . ($section_count - $html_sections) . "\n";

if ($html_sections === 0) {
    echo "\n⚠️  WARNING: No section descriptions found!\n";
    echo "   This course doesn't have any content in section descriptions.\n";
    echo "   To test template extraction, add HTML content to section descriptions.\n";
} else {
    echo "\n✓ Section descriptions found and extracted successfully!\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
