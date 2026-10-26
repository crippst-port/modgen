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
 * Learning activity handler for creating learning design metadata modules.
 *
 * This activity type is used to capture pedagogical information about sections
 * (themes/weeks) for instructional design purposes. It is NOT intended to be
 * created by AI but rather programmatically when sections are created.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

/**
 * Creates Learning Activity modules for capturing learning design metadata.
 */
class learningactivity {
    /**
     * Whether summary modules can be created: mod_learningactivity is optional, and adds a
     * learning design summary to each section only when it is installed and enabled.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $DB;
        return \core_component::get_component_directory('mod_learningactivity') !== null
            && (bool) $DB->get_field('modules', 'visible', ['name' => 'learningactivity']);
    }
    /**
     * Create the learningactivity module in the requested course section.
     *
     * @param stdClass $activitydata Raw activity definition returned by the AI response.
     * @param stdClass $course Full course record.
     * @param int $sectionnumber Target section number within the course.
     * @param array $options Additional contextual options.
     * @return array|null Returns an array with 'cmid' and 'message' on success, null otherwise.
     */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        // Security: the user must be allowed to build structure here: Quick Add needs the structure
        // capability, building from a file or prompt needs a generate one, and all of them end up here.
        \aiplacement_modgen\local\permissions::require_build(\context_course::instance($course->id));

        // Sanitize name field to prevent XSS.
        $name = isset($activitydata->name) ? clean_param(trim($activitydata->name), PARAM_TEXT) : '';

        // Enforce maximum length, in characters so a multibyte character is never split.
        $name = \core_text::substr($name, 0, 255);

        // If no name, try to infer from section type.
        if ($name === '') {
            $sectiontype = $activitydata->sectiontype ?? 'section';
            if ($sectiontype === 'section') {
                // For sections, name is optional (hidden in form).
                $name = '';
            } else {
                // For activities, name is required.
                return null;
            }
        }

        // Create the learningactivity module.
        $moduleinfo = new stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->modulename = 'learningactivity';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;

        // Learning design fields.
        $moduleinfo->sectiontype = $activitydata->sectiontype ?? 'section';
        $moduleinfo->activityicon = $activitydata->activityicon ?? '';
        $moduleinfo->duration = $activitydata->duration ?? '';
        $moduleinfo->learningmode = $activitydata->learningmode ?? '';
        $moduleinfo->groupactivity = $activitydata->groupactivity ?? 0;
        $moduleinfo->designnotes = $activitydata->designnotes ?? '';
        $moduleinfo->learningoutcomes_weekly = $activitydata->learningoutcomes_weekly ?? '';

        // Instructions (must be in editor format for learningactivity module).
        if (isset($activitydata->instructions)) {
            $instructionstext = '';
            if (is_array($activitydata->instructions)) {
                $instructionstext = $activitydata->instructions['text'] ?? '';
            } else {
                $instructionstext = $activitydata->instructions;
            }
            // Convert to editor format expected by learningactivity_add_instance().
            $moduleinfo->instructions_editor = [
                'text' => $instructionstext,
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
        }

        // Weekly learning outcomes (for weeks only).
        if (isset($activitydata->learningoutcomes_weekly)) {
            $moduleinfo->learningoutcomes_weekly = $activitydata->learningoutcomes_weekly;
        }

        // Learning types (array of tags, will be imploded to CSV).
        if (isset($activitydata->learningtypes)) {
            if (is_array($activitydata->learningtypes)) {
                $moduleinfo->learningtypes = $activitydata->learningtypes;
            } else {
                // Already a string.
                $moduleinfo->learningtypes = explode(',', $activitydata->learningtypes);
            }
        }

        // Learning outcomes (array, will be JSON encoded).
        if (isset($activitydata->learningoutcomes)) {
            if (is_string($activitydata->learningoutcomes)) {
                $outcomes = json_decode($activitydata->learningoutcomes, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    debugging('Invalid JSON in learningoutcomes: ' . json_last_error_msg(), DEBUG_DEVELOPER);
                    $outcomes = [];
                }
                $moduleinfo->learningoutcomes = $outcomes;
            } else {
                $moduleinfo->learningoutcomes = $activitydata->learningoutcomes;
            }
        }

        // Assessments (array, will be JSON encoded).
        if (isset($activitydata->assessments)) {
            if (is_string($activitydata->assessments)) {
                $assessments = json_decode($activitydata->assessments, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    debugging('Invalid JSON in assessments: ' . json_last_error_msg(), DEBUG_DEVELOPER);
                    $assessments = [];
                }
                $moduleinfo->assessments = $assessments;
            } else {
                $moduleinfo->assessments = $activitydata->assessments;
            }
        }

        try {
            $coursemodule = create_module($moduleinfo);

            return [
                'cmid' => $coursemodule->coursemodule,
                'instance' => $coursemodule->instance,
                'message' => get_string('learningactivity_created', 'aiplacement_modgen', [
                    'name' => $name ?: get_string('learningactivity_section', 'aiplacement_modgen'),
                    'type' => $moduleinfo->sectiontype,
                ]),
            ];
        } catch (\dml_exception $e) {
            // The write has failed inside the build's transaction, which can no longer commit: let the
            // build see a database error, so it rolls back rather than carrying on.
            throw $e;
        } catch (\Exception $e) {
            debugging('Failed to create learningactivity: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('errorcreatinglearningactivity', 'aiplacement_modgen', '', null, $e->getMessage());
        }
    }
}
