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
 * Which course formats the Module Assistant builds in, and what each can hold.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Which course formats the Module Assistant builds in, and what each can hold.
 *
 * Flexible sections nests sections to any depth, so it takes every structure: themes, their weeks
 * and each week's sessions. Custom sections and Weekly sections nest one level, with core
 * subsections, so they take weeks with their sessions as subsections and nothing that needs
 * themes. The plugin never changes a course's format: a course in any other format is refused.
 */
class course_formats {
    /** @var string Flexible sections. */
    public const FLEXSECTIONS = 'flexsections';

    /** @var string[] Core formats built with subsections: Custom sections and Weekly sections. */
    public const CORE_FORMATS = ['topics', 'weeks'];

    /**
     * @var int Oldest format_flexsections (4.1.4) a build runs against.
     *
     * 4.1.4 added the lock around delete_section_with_children() that
     * theme_builder::acquire_structure_locks() takes to keep a teacher's section delete out of a
     * build. Earlier versions have every method used here, but take no lock, so a delete could
     * interleave with a build without anything noticing.
     */
    public const FLEXSECTIONS_MIN_VERSION = 2024120801;

    /**
     * The course's format.
     *
     * @param int $courseid Course ID.
     * @return string
     */
    public static function format(int $courseid): string {
        global $DB;
        return (string) $DB->get_field('course', 'format', ['id' => $courseid], MUST_EXIST);
    }

    /**
     * Whether the course uses Flexible sections.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function is_flexsections(int $courseid): bool {
        return self::format($courseid) === self::FLEXSECTIONS;
    }

    /**
     * Whether the course uses Custom sections or Weekly sections.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function is_core(int $courseid): bool {
        return in_array(self::format($courseid), self::CORE_FORMATS, true);
    }

    /**
     * Whether the Subsection activity is enabled on the site.
     *
     * @return bool
     */
    public static function subsections_enabled(): bool {
        return array_key_exists('subsection', \core_plugin_manager::instance()->get_enabled_plugins('mod'));
    }

    /**
     * Why the Module Assistant cannot build in a course, or null when it can.
     *
     * @param int $courseid Course ID.
     * @return string|null Error string identifier in aiplacement_modgen.
     */
    public static function unsupported_reason(int $courseid): ?string {
        $format = self::format($courseid);

        if ($format === self::FLEXSECTIONS) {
            $plugin = \core_plugin_manager::instance()->get_plugin_info('format_flexsections');
            if (!$plugin || (int) $plugin->versiondisk < self::FLEXSECTIONS_MIN_VERSION) {
                return 'errorflexsectionstooold';
            }
            return null;
        }

        if (in_array($format, self::CORE_FORMATS, true)) {
            return self::subsections_enabled() ? null : 'errorsubsectionsdisabled';
        }

        return 'errorformatnotsupported';
    }

    /**
     * Refuse a course the Module Assistant cannot build in.
     *
     * Every way into a build checks this: a page or request reached by URL, and a queued job whose
     * course was switched to another format, or whose site switched subsections off, after it was queued.
     *
     * @param int $courseid Course ID.
     * @throws \moodle_exception If the course's format is not supported, or cannot be built in on this site.
     */
    public static function require_supported(int $courseid): void {
        $reason = self::unsupported_reason($courseid);
        if ($reason !== null) {
            throw new \moodle_exception($reason, 'aiplacement_modgen');
        }
    }

    /**
     * Whether the course can hold themes: a theme holds weeks, which hold sessions, two levels of nesting.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    public static function supports_themes(int $courseid): bool {
        return self::is_flexsections($courseid);
    }

    /**
     * Refuse themes in a course that cannot hold them.
     *
     * @param int $courseid Course ID.
     * @throws \moodle_exception If the course is not in Flexible sections.
     */
    public static function require_themes(int $courseid): void {
        if (!self::supports_themes($courseid)) {
            throw new \moodle_exception('errorthemesneedflexsections', 'aiplacement_modgen');
        }
    }

    /**
     * Refuse a structure from a file or the AI that the course cannot hold.
     *
     * A structure needs themes when it is of the theme type or lists any themes, whatever its type says:
     * it may have come back from the browser.
     *
     * @param int $courseid Course ID.
     * @param string $moduletype Module type (connected_theme, connected_weekly).
     * @param array $json The structure.
     * @throws \moodle_exception If the structure has themes and the course cannot hold them.
     */
    public static function require_structure_fits(int $courseid, string $moduletype, array $json): void {
        if ($moduletype === 'connected_theme' || !empty($json['themes'])) {
            self::require_themes($courseid);
        }
    }
}
