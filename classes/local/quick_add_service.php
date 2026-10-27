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
 * Validation and queuing for the Quick Add forms.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Checks a Quick Add request and queues the background job that builds it.
 *
 * The endpoint authenticates the user and checks the capability; the rules about what may be
 * requested live here so they can be tested without a web request.
 */
class quick_add_service {
    /**
     * Queue the creation of themes (each with weeks and sessions).
     *
     * @param int $courseid Course to build in.
     * @param int $userid User the job runs as.
     * @param int $themecount Number of themes.
     * @param int $weeksperthemecount Weeks in each theme.
     * @param int $parentsection Section the request was made from (0 at the top of the course).
     * @param bool $createsummaryactivities Whether to create the section summary placeholders.
     * @return array With 'jobid' and 'total' (how many sections the job will create).
     * @throws \moodle_exception If the request is not allowed or would not fit in the course.
     */
    public static function queue_themes(
        int $courseid,
        int $userid,
        int $themecount,
        int $weeksperthemecount,
        int $parentsection,
        bool $createsummaryactivities
    ): array {
        // Themes are top-level containers: refuse them when the request comes from inside a section.
        if ($parentsection > 0) {
            throw new \moodle_exception('themetoplevelonly', 'aiplacement_modgen');
        }

        $maxsections = self::max_quick_sections();
        if ($themecount < 1 || $themecount > $maxsections) {
            throw new \moodle_exception('invalidthemecount', 'aiplacement_modgen', '', $maxsections);
        }
        $maxweeks = (int) get_config('aiplacement_modgen', 'maxweeksperTheme') ?: 5;
        if ($weeksperthemecount < 1 || $weeksperthemecount > $maxweeks) {
            throw new \moodle_exception('invalidweeksperTheme', 'aiplacement_modgen', '', $maxweeks);
        }

        $total = theme_builder::projected_sections_for_themes($themecount, $weeksperthemecount);

        // Refuse now rather than queue a job that is certain to fail in the background.
        theme_builder::enforce_section_limit($courseid, $total);

        $jobid = job_status_helper::queue_creation_job($courseid, $userid, 'create_themes', [
            'themecount' => $themecount,
            'weeksperTheme' => $weeksperthemecount,
            'parentsection' => $parentsection,
            'parentsectionid' => self::section_id($courseid, $parentsection),
            'createsummaryactivities' => $createsummaryactivities,
        ]);

        return ['jobid' => $jobid, 'total' => $total];
    }

    /**
     * Queue the creation of standalone weeks (each with its sessions).
     *
     * @param int $courseid Course to build in.
     * @param int $userid User the job runs as.
     * @param int $weekcount Number of weeks.
     * @param int $parentsection Section to add the weeks within (0 for the top of the course).
     * @param bool $createsummaryactivities Whether to create the section summary placeholders.
     * @return array With 'jobid' and 'total' (how many sections the job will create).
     * @throws \moodle_exception If the request is not allowed or would not fit in the course.
     */
    public static function queue_weeks(
        int $courseid,
        int $userid,
        int $weekcount,
        int $parentsection,
        bool $createsummaryactivities
    ): array {
        $maxsections = self::max_quick_sections();
        if ($weekcount < 1 || $weekcount > $maxsections) {
            throw new \moodle_exception('invalidweekcount', 'aiplacement_modgen', '', $maxsections);
        }

        $total = theme_builder::projected_sections_for_weeks($weekcount);

        theme_builder::enforce_section_limit($courseid, $total);

        $jobid = job_status_helper::queue_creation_job($courseid, $userid, 'create_weeks', [
            'weekcount' => $weekcount,
            'parentsection' => $parentsection,
            'parentsectionid' => self::section_id($courseid, $parentsection),
            'createsummaryactivities' => $createsummaryactivities,
        ]);

        return ['jobid' => $jobid, 'total' => $total];
    }

    /**
     * The most themes or weeks one Quick Add may create.
     *
     * @return int
     */
    private static function max_quick_sections(): int {
        return (int) get_config('aiplacement_modgen', 'maxquicksections') ?: 30;
    }

    /**
     * Pin a section number to a stable section id.
     *
     * Section numbers are positional in format_flexsections and are rewritten whenever anyone
     * creates, moves, deletes or merges a section. The job runs later, from cron, so a number
     * captured now may name a different section by then; the task converts the id back to a
     * number when it runs.
     *
     * @param int $courseid Course id.
     * @param int $sectionnumber Section number, 0 for none.
     * @return int|null Section id, or null for the top of the course.
     * @throws \moodle_exception If the section does not exist: queueing it would leave the task
     *     to guess from a number that may name another section by the time it runs.
     */
    private static function section_id(int $courseid, int $sectionnumber): ?int {
        global $DB;

        if ($sectionnumber <= 0) {
            return null;
        }
        $id = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $sectionnumber]);
        if (!$id) {
            throw new \moodle_exception('invalidparentsection', 'aiplacement_modgen', '', $sectionnumber);
        }
        return (int) $id;
    }
}
