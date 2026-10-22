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
 * Helper class for creating session subsections in flexsections format.
 *
 * This class provides shared functionality for creating pre-session, session,
 * and post-session subsections used by both theme and weekly generation modes.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

use aiplacement_modgen\activitytype\registry;
use context_module;

/**
 * Session creator helper class.
 */
class session_creator {
    /**
     * Create pre-session, session, and post-session subsections under a parent section.
     *
     * @param object $courseformat The course format object (must be flexsections)
     * @param int $parentsectionnum The parent section number to create subsections under
     * @param int $courseid The course ID
     * @param array|null $sessiondata Optional session data with 'presession', 'session', 'postsession' keys
     * @param bool $createsummaryactivities Whether to create the learningactivity summary module in each session
     * @return array Associative array mapping session type to section number
     *               ['presession' => N, 'session' => N, 'postsession' => N]
     * @throws \Exception If course format is not flexsections or method is missing
     */
    public static function create_session_subsections(
        $courseformat,
        $parentsectionnum,
        $courseid,
        $sessiondata = null,
        $createsummaryactivities = true
    ) {
        global $DB;

        // Validate course format.
        if (!$courseformat || get_class($courseformat) !== 'format_flexsections') {
            throw new \moodle_exception('errorformatnotflexsections', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // Pin the parent to its section ID. format_flexsections addresses sections by
        // NUMBER, and numbers are positional: creating each session subsection below
        // renumbers the course, so the number passed in is only valid until the first
        // write. Hold the id and re-read the number before each use.
        $parentsectionid = \aiplacement_modgen\local\theme_builder::section_id_from_number(
            $courseid,
            $parentsectionnum
        );

        // Define session types with language strings.
        $sessiontypes = [
            'presession' => get_string('presession', 'aiplacement_modgen'),
            'session' => get_string('session', 'aiplacement_modgen'),
            'postsession' => get_string('postsession', 'aiplacement_modgen'),
        ];

        $sessionsectionmap = [];

        foreach ($sessiontypes as $sessionkey => $sessionlabel) {
            // Prepare session summary from description if provided.
            $sessionsummary = '';
            if (!empty($sessiondata[$sessionkey]) && is_array($sessiondata[$sessionkey])) {
                $data = $sessiondata[$sessionkey];
                if (!empty($data['description'])) {
                    // Stored as given: FORMAT_PLAIN is escaped when shown.
                    $sessionsummary = (string) $data['description'];
                }
            }

            // Create session section using centralized helper.
            $currentparentnum = \aiplacement_modgen\local\theme_builder::current_section_number(
                $courseid,
                $parentsectionid
            ) ?? $parentsectionnum;
            $sessionsection = \aiplacement_modgen\local\theme_builder::create_section_with_parent(
                $courseid,
                $courseformat,
                $currentparentnum,
                $sessionlabel,
                $sessionsummary,
                FORMAT_PLAIN,
                ['collapsed' => 0], // Sessions don't appear as links.
                false  // Defer cache rebuild until all 3 sessions created.
            );

            $sessionsectionnum = $sessionsection->section;
            $sessionsectionmap[$sessionkey] = $sessionsectionnum;

            // Create learningactivity summary module at the start of the session,
            // unless summary activities are switched off (e.g. Quick Add, where they
            // would be empty placeholders).
            if ($createsummaryactivities) {
                // Extract metadata - new structure or backward compatible.
                $metadata = [];
                $activityname = $sessionlabel . ' activity'; // Default name.

                if (!empty($sessiondata[$sessionkey]) && is_array($sessiondata[$sessionkey])) {
                    $data = $sessiondata[$sessionkey];
                    if (isset($data['learningactivity_metadata']) && is_array($data['learningactivity_metadata'])) {
                        // New structure with separate metadata.
                        $metadata = $data['learningactivity_metadata'];
                        // Use custom name if provided, otherwise use default.
                        if (!empty($metadata['name'])) {
                            $activityname = $metadata['name'];
                        }
                    } else if (!empty($data['description'])) {
                        // Backward compatibility: use description as instructions.
                        $metadata['instructions'] = $data['description'];
                    }
                }

                $sessioncmid = self::create_learningactivity_metadata(
                    $courseid,
                    $sessionsectionnum,
                    'activity',
                    $activityname,
                    $metadata,
                    false  // Don't validate - allows empty metadata from Quick Add.
                );
            }
        }

        return $sessionsectionmap;
    }

    /**
     * Create a learningactivity module at the start of a session.
     *
     * This is a shared helper to add learning design metadata modules to session subsections.
     *
     * @param int $courseid Course ID
     * @param int $sectionnumber Section number where activity should be created
     * @param string $sectiontype 'activity' for session subsections
     * @param string $name Session name
     * @param array $metadata Learningactivity metadata (instructions, duration, learningmode, etc.)
     * @param bool $validate Whether to validate metadata (true for AI-generated, false for manual/Quick Add)
     * @return int|null CM ID of created activity or null on failure
     */
    private static function create_learningactivity_metadata(
        $courseid,
        $sectionnumber,
        $sectiontype,
        $name,
        $metadata,
        $validate = false
    ) {
        global $DB;

        // Get handler.
        $handler = registry::get_handler('learningactivity');
        if (!$handler) {
            // Learningactivity handler not found.
            return null;
        }

        // Get course.
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Prepare activity data.
        $activitydata = new \stdClass();
        $activitydata->sectiontype = $sectiontype;
        $activitydata->name = $name;

        // Apply metadata - validate only if requested (AI-generated content).
        if ($validate && !empty($metadata)) {
            // Validate and sanitize metadata for AI-generated content.
            $validatedmetadata = learningactivity_validator::validate_metadata($metadata);
            foreach ($validatedmetadata as $field => $value) {
                if ($value !== null) {
                    $activitydata->$field = $value;
                }
            }
        } else {
            // Direct merge for manual/Quick Add - skip null/empty values.
            foreach ($metadata as $key => $value) {
                // Skip null values and empty strings to avoid issues with learningactivity module.
                if ($value !== null && $value !== '') {
                    $activitydata->$key = $value;
                }
            }
        }

        // Create learningactivity instance.
        try {
            $instance = new $handler();
            $result = $instance->create($activitydata, $course, $sectionnumber);

            if ($result && isset($result['cmid'])) {
                // Ensure context is created immediately to avoid integrity errors
                // if user refreshes page during background task execution.
                \context_module::instance($result['cmid']);
                return $result['cmid'];
            }
        } catch (\Exception $e) {
            // Log the error for debugging. Learningactivity creation failures are non-fatal
            // as the section structure can still be created without metadata modules.
            debugging(
                'Failed to create learningactivity in section ' . $sectionnumber . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }

        return null;
    }
}
