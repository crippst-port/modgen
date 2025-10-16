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

namespace aiplacement_modgen\activitytype;

use core_text;
use stdClass;

require_once(__DIR__ . '/activity_type.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Registry that locates and instantiates AI activity handlers.
 */
class registry {
    /** @var array<string, class-string<activity_type>>|null Cached map of type identifier => class FQCN. */
    private static ?array $map = null;

    /**
     * Return metadata for all discoverable activity handlers indexed by type.
     *
     * @return array<string, array{stringid: string, description: string}>
     */
    public static function get_supported_activity_metadata(): array {
        $handlers = [];

        foreach (self::get_map() as $type => $class) {
            $handlers[$type] = [
                'stringid' => $class::get_display_string_id(),
                'description' => $class::get_prompt_description(),
            ];
        }

        return $handlers;
    }

    /**
     * Process a list of AI generated activities, creating each in turn.
     *
     * @param array<int, stdClass> $activities
     * @param stdClass $course
     * @param int $sectionnumber
     * @param array $options
     * @return array{results: array<int, array>, warnings: array<int, string>}
     */
    public static function create_activities(array $activities, stdClass $course, int $sectionnumber, array $options = []): array {
        $results = [];
        $warnings = [];

        file_put_contents('/tmp/modgen_debug.log', "\n=== CREATE ACTIVITIES DEBUG ===\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Activities count: " . count($activities) . "\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Activities data: " . print_r($activities, true) . "\n", FILE_APPEND);

        foreach ($activities as $index => $activity) {
            file_put_contents('/tmp/modgen_debug.log', "\nProcessing activity $index:\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Activity object: " . print_r($activity, true) . "\n", FILE_APPEND);
            
            $type = self::normalise_type($activity->type ?? '');
            $handlerclass = self::get_map()[$type] ?? null;

            file_put_contents('/tmp/modgen_debug.log', "Original type: " . ($activity->type ?? 'NULL') . "\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Normalized type: '$type'\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Handler class: " . ($handlerclass ?? 'NULL') . "\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Available handlers: " . print_r(array_keys(self::get_map()), true) . "\n", FILE_APPEND);

            if ($handlerclass === null) {
                $error = get_string('activitytypeunsupported', 'aiplacement_modgen', $type ?: '?');
                $warnings[] = $error;
                file_put_contents('/tmp/modgen_debug.log', "ERROR: $error\n", FILE_APPEND);
                continue;
            }

            try {
                $handler = new $handlerclass();
                file_put_contents('/tmp/modgen_debug.log', "Created handler instance\n", FILE_APPEND);
                
                $result = $handler->create($activity, $course, $sectionnumber, $options);
                file_put_contents('/tmp/modgen_debug.log', "Handler result: " . print_r($result, true) . "\n", FILE_APPEND);

                if ($result === null) {
                    $error = get_string('activitytypecreationfailed', 'aiplacement_modgen', $type);
                    $warnings[] = $error;
                    file_put_contents('/tmp/modgen_debug.log', "ERROR: $error\n", FILE_APPEND);
                    continue;
                }

                $results[] = $result;
                file_put_contents('/tmp/modgen_debug.log', "SUCCESS: Activity created\n", FILE_APPEND);
            } catch (\Exception $e) {
                $error = "Exception creating $type: " . $e->getMessage();
                $warnings[] = $error;
                file_put_contents('/tmp/modgen_debug.log', "EXCEPTION: $error\n", FILE_APPEND);
            }
        }

        file_put_contents('/tmp/modgen_debug.log', "\nFinal results: " . print_r($results, true) . "\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Final warnings: " . print_r($warnings, true) . "\n", FILE_APPEND);

        return [
            'results' => $results,
            'warnings' => $warnings,
        ];
    }

    /**
     * Convenience wrapper for creating activities in a single course section.
     *
     * @param array<int, stdClass|array> $activities
     * @param stdClass $course
     * @param int $sectionnumber
     * @param array $options
     * @return array{created: array<int, string>, warnings: array<int, string>}
     */
    public static function create_for_section(array $activities, stdClass $course, int $sectionnumber, array $options = []): array {
        $normalized = [];
        foreach ($activities as $activity) {
            if ($activity instanceof stdClass) {
                $normalized[] = $activity;
            } else if (is_array($activity)) {
                $normalized[] = (object) $activity;
            }
        }

        $outcome = self::create_activities($normalized, $course, $sectionnumber, $options);
        $created = [];
        foreach ($outcome['results'] as $result) {
            if (!empty($result['message'])) {
                $created[] = $result['message'];
            }
        }

        return [
            'created' => $created,
            'warnings' => $outcome['warnings'],
        ];
    }

    /**
     * Build and cache a map of type identifiers to handler classes.
     *
     * @return array<string, class-string<activity_type>>
     */
    private static function get_map(): array {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];
        $directory = __DIR__;
        $files = glob($directory . '/*.php');

        // Debug: log what files we're finding
        file_put_contents('/tmp/modgen_debug.log', "Registry scanning directory: $directory\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Found files: " . print_r($files, true) . "\n", FILE_APPEND);

        foreach ($files as $filepath) {
            $filename = basename($filepath, '.php');
            if ($filename === 'registry' || $filename === 'activity_type') {
                continue;
            }

            $classname = __NAMESPACE__ . '\\' . $filename;
            file_put_contents('/tmp/modgen_debug.log', "Checking class: $classname\n", FILE_APPEND);
            
            if (!class_exists($classname, false)) {
                require_once($filepath);
            }

            if (!class_exists($classname)) {
                file_put_contents('/tmp/modgen_debug.log', "Class $classname does not exist after require\n", FILE_APPEND);
                continue;
            }
            
            if (!is_subclass_of($classname, activity_type::class)) {
                file_put_contents('/tmp/modgen_debug.log', "Class $classname is not subclass of activity_type\n", FILE_APPEND);
                continue;
            }

            $type = self::normalise_type($classname::get_type());
            if ($type === '') {
                file_put_contents('/tmp/modgen_debug.log', "Class $classname returned empty type\n", FILE_APPEND);
                continue;
            }

            $map[$type] = $classname;
            file_put_contents('/tmp/modgen_debug.log', "Registered $type => $classname\n", FILE_APPEND);
        }

        self::$map = $map;
        file_put_contents('/tmp/modgen_debug.log', "Final map: " . print_r($map, true) . "\n", FILE_APPEND);
        return self::$map;
    }

    /**
     * Normalise an activity type identifier to alphanumeric lowercase.
     *
     * @param string $type
     * @return string
     */
    private static function normalise_type(string $type): string {
        $type = core_text::strtolower($type);
        $type = preg_replace('/[^a-z0-9_-]+/', '', $type);
        return $type ?? '';
    }
}
