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
 * Section creation service for Module Generator.
 *
 * Handles creation of course sections and modules from approved JSON structure.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/subsection/classes/manager.php');

/**
 * Service for creating course sections from approved JSON structure.
 */
class section_creation_service {

    /**
     * Create sections from approved JSON structure.
     *
     * @param array $json The approved JSON structure
     * @param int $courseid Course ID
     * @param string $moduletype Module type (connected_theme, connected_weekly, etc)
     * @param bool $generatethemeintroductions Whether to generate theme introductions
     * @param bool $createsuggestedactivities Whether to create suggested activities
     * @param bool $hideexistingsections Whether to hide existing sections
     * @return array Array with 'results' and 'warnings' keys
     * @throws \Exception If section creation fails
     */
    public function create_sections_from_json(
        array $json,
        int $courseid,
        string $moduletype,
        bool $generatethemeintroductions,
        bool $createsuggestedactivities,
        bool $hideexistingsections
    ): array {
        global $DB;
        
        $results = [];
        $activitywarnings = [];
        $needscacherefresh = false;
        $new_toplevel_section_ids = [];
        
        // Lock the course to prevent concurrent access
        $lockkey = 'aiplacement_modgen_building_' . $courseid;
        $lock = \core\lock\lock_config::get_lock_factory('aiplacement_modgen')->get_lock(
            $lockkey,
            constants::GENERATION_LOCK_TIMEOUT
        );
        
        try {
            // Track existing sections if hiding is enabled
            $existing_section_ids = [];
            if ($hideexistingsections) {
                $existingsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
                foreach ($existingsections as $section) {
                    if ($section->section > 0) {
                        $existing_section_ids[] = $section->id;
                    }
                }
            }
            
            // Ensure course format is flexsections
            $this->ensure_flexsections_format($courseid);
            
            // Initialize core sections (section 0 and Assessments)
            \aiplacement_modgen\local\theme_builder::initialize_core_sections($courseid);
            
            $course = get_course($courseid, true);
            $context = \context_course::instance($courseid);
            $courseformat = course_get_format($course);
            
            // Process based on module type
            if ($moduletype === 'connected_theme' && !empty($json['themes']) && is_array($json['themes'])) {
                $result = $this->create_theme_structure(
                    $json['themes'],
                    $course,
                    $context,
                    $courseformat,
                    $generatethemeintroductions,
                    $createsuggestedactivities,
                    $hideexistingsections,
                    $new_toplevel_section_ids,
                    $activitywarnings
                );
                $results = array_merge($results, $result);
                $needscacherefresh = true;
            } else if (!empty($json['sections']) && is_array($json['sections'])) {
                $result = $this->create_weekly_structure(
                    $json['sections'],
                    $course,
                    $context,
                    $courseformat,
                    $moduletype,
                    $createsuggestedactivities,
                    $hideexistingsections,
                    $new_toplevel_section_ids,
                    $activitywarnings
                );
                $results = array_merge($results, $result);
            }
            
            // Handle hiding existing sections
            if ($hideexistingsections && !empty($new_toplevel_section_ids)) {
                $this->hide_and_reorder_sections($courseid, $course, $new_toplevel_section_ids);
            }
            
            if ($needscacherefresh) {
                rebuild_course_cache($courseid, true, true);
            }
            
        } finally {
            rebuild_course_cache($courseid, true, true);
            if (isset($lock)) {
                $lock->release();
            }
        }
        
        return [
            'results' => $results,
            'warnings' => $activitywarnings,
        ];
    }

    /**
     * Ensure course format is set to flexsections.
     *
     * @param int $courseid Course ID
     * @throws \Exception If flexsections plugin is not available
     */
    private function ensure_flexsections_format(int $courseid): void {
        $pluginmanager = \core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        
        if (empty($flexsectionsplugin)) {
            throw new \Exception(
                'The flexsections course format plugin is required but not installed. ' .
                'Please install the flexsections plugin before using this feature.'
            );
        }
        
        $course = get_course($courseid, true);
        
        if ($course->format !== 'flexsections') {
            $update = new \stdClass();
            $update->id = $courseid;
            $update->format = 'flexsections';
            
            update_course($update);
            rebuild_course_cache($courseid, true, true);
            
            $course = get_course($courseid, true);
            
            if ($course->format !== 'flexsections') {
                global $DB;
                $DB->set_field('course', 'format', 'flexsections', ['id' => $courseid]);
                rebuild_course_cache($courseid, true, true);
                $course = get_course($courseid, true);
            }
            
            if ($course->format !== 'flexsections') {
                throw new \Exception(
                    'Failed to set course format to flexsections. Current format: ' . $course->format
                );
            }
        }
    }

    /**
     * Create theme-based structure (connected_theme).
     *
     * @param array $themes Array of theme data
     * @param \stdClass $course Course object
     * @param \context_course $context Course context
     * @param \course_format $courseformat Course format instance
     * @param bool $generatethemeintroductions Whether to generate theme introductions
     * @param bool $createsuggestedactivities Whether to create activities
     * @param bool $hideexistingsections Whether to hide existing sections
     * @param array &$new_toplevel_section_ids Array to track new section IDs
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_theme_structure(
        array $themes,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        bool $generatethemeintroductions,
        bool $createsuggestedactivities,
        bool $hideexistingsections,
        array &$new_toplevel_section_ids,
        array &$activitywarnings
    ): array {
        global $DB;
        $results = [];
        
        foreach ($themes as $themeindex => $theme) {
            if (!is_array($theme)) {
                continue;
            }
            
            $title = $theme['title'] ?? get_string('themefallback', 'aiplacement_modgen');
            $summary = $theme['summary'] ?? '';
            $weeks = !empty($theme['weeks']) && is_array($theme['weeks']) ? $theme['weeks'] : [];
            
            try {
                if (!method_exists($courseformat, 'create_new_section')) {
                    throw new \Exception('Flexsections create_new_section method not available');
                }
                
                $themesectionnum = $courseformat->create_new_section(0, null);
                
                if ($hideexistingsections) {
                    $themesectionid = $DB->get_field('course_sections', 'id', [
                        'course' => $course->id,
                        'section' => $themesectionnum
                    ]);
                    if ($themesectionid) {
                        $new_toplevel_section_ids[] = $themesectionid;
                    }
                }
            } catch (\Exception $e) {
                $activitywarnings[] = "Failed to create theme section: " . $e->getMessage();
                continue;
            }
            
            $themetitle = format_string($title, true, ['context' => $context]);
            $sectionhtml = '';
            
            $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
            if ((!$ai_enabled || $generatethemeintroductions) && trim($summary) !== '') {
                $sectionhtml = format_text($summary, FORMAT_HTML, ['context' => $context]);
            }
            
            $DB->update_record('course_sections', [
                'id' => $DB->get_field('course_sections', 'id', [
                    'course' => $course->id,
                    'section' => $themesectionnum
                ]),
                'name' => $themetitle,
                'summary' => $sectionhtml,
                'summaryformat' => FORMAT_HTML,
            ]);
            
            $themesectionid = $DB->get_field('course_sections', 'id', [
                'course' => $course->id,
                'section' => $themesectionnum
            ]);
            
            if (method_exists($courseformat, 'update_section_format_options')) {
                $courseformat->update_section_format_options(['id' => $themesectionid, 'collapsed' => 1]);
            }
            
            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $themetitle);
            
            // Create nested week subsections
            if (!empty($weeks)) {
                foreach ($weeks as $weekindex => $week) {
                    if (!is_array($week)) {
                        continue;
                    }
                    
                    $weektitle = $week['title'] ?? "Week " . ($weekindex + 1);
                    $weeksummary = $week['summary'] ?? '';
                    $weekmetadata = !empty($week['learningactivity_metadata']) && is_array($week['learningactivity_metadata']) 
                        ? $week['learningactivity_metadata'] 
                        : [];
                    $sessions = !empty($week['sessions']) && is_array($week['sessions']) ? $week['sessions'] : [];
                    
                    try {
                        $weeksectionnum = \aiplacement_modgen\local\theme_builder::create_week_section(
                            $course->id,
                            $courseformat,
                            $themesectionnum,
                            $weektitle,
                            $weeksummary,
                            [
                                'collapsed' => 1,
                                'sessiondata' => $sessions,
                                'createactivities' => $createsuggestedactivities,
                                'metadata' => $weekmetadata,
                            ]
                        );
                        
                        // Create activities in sessions if requested
                        if ($createsuggestedactivities && !empty($sessions)) {
                            $sessionsectionmap = \aiplacement_modgen\local\session_creator::get_session_sections($weeksectionnum, $course->id);
                            
                            \aiplacement_modgen\local\session_creator::create_session_activities(
                                $sessions,
                                $sessionsectionmap,
                                $course,
                                $results,
                                $activitywarnings
                            );
                        }
                        
                        $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $weektitle);
                    } catch (\Exception $e) {
                        $activitywarnings[] = "Failed to create week '{$weektitle}': " . $e->getMessage();
                    }
                }
            }
        }
        
        return $results;
    }

    /**
     * Create weekly structure (connected_weekly or weekly).
     *
     * @param array $sections Array of section data
     * @param \stdClass $course Course object
     * @param \context_course $context Course context
     * @param object $courseformat Course format object
     * @param string $moduletype Module type
     * @param bool $hideexistingsections Whether to hide existing sections
     * @param array &$new_toplevel_section_ids Array to track new section IDs
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_weekly_structure(
        array $sections,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        string $moduletype,
        bool $createsuggestedactivities,
        bool $hideexistingsections,
        array &$new_toplevel_section_ids,
        array &$activitywarnings
    ): array {
        $results = [];
        
        foreach ($sections as $sectiondata) {
            if (!is_array($sectiondata)) {
                continue;
            }
            
            $title = $sectiondata['title'] ?? get_string('aigensummary', 'aiplacement_modgen');
            $summary = $sectiondata['summary'] ?? '';
            $outline = !empty($sectiondata['outline']) && is_array($sectiondata['outline']) ? $sectiondata['outline'] : [];
            
            $summaryhtml = trim(format_text($summary, FORMAT_HTML, ['context' => $context]));
            if (!empty($outline)) {
                $items = '';
                foreach ($outline as $entry) {
                    if (is_array($entry) && isset($entry['text'])) {
                        $items .= '<li>' . s($entry['text']) . '</li>';
                    } else if (is_string($entry)) {
                        $items .= '<li>' . s($entry) . '</li>';
                    }
                }
                if ($items !== '') {
                    $summaryhtml .= '<ul class="course-outline">' . $items . '</ul>';
                }
            }
            
            $hassessions = !empty($sectiondata['sessions']) && is_array($sectiondata['sessions']);
            
            if ($moduletype === 'connected_weekly') {
                $hassessions = true;
                if (empty($sectiondata['sessions']) || !is_array($sectiondata['sessions'])) {
                    $sectiondata['sessions'] = [
                        ['phase' => 'pre', 'description' => '', 'activities' => []],
                        ['phase' => 'session', 'description' => '', 'activities' => []],
                        ['phase' => 'post', 'description' => '', 'activities' => []],
                    ];
                }
            }
            
            try {
                $weekSessionData = $hassessions ? $sectiondata['sessions'] : null;
                $weekmetadata = !empty($sectiondata['learningactivity_metadata']) && is_array($sectiondata['learningactivity_metadata']) 
                    ? $sectiondata['learningactivity_metadata'] 
                    : [];
                    
                $weeksectionnum = \aiplacement_modgen\local\theme_builder::create_week_section(
                    $course->id,
                    $courseformat,
                    0,
                    $title,
                    $summaryhtml,
                    [
                        'collapsed' => $hassessions ? 1 : 0,
                        'sessiondata' => $weekSessionData,
                        'createactivities' => $createsuggestedactivities,
                        'metadata' => $weekmetadata,
                    ]
                );
                
                // Create activities if requested
                if ($createsuggestedactivities) {
                    if ($hassessions && !empty($weekSessionData)) {
                        // Has sessions - create activities in session subsections
                        $sessionsectionmap = \aiplacement_modgen\local\session_creator::get_session_sections($weeksectionnum, $course->id);
                        \aiplacement_modgen\local\session_creator::create_session_activities(
                            $weekSessionData,
                            $sessionsectionmap,
                            $course,
                            $results,
                            $activitywarnings
                        );
                    } else if (!empty($sectiondata['activities']) && is_array($sectiondata['activities'])) {
                        // No sessions - create activities directly in section
                        $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                            $sectiondata['activities'],
                            $course,
                            $weeksectionnum
                        );
                        
                        if (!empty($activityoutcome['created'])) {
                            $results = array_merge($results, $activityoutcome['created']);
                        }
                        if (!empty($activityoutcome['warnings'])) {
                            $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                        }
                    }
                }
                
                if ($hideexistingsections) {
                    global $DB;
                    $weeksectionid = $DB->get_field('course_sections', 'id', [
                        'course' => $course->id,
                        'section' => $weeksectionnum
                    ]);
                    if ($weeksectionid) {
                        $new_toplevel_section_ids[] = $weeksectionid;
                    }
                }
                
                $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            } catch (\Exception $e) {
                $activitywarnings[] = "Failed to create week section '{$title}': " . $e->getMessage();
            }
        }
        
        return $results;
    }

    /**
     * Hide existing sections and move new sections to top.
     *
     * @param int $courseid Course ID
     * @param \stdClass $course Course object
     * @param array $new_toplevel_section_ids Array of new section IDs
     */
    private function hide_and_reorder_sections(int $courseid, \stdClass $course, array $new_toplevel_section_ids): void {
        global $DB;
        
        // Hide all sections except section 0 and newly created ones
        $allsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        foreach ($allsections as $section) {
            if ($section->section == 0 || in_array($section->id, $new_toplevel_section_ids, true)) {
                continue;
            }
            
            if (!empty($section->parent) && in_array($section->parent, $new_toplevel_section_ids, true)) {
                continue;
            }
            
            $DB->set_field('course_sections', 'visible', 0, ['id' => $section->id]);
        }
        
        // Move new sections to top
        if (!empty($new_toplevel_section_ids)) {
            $courseformat = course_get_format($course);
            $modinfo = get_fast_modinfo($course);
            
            $anchorsectionnum = null;
            foreach ($modinfo->get_section_info_all() as $s) {
                if ($s->section > 0 && !in_array($s->id, $new_toplevel_section_ids, true) && empty($s->parent)) {
                    $anchorsectionnum = $s->section;
                    break;
                }
            }
            
            $anchor = $anchorsectionnum ?? 1;
            
            foreach (array_reverse($new_toplevel_section_ids) as $new_section_id) {
                $sectioninfo = null;
                foreach ($modinfo->get_section_info_all() as $s) {
                    if ($s->id == $new_section_id) {
                        $sectioninfo = $s;
                        break;
                    }
                }
                
                if ($sectioninfo && method_exists($courseformat, 'move_section')) {
                    try {
                        $courseformat->move_section($sectioninfo->section, $anchor, true);
                    } catch (\Exception $e) {
                        // Continue on error
                    }
                }
            }
            
            rebuild_course_cache($courseid, true, true);
        }
    }
}
