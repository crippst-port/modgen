<?php
/**
 * Test script to verify section HTML extraction
 *
 * Usage: php test_section_html.php <courseid>
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/template_reader.php');

// Get course ID from command line
$courseid = isset($argv[1]) ? (int)$argv[1] : 0;

if ($courseid <= 0) {
    echo "Usage: php test_section_html.php <courseid>\n";
    echo "Example: php test_section_html.php 3\n";
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
echo "SECTIONS FROM DATABASE (raw HTML):\n";
echo str_repeat("-", 80) . "\n";
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,section,name,summary');
$section_count = 0;
$html_sections = 0;

foreach ($sections as $section) {
    $section_count++;
    $name = !empty($section->name) ? $section->name : "Section {$section->section}";
    $summary_length = strlen($section->summary ?? '');

    echo "\n{$section_count}. {$name} (ID: {$section->id})\n";
    echo "   Summary length: {$summary_length} chars\n";

    if (!empty($section->summary)) {
        $html_sections++;
        // Check for Bootstrap classes
        $has_bootstrap = preg_match('/(class="[^"]*(?:row|col-|card|nav|tab|accordion|btn)[^"]*")/i', $section->summary);
        echo "   Has Bootstrap classes: " . ($has_bootstrap ? "YES" : "NO") . "\n";

        // Show first 200 chars
        echo "   Preview: " . substr(strip_tags($section->summary), 0, 200) . "...\n";

        // Show first HTML tag
        if (preg_match('/<[^>]+>/', $section->summary, $matches)) {
            echo "   First HTML tag: " . htmlspecialchars($matches[0]) . "\n";
        }
    } else {
        echo "   (No summary/description)\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Total sections: {$section_count}\n";
echo "Sections with HTML: {$html_sections}\n\n";

// Test template reader extraction
echo "TESTING TEMPLATE READER:\n";
echo str_repeat("-", 80) . "\n";

try {
    $template_reader = new \aiplacement_modgen\local\template_reader();

    // Test extraction
    $template_key = (string)$courseid;
    echo "Extracting template data for key: '{$template_key}'\n\n";

    $template_data = $template_reader->extract_curriculum_template($template_key);

    echo "Extracted data keys: " . implode(', ', array_keys($template_data)) . "\n\n";

    // Check template_html
    if (isset($template_data['template_html'])) {
        $html_length = strlen($template_data['template_html']);
        echo "template_html length: {$html_length} chars\n";

        if ($html_length > 0) {
            // Check for Bootstrap
            $bootstrap_count = preg_match_all('/(class="[^"]*(?:row|col-|card|nav|tab|accordion|btn)[^"]*")/i',
                                             $template_data['template_html']);
            echo "Bootstrap classes found: {$bootstrap_count}\n";

            echo "\nFirst 500 chars of template_html:\n";
            echo str_repeat("-", 80) . "\n";
            echo substr($template_data['template_html'], 0, 500) . "\n";
            echo str_repeat("-", 80) . "\n";
        } else {
            echo "WARNING: template_html is empty!\n";
        }
    } else {
        echo "ERROR: template_html key not found in extracted data!\n";
    }

    // Check bootstrap_structure
    echo "\nBootstrap structure extraction:\n";
    $bootstrap_structure = $template_reader->extract_bootstrap_structure($template_key);
    echo "Components found: " . count($bootstrap_structure['components']) . "\n";
    if (!empty($bootstrap_structure['components'])) {
        echo "Components: " . implode(', ', $bootstrap_structure['components']) . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Test complete!\n";
