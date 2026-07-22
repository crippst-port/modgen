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
 * @package aiplacement_modgen
 */
class registry {
    /** @var array<string, class-string<activity_type>>|null Cached map of type identifier => class FQCN. */
    private static ?array $map = null;

    /**
     * Return metadata for all discoverable activity handlers indexed by type.
     * Excludes handlers marked as AI_CREATABLE = false.
     *
     * @return array<string, array{stringid: string, description: string}>
     */
    public static function get_supported_activity_metadata(): array {
        $handlers = [];

        foreach (self::get_map() as $type => $class) {
            // Check if handler has AI_CREATABLE constant and respect it
            if (defined("$class::AI_CREATABLE") && !$class::AI_CREATABLE) {
                // Skip handlers that are not AI-creatable
                continue;
            }

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

        foreach ($activities as $index => $activity) {
            $type = self::normalise_type($activity->type ?? '');
            $handlerclass = self::get_map()[$type] ?? null;

            if ($handlerclass === null) {
                $error = get_string('activitytypeunsupported', 'aiplacement_modgen', $type ?: '?');
                $warnings[] = $error;
                continue;
            }

            try {
                $handler = new $handlerclass();
                $result = $handler->create($activity, $course, $sectionnumber, $options);

                if ($result === null) {
                    $error = get_string('activitytypecreationfailed', 'aiplacement_modgen', $type);
                    $warnings[] = $error;
                    continue;
                }

                // Track AI-generated activities.
                $cmid = $result['cmid'] ?? $result['coursemodule'] ?? null;
                if ($cmid) {
                    \aiplacement_modgen\aigen_tracker::mark_as_aigenerated((int)$cmid, $course->id);
                }

                $results[] = $result;
            } catch (\Exception $e) {
                $error = "Exception creating $type: " . $e->getMessage();
                $warnings[] = $error;
            }
        }

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
                continue;
            }
            // Fallbacks: many handlers return arrays with 'coursemodule', 'instance' or similar.
            if (!empty($result['coursemodule'])) {
                $created[] = get_string('activity_created_coursemodule', 'aiplacement_modgen', (int)$result['coursemodule']);
                continue;
            }
            if (!empty($result['instance'])) {
                $created[] = get_string('activity_created_instance', 'aiplacement_modgen', (int)$result['instance']);
                continue;
            }
            if (!empty($result['cmid'])) {
                $created[] = get_string('activity_created_cmid', 'aiplacement_modgen', (int)$result['cmid']);
                continue;
            }
            // Last resort: include a JSON-encoded representation so caller can inspect.
            $created[] = json_encode($result);
        }

        return [
            'created' => $created,
            'warnings' => $outcome['warnings'],
        ];
    }

    /**
     * Get handler class for a specific activity type.
     * Useful for programmatically creating activities that aren't AI-creatable.
     *
     * @param string $type Activity type identifier (e.g., 'learningactivity')
     * @return class-string<activity_type>|null Handler class or null if not found
     */
    public static function get_handler(string $type): ?string {
        $type = self::normalise_type($type);
        return self::get_map()[$type] ?? null;
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

        foreach ($files as $filepath) {
            $filename = basename($filepath, '.php');
            if ($filename === 'registry' || $filename === 'activity_type') {
                continue;
            }

            $classname = __NAMESPACE__ . '\\' . $filename;

            if (!class_exists($classname, false)) {
                require_once($filepath);
            }

            if (!class_exists($classname)) {
                continue;
            }

            if (!is_subclass_of($classname, activity_type::class)) {
                continue;
            }

            $type = self::normalise_type($classname::get_type());
            if ($type === '') {
                continue;
            }

            $map[$type] = $classname;
        }

        self::$map = $map;

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
