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
 * Course toolbar output component.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\output;

use renderable;
use templatable;
use renderer_base;
use stdClass;
use moodle_url;

/**
 * Course toolbar renderable.
 */
class course_toolbar implements renderable, templatable {
    /** @var int Course ID */
    private $courseid;
    
    /** @var bool Whether to show generator button */
    private $showgenerator;
    
    /** @var bool Whether to show explore button */
    private $showexplore;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param bool $showgenerator Whether to show generator button
     * @param bool $showexplore Whether to show explore button
     */
    public function __construct(int $courseid, bool $showgenerator, bool $showexplore) {
        $this->courseid = $courseid;
        $this->showgenerator = $showgenerator;
        $this->showexplore = $showexplore;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output Renderer
     * @return stdClass Template data
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $data->navtitle = get_string('navtitle', 'aiplacement_modgen');
        $data->showgenerator = $this->showgenerator;
        $data->showexplore = $this->showexplore;
        
        if ($this->showgenerator) {
            $generatorurl = new moodle_url('/ai/placement/modgen/modal.php', ['id' => $this->courseid]);
            $data->generatorurl = $generatorurl->out(false);
            $data->generatorlabel = get_string('generatorbutton', 'aiplacement_modgen');
        }
        
        if ($this->showexplore) {
            $exploreurl = new moodle_url('/ai/placement/modgen/explore.php', ['id' => $this->courseid]);
            $data->exploreurl = $exploreurl->out(false);
            $data->explorelabel = get_string('explorebutton', 'aiplacement_modgen');
        }
        
        return $data;
    }
}
