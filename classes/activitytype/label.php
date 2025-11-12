<?php

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Label activity type for creating label activities.
 */
class label implements activity_type {

    /** @inheritDoc */
    public static function get_type(): string {
        return 'label';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_label';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle label to display text and information. Can include HTML markup with Bootstrap 4/5 classes for layout purposes (cards, grid layouts, alerts, badges, etc.). Use HTML to create visually structured content sections.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        // Extract name and intro, ensuring proper handling
        $name = trim($activitydata->name ?? '');
        $intro = trim($activitydata->intro ?? '');
        
        if ($name === '') {
            return null;
        }

        try {
            // Prepare the module information for label
            $moduleinfo = new stdClass();
            $moduleinfo->course = $course->id;
            $moduleinfo->modulename = 'label';
            $moduleinfo->section = $sectionnumber;
            $moduleinfo->visible = 1;
            $moduleinfo->name = $name;
            $moduleinfo->cmidnumber = '';  // Course module ID number (optional identifier)
            
            // Label intro - labels use intro as the main content
            $moduleinfo->introeditor = [
                'text' => $intro,
                'format' => 1,
                'itemid' => 0
            ];
            
            // Label-specific fields
            $moduleinfo->introformat = 1;

            file_put_contents('/tmp/modgen_debug.log', "Label module info prepared: " . print_r($moduleinfo, true) . "\n", FILE_APPEND);

            $cm = create_module($moduleinfo);
            file_put_contents('/tmp/modgen_debug.log', "Label creation SUCCESS: CM ID = " . $cm->coursemodule . "\n", FILE_APPEND);
            
            return [
                'coursemodule' => $cm->coursemodule,
                'instance' => $cm->instance
            ];
            
        } catch (\Exception $e) {
            file_put_contents('/tmp/modgen_debug.log', "LABEL EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            return null;
        }
    }
}