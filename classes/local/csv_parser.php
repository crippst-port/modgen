<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * CSV parser for direct module structure creation without AI.
 *
 * @package     aiplacement_modgen
 * @category    local
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * CSV parser for creating module structures from uploaded CSV files.
 * 
 * This class parses CSV files to create module structures without using AI.
 * The CSV format should match your institution's course structure requirements.
 */
class csv_parser {

    /**
     * Detect whether a CSV file contains themed or weekly structure.
     * 
     * Detects by scanning for "Theme:" labels. If found, treats as themed structure.
     * If no "Theme:" labels are found, treats as weekly structure.
     *
     * @param stored_file $file The uploaded CSV file
     * @return string Either 'connected_theme' or 'connected_weekly'
     * @throws \Exception if CSV parsing fails
     */
    public static function detect_csv_format(\stored_file $file): string {
        $content = $file->get_content();
        
        if (empty($content)) {
            // Default to weekly if file is empty
            return 'connected_weekly';
        }

        // Parse CSV content
        $lines = explode("\n", $content);
        
        // Scan for "Theme:" labels (case-insensitive)
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line) || $line === ',') {
                continue;
            }

            // Parse the line
            $parts = str_getcsv($line);
            
            if (count($parts) >= 1) {
                $label = trim($parts[0]);
                
                // Check if this line contains a theme label
                if (stripos($label, 'Theme') === 0) {
                    return 'connected_theme';
                }
            }
        }

        // No themes found, default to weekly structure
        return 'connected_weekly';
    }

    /**
     * Parse a CSV file and return module structure in the same format as AI generation.
     *
     * Expected CSV format:
     * Title:,Course Title
     * 
     * Theme:,Theme Name
     * Description:,Optional theme description
     * Week:,Week Name/Description
     * Description:,Optional week description
     * Week:,Week Name/Description
     * 
     * Theme:,Next Theme Name
     * Description:,Optional theme description
     * Week:,Week Name/Description
     *
     * @param stored_file $file The uploaded CSV file
     * @param string $moduletype The module type (connected_weekly, connected_theme, etc.)
     * @return array Module structure array compatible with existing processing
     * @throws \Exception if CSV parsing fails
     */
    public static function parse_csv_to_structure(\stored_file $file, string $moduletype): array {
        $content = $file->get_content();
        
        if (empty($content)) {
            throw new \Exception('CSV file is empty');
        }

        // Parse CSV content
        $lines = explode("\n", $content);
        
        if (empty($lines)) {
            throw new \Exception('No data found in CSV file');
        }

        // Normalize module type
        $normalized_type = self::normalize_module_type($moduletype);
        
        if ($normalized_type === 'theme') {
            $structure = self::parse_simple_theme_structure($lines);
        } else {
            $structure = self::parse_simple_weekly_structure($lines);
        }

        return $structure;
    }

    /**
     * Normalize module type for processing.
     */
    private static function normalize_module_type(string $moduletype): string {
        if ($moduletype === 'connected_weekly') {
            return 'weekly';
        } elseif ($moduletype === 'connected_theme') {
            return 'theme';
        }
        return $moduletype;
    }

    /**
     * Parse CSV for simple themed structure.
     * Format: Title:,Course Title
     *         Theme:,Theme Name
     *         Description:,Theme Description (optional)
     *         Week:,Week Description
     *         Description:,Week Description (optional)
     *
     * @param array $lines CSV lines
     * @return array Themed structure
     */
    private static function parse_simple_theme_structure(array $lines): array {
        $themes = [];
        $current_theme = null;
        $current_week = null;
        $module_title = '';
        $last_item_type = ''; // Track what was just added (theme/week)

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line) || $line === ',') {
                continue;
            }

            // Parse the line
            $parts = str_getcsv($line);
            
            if (count($parts) < 2) {
                continue;
            }

            $label = trim($parts[0]);
            $value = trim($parts[1] ?? '');

            if (empty($value)) {
                continue;
            }

            // Process based on label
            if (stripos($label, 'Title') === 0) {
                $module_title = $value;
                $last_item_type = 'title';
            } elseif (stripos($label, 'Description') === 0) {
                // Add description to the most recently added item
                if ($last_item_type === 'week' && is_array($current_week)) {
                    // Directly modify the last week in the current theme
                    $week_count = count($current_theme['weeks']);
                    if ($week_count > 0) {
                        $current_theme['weeks'][$week_count - 1]['summary'] = $value;
                    }
                } elseif ($last_item_type === 'theme' && $current_theme !== null) {
                    $current_theme['summary'] = $value;
                }
            } elseif (stripos($label, 'Theme') === 0) {
                // Start a new theme - save the previous one first
                if ($current_theme !== null) {
                    $themes[] = $current_theme;
                }
                $current_theme = [
                    'title' => $value,
                    'summary' => '',
                    'weeks' => []
                ];
                $current_week = null;
                $last_item_type = 'theme';
            } elseif (stripos($label, 'Week') === 0 && $current_theme !== null) {
                // Add week to current theme
                $week = [
                    'title' => $value,
                    'summary' => '',
                    'sessions' => [
                        'presession' => ['description' => '', 'activities' => []],
                        'session' => ['description' => '', 'activities' => []],
                        'postsession' => ['description' => '', 'activities' => []]
                    ]
                ];
                $current_theme['weeks'][] = $week;
                // Mark that we just added a week (for description tracking)
                $current_week = true; // Just a flag, not a reference
                $last_item_type = 'week';
            }
        }

        // Add the last theme
        if ($current_theme !== null) {
            $themes[] = $current_theme;
        }

        return ['themes' => $themes];
    }

    /**
     * Parse CSV for simple weekly structure.
     * Format: Title:,Course Title
     *         Week:,Week Description
     *         Description:,Week Description (optional)
     *
     * @param array $lines CSV lines
     * @return array Weekly structure
     */
    private static function parse_simple_weekly_structure(array $lines): array {
        $sections = [];
        $current_section = null;
        $module_title = '';
        $last_item_type = ''; // Track what was just added

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line) || $line === ',') {
                continue;
            }

            // Parse the line
            $parts = str_getcsv($line);
            
            if (count($parts) < 2) {
                continue;
            }

            $label = trim($parts[0]);
            $value = trim($parts[1] ?? '');

            if (empty($value)) {
                continue;
            }

            // Process based on label
            if (stripos($label, 'Title') === 0) {
                $module_title = $value;
                $last_item_type = 'title';
            } elseif (stripos($label, 'Description') === 0) {
                // Add description to the most recently added section/week
                if ($last_item_type === 'week' && $current_section !== null) {
                    $current_section['summary'] = $value;
                }
            } elseif (stripos($label, 'Week') === 0 || stripos($label, 'Section') === 0) {
                // Add week/section
                $section = [
                    'title' => $value,
                    'summary' => '',
                    'sessions' => [
                        'presession' => ['description' => '', 'activities' => []],
                        'session' => ['description' => '', 'activities' => []],
                        'postsession' => ['description' => '', 'activities' => []]
                    ]
                ];
                $sections[] = $section;
                // Store reference to last section for description updates
                $current_section = &$sections[count($sections) - 1];
                $last_item_type = 'week';
            }
        }

        return ['sections' => $sections];
    }
}
