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
 * Detects content already in a module, to warn before a file or prompt build.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Detects content already in a module, to warn before a file or prompt build.
 *
 * New modules often come with several empty sections, so sections alone never count, whatever
 * their number or names. Content is an activity or resource (other than the Announcements forum
 * Moodle adds by itself, and the modules Flexible sections uses to hold subsections), or a section
 * inside another one, which means a theme and week structure has already been built.
 */
class existing_content {
    /**
     * Whether the module has activities or resources of its own.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function has_activities(int $courseid): bool {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
          LEFT JOIN {forum} f ON m.name = 'forum' AND f.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0
                AND m.name <> 'subsection'
                AND (f.id IS NULL OR f.type <> 'news')",
            ['courseid' => $courseid]
        );
    }

    /**
     * Whether the module has a section inside another one.
     *
     * Flexible sections stores the parent as a format option, 0 or empty at the top level; options left
     * over from when the course used another format do not count. Core subsections are delegated sections.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function has_nested_sections(int $courseid): bool {
        global $DB;

        $value = $DB->sql_compare_text('cfo.value', 10);
        $nested = $DB->record_exists_sql(
            "SELECT 1
               FROM {course_format_options} cfo
               JOIN {course} c ON c.id = cfo.courseid AND c.format = cfo.format
               JOIN {course_sections} cs ON cs.id = cfo.sectionid
              WHERE cfo.courseid = :courseid
                AND cfo.format = 'flexsections'
                AND cfo.name = 'parent'
                AND $value <> '' AND $value <> '0'",
            ['courseid' => $courseid]
        );
        return $nested || $DB->record_exists_select(
            'course_sections',
            'course = :courseid AND component IS NOT NULL',
            ['courseid' => $courseid]
        );
    }

    /**
     * Add a warning to a build form when the module already has content.
     *
     * @param \MoodleQuickForm $mform The form.
     * @param int $courseid Course the build is for.
     */
    public static function add_warning(\MoodleQuickForm $mform, int $courseid): void {
        global $OUTPUT;
        if (!self::has_activities($courseid) && !self::has_nested_sections($courseid)) {
            return;
        }

        $message = get_string('existingcontentwarning', 'aiplacement_modgen');
        $mform->addElement('html', $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING, false));
    }
}
