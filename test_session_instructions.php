<?php
// Test script to check if session descriptions are being generated in the AI response
require_once(__DIR__ . '/../../config.php');

// Simple test: decode the last JSON response and check for session descriptions
$json_file = '/tmp/modgen_last_response.json';

if (!file_exists($json_file)) {
    echo "No previous response found. Generate a module first.\n";
    exit;
}

$json_str = file_get_contents($json_file);
$data = json_decode($json_str, true);

if (!$data) {
    echo "Could not decode JSON.\n";
    exit;
}

echo "=== SESSION DESCRIPTIONS DEBUG ===\n\n";

// Check if using themes structure
if (!empty($data['themes']) && is_array($data['themes'])) {
    echo "Found THEMES structure:\n";
    foreach ($data['themes'] as $idx => $theme) {
        echo "\nTheme " . ($idx + 1) . ": " . $theme['title'] . "\n";
        if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
            foreach ($theme['weeks'] as $widx => $week) {
                echo "  Week " . ($widx + 1) . ": " . $week['title'] . "\n";
                if (!empty($week['sessions']) && is_array($week['sessions'])) {
                    foreach (['presession', 'session', 'postsession'] as $stype) {
                        if (!empty($week['sessions'][$stype])) {
                            $sdata = $week['sessions'][$stype];
                            $desc = $sdata['description'] ?? '(NO DESCRIPTION)';
                            echo "    $stype: " . substr($desc, 0, 80) . (strlen($desc) > 80 ? '...' : '') . "\n";
                        }
                    }
                }
            }
        }
    }
} elseif (!empty($data['sections']) && is_array($data['sections'])) {
    echo "Found SECTIONS structure (weekly):\n";
    foreach ($data['sections'] as $idx => $section) {
        echo "\nSection " . ($idx + 1) . ": " . $section['title'] . "\n";
        echo "  Description: " . substr($section['summary'] ?? '(NO SUMMARY)', 0, 80) . "\n";
    }
}

echo "\n\nFull JSON structure:\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
