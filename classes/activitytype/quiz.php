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

namespace local_aiplacement_modgen\activitytype;

use cache_helper;
use cm_info;
use context_course;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/activity_type.php');

/**
 * Creates quizzes defined by AI responses.
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
        return 'A Moodle quiz activity containing the supplied questions and settings.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/course/modlib.php');

        $name = trim(format_string($activitydata->name ?? '', true, ['context' => context_course::instance($course->id)]));
        if ($name === '') {
            return null;
        }

        $intro = trim($activitydata->intro ?? '');
        $moduleinfo = prepare_new_moduleinfo_data($course, cm_info::MODULE_UNKNOWN);
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        $moduleinfo->intro = $intro;
        $moduleinfo->introformat = FORMAT_HTML;

        if (!empty($activitydata->timeopen)) {
            $moduleinfo->timeopen = (int) $activitydata->timeopen;
        }
        if (!empty($activitydata->timeclose)) {
            $moduleinfo->timeclose = (int) $activitydata->timeclose;
        }

        $moduleinfo = add_moduleinfo($moduleinfo, $course, null);

        // Ensure course cache reflects newly created CM.
        cache_helper::purge_course_modinfo($course->id);

        return [
            'cmid' => $moduleinfo->coursemodule,
            'message' => get_string('quizcreated', 'aiplacement_modgen', $name),
        ];
    }
}
