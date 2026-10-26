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
 * Behat data generator for aiplacement_modgen.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lets feature files create jobs:
 *
 *   Given the following "aiplacement_modgen > jobs" exist:
 *     | course | user | action | status | result |
 */
class behat_aiplacement_modgen_generator extends behat_generator_base {
    /**
     * Entities that can be created.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'jobs' => [
                'singular' => 'job',
                'datagenerator' => 'job',
                'required' => ['course', 'user'],
                'switchids' => ['course' => 'courseid', 'user' => 'userid'],
            ],

        ];
    }

    /**
     * Turn the result column of a job into data: it is written in the feature as JSON.
     *
     * @param array $data Job fields.
     * @return array
     */
    protected function preprocess_job(array $data): array {
        foreach (['result', 'parameters'] as $json) {
            if (isset($data[$json]) && $data[$json] !== '') {
                $decoded = json_decode($data[$json], true);
                if ($decoded === null) {
                    throw new Exception("The job '$json' must be valid JSON.");
                }
                $data[$json] = $decoded;
            } else {
                unset($data[$json]);
            }
        }
        return $data;
    }
}
