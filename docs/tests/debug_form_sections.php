<?php
/**
 * Debug script to show what sections the form sees.
 *
 * Usage: php ai/placement/modgen/docs/debug_form_sections.php <courseid>
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\date_calculator;

if ($argc < 2) {
    echo "Usage: php debug_form_sections.php <courseid>\n";
    exit(1);
}

$courseid = intval($argv[1]);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

echo "\n=== Form Section Debug for Course: {$course->fullname} (ID: {$courseid}) ===\n\n";

// Replicate what lib.php does
require_once(__DIR__ . '/../../classes/local/date_calculator.php');
$sectionsdata = date_calculator::calculate_section_dates($courseid, [], true);

echo "Sections returned by calculate_section_dates():\n";
echo "Total: " . count($sectionsdata) . "\n\n";

foreach ($sectionsdata as $sectionid => $data) {
    echo "ID: {$sectionid} | Section: {$data['section']} | Name: {$data['name']} | Date: {$data['formatted_date']} | Parent: " . ($data['is_parent'] ? 'Yes' : 'No') . "\n";
}

echo "\n--- Now applying filters from lib.php ---\n\n";

$introsectionname = get_string('introductionsectionname', 'aiplacement_modgen');
$assessmentssectionname = get_string('assessmentssectionname', 'aiplacement_modgen');

$modinfo = get_fast_modinfo($courseid);
$allsections = $modinfo->get_section_info_all();

$sessionnames = [
    get_string('presession', 'aiplacement_modgen'),
    get_string('session', 'aiplacement_modgen'),
    get_string('postsession', 'aiplacement_modgen')
];

echo "Filtered out sections:\n";
echo "Intro section name: '{$introsectionname}'\n";
echo "Assessments section name: '{$assessmentssectionname}'\n";
echo "Session names: " . implode(', ', array_map(function($s) { return "'{$s}'"; }, $sessionnames)) . "\n\n";

$filteredsections = [];
foreach ($sectionsdata as $sectionid => $sectiondata) {
    // Double-check section exists
    $sectionexists = false;
    foreach ($allsections as $section) {
        if ($section->id == $sectionid) {
            $sectionexists = true;
            break;
        }
    }

    if ($sectionexists) {
        $isspecial = ($sectiondata['name'] === $introsectionname || $sectiondata['name'] === $assessmentssectionname);
        $issession = in_array($sectiondata['name'], $sessionnames);
        
        if ($isspecial) {
            echo "FILTERED: Section {$sectiondata['section']} '{$sectiondata['name']}' - Special section\n";
        } else if ($issession) {
            echo "FILTERED: Section {$sectiondata['section']} '{$sectiondata['name']}' - Session subsection\n";
        } else {
            $filteredsections[] = $sectiondata;
        }
    } else {
        echo "FILTERED: Section ID {$sectionid} - Does not exist\n";
    }
}

echo "\n=== FINAL RESULT ===\n";
echo "Sections available to form: " . count($filteredsections) . "\n\n";

if (empty($filteredsections)) {
    echo "⚠️  NO SECTIONS AVAILABLE - This is why the form shows 'no sections available'\n\n";
    echo "Likely causes:\n";
    echo "1. All sections are filtered out as special sections or session subsections\n";
    echo "2. calculate_section_dates() is not returning any sections\n";
} else {
    echo "✓ Sections that will appear in form:\n";
    foreach ($filteredsections as $section) {
        echo "  - Section {$section['section']}: {$section['name']}\n";
    }
}

echo "\n";
