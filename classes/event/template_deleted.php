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
 * A structure template was removed from the library.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\event;

/**
 * A structure template was removed from the library.
 */
class template_deleted extends \core\event\base {
    /**
     * Create the event for a template.
     *
     * @param int $templateid Template id.
     * @param string $name Template name at the time.
     * @param array $other Extra event data.
     * @return self
     */
    public static function for_template(int $templateid, string $name, array $other = []): self {
        return self::create([
            'context' => \context_system::instance(),
            'objectid' => $templateid,
            'other' => ['name' => $name] + $other,
        ]);
    }

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'aiplacement_modgen_templates';
    }

    /**
     * Get the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtemplate_deleted', 'aiplacement_modgen');
    }

    /**
     * Get a description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' deleted the structure template with id '{$this->objectid}' " .
            "('{$this->other['name']}').";
    }

    /**
     * Get the template management page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/ai/placement/modgen/manage_templates.php');
    }

    /**
     * Check the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['name'])) {
            throw new \coding_exception('The \'name\' value must be set in other.');
        }
    }

    /**
     * Templates are not backed up, so the object id cannot be mapped on restore.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }

    /**
     * Other data holds no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
