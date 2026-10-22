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
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

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
        $content = self::get_content($file);

        if (empty($content)) {
            // Default to weekly if file is empty.
            return 'connected_weekly';
        }

        // Scan for "Theme:" labels (case-insensitive) on real CSV rows, so a quoted description
        // that merely contains a line starting with "Theme" cannot change the detected format.
        foreach (self::read_rows($content) as $row) {
            if (stripos($row['label'], 'Theme') === 0) {
                return 'connected_theme';
            }
        }

        // No themes found, default to weekly structure.
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
        $content = self::get_content($file);

        if (empty($content)) {
            throw new \moodle_exception('csvempty', 'aiplacement_modgen');
        }

        // Parse CSV content into label/value rows. A real CSV reader is used so that quoted
        // values may contain commas, quotes and line breaks (Excel's Alt+Enter).
        $lines = self::read_rows($content);

        if (empty($lines)) {
            throw new \moodle_exception('csvnodata', 'aiplacement_modgen');
        }

        // Normalize module type.
        $normalizedtype = self::normalize_module_type($moduletype);

        if ($normalizedtype === 'theme') {
            $structure = self::parse_simple_theme_structure($lines);
        } else {
            $structure = self::parse_simple_weekly_structure($lines);
        }

        // Validate section count against max limit.
        // An unset setting means the default of 50; an explicit 0 means unlimited.
        $configured = get_config('aiplacement_modgen', 'maxcsvsections');
        $maxsections = ($configured === false || $configured === '') ? 50 : (int) $configured;

        if ($maxsections > 0) {
            $sectioncount = self::count_sections($structure);

            if ($sectioncount > $maxsections) {
                throw new \moodle_exception('csvlimitexceeded', 'aiplacement_modgen', '', [
                    'count' => $sectioncount,
                    'max' => $maxsections,
                ]);
            }
        }

        return $structure;
    }

    /**
     * Get file content with UTF-8 BOM stripped if present.
     */
    private static function get_content(\stored_file $file): string {
        $content = $file->get_content();
        // Strip UTF-8 BOM if present (common in Excel UTF-8 exports).
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        // Convert Windows-1252/Latin-1 exports to UTF-8.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        return $content;
    }

    /**
     * Read the file as CSV and return its non-empty rows as label/value pairs.
     *
     * Reading the whole content as CSV (rather than line by line) keeps quoted values that span
     * several lines in one piece. Rows with fewer than two columns, or an empty label or value,
     * carry nothing to import and are skipped. A value of "0" is a real value.
     *
     * @param string $content CSV text (UTF-8, BOM already removed)
     * @return array[] Each ['label' => string, 'value' => string, 'row' => int (1-based CSV record)]
     */
    private static function read_rows(string $content): array {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $recordnumber = 0;
        while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $recordnumber++;
            if ($record === [null] || count($record) < 2) {
                continue;
            }
            $label = trim((string) $record[0]);
            $value = trim((string) $record[1]);
            if ($label === '' || $value === '') {
                continue;
            }
            $rows[] = ['label' => $label, 'value' => $value, 'row' => $recordnumber];
        }
        fclose($stream);

        return $rows;
    }
    /**
     * Count total sections in a structure (themes + weeks).
     *
     * @param array $structure The parsed structure
     * @return int Total number of sections
     */
    private static function count_sections(array $structure): int {
        $count = 0;

        if (!empty($structure['themes'])) {
            $count += count($structure['themes']);
            foreach ($structure['themes'] as $theme) {
                if (!empty($theme['weeks'])) {
                    $count += count($theme['weeks']);
                }
            }
        }

        if (!empty($structure['weeks'])) {
            $count += count($structure['weeks']);
        }

        return $count;
    }

    /**
     * Normalize module type for processing.
     */
    private static function normalize_module_type(string $moduletype): string {
        if ($moduletype === 'connected_weekly') {
            return 'weekly';
        } else if ($moduletype === 'connected_theme') {
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
     * @param array $lines Label/value rows from read_rows()
     * @return array Themed structure
     */
    private static function parse_simple_theme_structure(array $lines): array {
        $themes = [];
        $currenttheme = null;
        $currentweek = null;
        $moduletitle = '';
        $lastitemtype = ''; // Track what was just added (theme/week).

        foreach ($lines as $row) {
            $label = $row['label'];
            $value = $row['value'];

            // Process based on label.
            if (stripos($label, 'Title') === 0) {
                $moduletitle = $value;
                $lastitemtype = 'title';
            } else if (stripos($label, 'Description') === 0) {
                // Add description to the most recently added item.
                if ($lastitemtype === 'week' && $currenttheme !== null) {
                    $weekcount = count($currenttheme['weeks']);
                    if ($weekcount > 0) {
                        $currenttheme['weeks'][$weekcount - 1]['summary'] = $value;
                    }
                } else if ($lastitemtype === 'theme' && $currenttheme !== null) {
                    $currenttheme['summary'] = $value;
                }
            } else if (stripos($label, 'Theme') === 0) {
                // Start a new theme - save the previous one first.
                if ($currenttheme !== null) {
                    $themes[] = $currenttheme;
                }
                $currenttheme = [
                    'title' => $value,
                    'summary' => '',
                    'weeks' => [],
                ];
                $currentweek = null;
                $lastitemtype = 'theme';
            } else if (stripos($label, 'Week') === 0) {
                if ($currenttheme === null) {
                    // Dropping it silently would lose content the user supplied.
                    throw new \moodle_exception('csvweekbeforetheme', 'aiplacement_modgen', '', (object) [
                        'row' => $row['row'],
                        'value' => s($value),
                    ]);
                }
                // Add week to current theme.
                $week = [
                    'title' => $value,
                    'summary' => '',
                    'learningactivity_metadata' => [
                        'name' => '',
                        'activityicon' => '',
                        'instructions' => '',
                    ],
                    'sessions' => [
                        'presession' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                            ],
                            'activities' => [],
                        ],
                        'session' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                            ],
                            'activities' => [],
                        ],
                        'postsession' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                            ],
                            'activities' => [],
                        ],
                    ],
                ];
                $currenttheme['weeks'][] = $week;
                // Mark that we just added a week (for description tracking).
                $currentweek = true; // Just a flag, not a reference.
                $lastitemtype = 'week';
            }
        }

        // Add the last theme.
        if ($currenttheme !== null) {
            $themes[] = $currenttheme;
        }

        return ['themes' => $themes];
    }

    /**
     * Parse CSV for simple weekly structure.
     * Format: Title:,Course Title
     *         Week:,Week Description
     *         Description:,Week Description (optional)
     *
     * @param array $lines Label/value rows from read_rows()
     * @return array Weekly structure
     */
    private static function parse_simple_weekly_structure(array $lines): array {
        $sections = [];
        $currentsection = null;
        $moduletitle = '';
        $lastitemtype = ''; // Track what was just added.

        foreach ($lines as $row) {
            $label = $row['label'];
            $value = $row['value'];

            // Process based on label.
            if (stripos($label, 'Title') === 0) {
                $moduletitle = $value;
                $lastitemtype = 'title';
            } else if (stripos($label, 'Description') === 0) {
                // Add description to the most recently added section/week.
                if ($lastitemtype === 'week' && $currentsection !== null) {
                    $currentsection['summary'] = $value;
                }
            } else if (stripos($label, 'Week') === 0 || stripos($label, 'Section') === 0) {
                // Add week/section.
                $section = [
                    'title' => $value,
                    'summary' => '',
                    'learningactivity_metadata' => [
                        'name' => '',
                        'activityicon' => '',
                        'instructions' => '',
                    ],
                    'sessions' => [
                        'presession' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                                'learningoutcomes_weekly' => '',
                            ],
                            'activities' => [],
                        ],
                        'session' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                                'learningoutcomes_weekly' => '',
                            ],
                            'activities' => [],
                        ],
                        'postsession' => [
                            'description' => '',
                            'learningactivity_metadata' => [
                                'name' => '',
                                'activityicon' => '',
                                'instructions' => '',
                                'duration' => null,
                                'learningmode' => null,
                                'groupactivity' => null,
                                'learningtypes' => null,
                                'learningoutcomes_weekly' => '',
                            ],
                            'activities' => [],
                        ],
                    ],
                ];
                $sections[] = $section;
                // Store reference to last section for description updates.
                $currentsection = &$sections[count($sections) - 1];
                $lastitemtype = 'week';
            }
        }

        return ['sections' => $sections];
    }
}
