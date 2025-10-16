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
        return 'A Moodle label to display text and information.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        file_put_contents('/tmp/modgen_debug.log', "\n=== LABEL CREATION DEBUG ===\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Activity data: " . print_r($activitydata, true) . "\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Course ID: " . $course->id . "\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Section number: " . $sectionnumber . "\n", FILE_APPEND);

        require_once($CFG->dirroot . '/course/modlib.php');

        // Extract name and intro, ensuring proper handling
        $name = trim($activitydata->name ?? '');
        $intro = trim($activitydata->intro ?? '');
        
        file_put_contents('/tmp/modgen_debug.log', "Processed name: '" . $name . "'\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Intro: '" . $intro . "'\n", FILE_APPEND);
        
        if ($name === '') {
            file_put_contents('/tmp/modgen_debug.log', "ERROR: Empty name, returning null\n", FILE_APPEND);
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