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

namespace aiplacement_modgen\event;

/**
 * Fired when the post-build section renumber fails and the course is left out of order.
 *
 * theme_builder::renumber_into_hierarchy_order() deliberately swallows its own errors: the
 * generated content is already committed by the time it runs, so losing the tidy-up is
 * better than losing the build. That leaves the course in a state where
 * format_flexsections::delete_section_with_children() can remove the wrong content without
 * reporting an error (see integrity_checker::check_section_order()), so the swallowed
 * failure has to surface somewhere an administrator actually looks — hence a logged event
 * rather than a DEBUG_DEVELOPER message that production never displays.
 *
 * Read this event as: course {objectid} needs "Renumber Sections" on check_structure.php
 * before anyone deletes or reorganises sections on it.
 *
 * @package     aiplacement_modgen
 * @category    event
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property-read array $other {
 * @var string $reason Exception message that caused the renumber to be abandoned.
 * }
 */
class section_renumber_failed extends \core\event\base {
    /** @var int Longest reason string stored in the log, to keep the 'other' blob bounded. */
    private const REASON_MAX_LENGTH = 500;

    /**
     * Initialise the event data.
     */
    protected function init() {
        // Action 'u' because the intent was to update section numbering; LEVEL_OTHER because this
        // is an administrative integrity concern, not a teaching or learning action.
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Build the event for a course whose renumber was abandoned.
     *
     * Wraps the context lookup so callers do not have to: this is only ever raised from a
     * path that must not throw, and context_course::instance() throws when the course has
     * gone away underneath the build.
     *
     * @param int $courseid Course whose numbering was left out of order
     * @param \Throwable $reason What stopped the renumber
     * @return self|null Event ready to trigger, or null if it could not be built
     */
    public static function build(int $courseid, \Throwable $reason): ?self {
        try {
            $event = self::create([
                'context'  => \context_course::instance($courseid),
                'objectid' => $courseid,
                'other'    => [
                    'reason' => \core_text::substr($reason->getMessage(), 0, self::REASON_MAX_LENGTH),
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        return $event;
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_sectionrenumberfailed', 'aiplacement_modgen');
    }

    /**
     * Return a description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The post-build section renumber for course id '{$this->objectid}' failed, so its "
            . "section numbers no longer match the section hierarchy. Deleting a section on this "
            . "course can remove unrelated content without reporting an error. Run 'Renumber "
            . "Sections' on the course structure check page before reorganising it. "
            . "Reason: {$this->other['reason']}";
    }

    /**
     * Point the log report at the repair tool for this course.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/ai/placement/modgen/check_structure.php', [
            'id'    => $this->objectid,
            'check' => 1,
        ]);
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set to the course id.');
        }
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
        }
    }
}
