<?php

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Label activity type for creating label activities.
 */
class quiz implements activity_type {

    /** @inheritDoc */
    public static function get_type(): string {
        return 'quiz';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_quiz';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle quiz activity containing the supplied questions and settings. Supports multiple question types and assessment configurations.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        file_put_contents('/tmp/modgen_debug.log', "\n=== QUIZ CREATION START ===\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Activity data: " . print_r($activitydata, true) . "\n", FILE_APPEND);
        file_put_contents('/tmp/modgen_debug.log', "Course ID: {$course->id}, Section: $sectionnumber\n", FILE_APPEND);
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
            // Prepare the module information for quiz - minimal fields only
            $moduleinfo = new stdClass();
            $moduleinfo->course = $course->id;
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->section = $sectionnumber;
            $moduleinfo->visible = 1;
            $moduleinfo->name = $name;
            
            // Quiz intro
            $moduleinfo->introeditor = [
                'text' => $intro,
                'format' => 1,
                'itemid' => 0
            ];
            
            // Minimal quiz-specific fields
            $moduleinfo->introformat = 1;
            $moduleinfo->preferredbehaviour = 'deferredfeedback';
            $moduleinfo->questionsperpage = 1;
            $moduleinfo->navmethod = 'free';
            $moduleinfo->grade = 10;
            $moduleinfo->timeopen = 0;  // No time restriction
            $moduleinfo->timeclose = 0;  // No time restriction
            $moduleinfo->questiondecimalpoints = -1;  // Default decimal points
            
            // Required fields that quiz_process_options expects
            $moduleinfo->quizpassword = ''; // Gets converted to password by quiz_process_options
            $moduleinfo->feedbackboundarycount = -1; // Disable feedback processing

            file_put_contents('/tmp/modgen_debug.log', "Quiz module info prepared: " . print_r($moduleinfo, true) . "\n", FILE_APPEND);

            $cm = create_module($moduleinfo);
            file_put_contents('/tmp/modgen_debug.log', "Quiz creation SUCCESS: CM ID = " . $cm->coursemodule . "\n", FILE_APPEND);
            
            return [
                'coursemodule' => $cm->coursemodule,
                'instance' => $cm->instance
            ];
            
        } catch (\Exception $e) {
            file_put_contents('/tmp/modgen_debug.log', "QUIZ EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            return null;
        }
    }
}