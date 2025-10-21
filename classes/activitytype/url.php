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
 * URL activity handler for creating Moodle URL modules (external links).
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates URL activities that link to external resources.
 */
class url implements activity_type {
    
    /** @inheritDoc */
    public static function get_type(): string {
        return 'url';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_url';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle URL activity that links to external websites, articles, videos, or resources. Ideal for directing students to external reading materials, reference sites, multimedia content, or context from other activities.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/url/lib.php');

        $name = trim($activitydata->name ?? '');
        
        if ($name === '') {
            error_log('URL: Empty name, returning null');
            return null;
        }

        // Extract URL from various possible field names
        $externalurl = trim($activitydata->externalurl ?? $activitydata->url ?? '');
        
        if ($externalurl === '') {
            error_log('URL: No externalurl or url field found, returning null');
            return null;
        }

        // Validate that this actually looks like a URL, not random text
        if (!$this->is_valid_url($externalurl)) {
            error_log('URL: Field does not appear to be a valid URL: ' . $externalurl);
            return null;
        }

        error_log('URL: Creating URL activity: ' . $name);
        error_log('URL: Course ID: ' . $course->id . ', Section: ' . $sectionnumber);
        error_log('URL: External URL: ' . $externalurl);

        $intro = trim($activitydata->intro ?? '');

        // Validate and ensure URL has a protocol
        $externalurl = $this->ensure_url_protocol($externalurl);

        // Create the URL module using minimal required fields
        $moduleinfo = new stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->modulename = 'url';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        
        // URL intro
        $moduleinfo->introeditor = [
            'text' => $intro,
            'format' => 1,
            'itemid' => 0
        ];
        
        // URL-specific fields
        $moduleinfo->introformat = 1;
        $moduleinfo->externalurl = $externalurl;
        $moduleinfo->display = 0;  // RESOURCELIB_DISPLAY_AUTO
        $moduleinfo->displayoptions = [];  // Will be serialized by create_module or url_add_instance
        $moduleinfo->printintro = 1;  // Show intro above content

        error_log('URL: Module info prepared');

        try {
            error_log('URL: Calling create_module');
            $cm = \create_module($moduleinfo);
            error_log('URL: create_module succeeded, result: ' . print_r($cm, true));
            
            if (is_object($cm)) {
                $urlid = $cm->instance ?? ($cm->id ?? null);
                $cmid = $cm->coursemodule ?? null;
            } else if (is_numeric($cm)) {
                $cmid = (int)$cm;
                // Get the instance ID from the course module
                $cmrec = $DB->get_record('course_modules', ['id' => $cmid]);
                $urlid = $cmrec ? $cmrec->instance : null;
            } else {
                error_log('URL: Unexpected create_module return type: ' . gettype($cm));
                return null;
            }

            if (empty($cmid) || empty($urlid)) {
                error_log('URL: Failed to get module IDs');
                return null;
            }

            error_log('URL: URL created with ID: ' . $urlid . ', CM ID: ' . $cmid);
            error_log('URL: Creation successful');

            return [
                'coursemodule' => $cmid,
                'instance' => $urlid
            ];
        } catch (\Exception $e) {
            error_log('URL: Exception caught: ' . $e->getMessage());
            error_log('URL: Trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Ensure URL has a protocol (http:// or https://).
     *
     * @param string $url The URL to validate and process.
     * @return string The URL with protocol prepended if necessary.
     */
    private function ensure_url_protocol(string $url): string {
        $url = trim($url);
        
        // Check if URL already has a protocol
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        
        // If URL looks like a domain, add https://
        if (preg_match('~^[a-z0-9]~i', $url) && !preg_match('~^/~', $url)) {
            return 'https://' . $url;
        }
        
        // Default to https:// for any other case
        return 'https://' . $url;
    }

    /**
     * Check if a string appears to be a valid URL.
     *
     * @param string $url The string to validate.
     * @return bool True if the string looks like a URL, false otherwise.
     */
    private function is_valid_url(string $url): bool {
        $url = trim($url);
        
        // Check for common URL patterns
        if (preg_match('~^(https?://|www\.|[a-z0-9]+\.[a-z]{2,})~i', $url)) {
            return true;
        }
        
        // Check for paths starting with /
        if (preg_match('~^/~', $url)) {
            return true;
        }
        
        // Looks like plain text, not a URL
        return false;
    }
}
