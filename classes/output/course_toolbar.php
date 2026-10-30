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
 *
 * Shows the entries it is given: aiplacement_modgen_toolbar_entries() decides what a user may use.
 */
class course_toolbar implements renderable, templatable {
    /** @var int Course ID */
    private $courseid;

    /** @var bool[] Entries to show, keyed as aiplacement_modgen_toolbar_entries() returns them */
    private $entries;

    /** @var string Why nothing can be built in the course, shown in place of the menus; empty when it can */
    private $unavailable = '';

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param bool[] $entries Entries to show: newtheme, newweek, assessments, fromfile, fromprompt, checkstructure
     */
    public function __construct(int $courseid, array $entries) {
        $this->courseid = $courseid;
        $names = ['newtheme', 'newweek', 'assessments', 'fromfile', 'fromprompt', 'checkstructure'];
        $this->entries = array_map(fn($name) => !empty($entries[$name]), array_combine($names, $names));
    }

    /**
     * Say why nothing can be built in the course: the toolbar shows this instead of its menus.
     *
     * @param string $reason Reason, as plain text.
     */
    public function set_unavailable(string $reason): void {
        $this->unavailable = $reason;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output Renderer
     * @return stdClass Template data
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $data->logourl = $output->image_url('compact_logo', 'aiplacement_modgen')->out(false);
        $data->shownewtheme = $this->entries['newtheme'];
        $data->shownewweek = $this->entries['newweek'];
        $data->showassessments = $this->entries['assessments'];
        $data->showquickadd = $data->shownewtheme || $data->shownewweek || $data->showassessments;
        $data->showtemplatefromfile = $this->entries['fromfile'];
        $data->showgenerator = $this->entries['fromprompt'];
        $data->showcheckstructure = $this->entries['checkstructure'];
        // The Quick Add menu holds every entry but Check Structure, so it shows if any of them does.
        $data->showquickaddmenu = $data->showquickadd || $data->showtemplatefromfile || $data->showgenerator;
        // Every entry in it builds: when nothing can be built, the reason takes its place.
        if ($this->unavailable !== '' && $data->showquickaddmenu) {
            $data->showquickaddmenu = false;
            $data->unavailable = $this->unavailable;
        }
        if ($data->showcheckstructure) {
            $checkstructureurl = new moodle_url(
                '/ai/placement/modgen/check_structure.php',
                ['id' => $this->courseid, 'check' => 1]
            );
            $data->checkstructureurl = $checkstructureurl->out(false);
        }

        // Always provide generator URL (needed for "Template from file" link).
        $generatorurl = new moodle_url('/ai/placement/modgen/modal.php', ['id' => $this->courseid]);
        $data->generatorurl = $generatorurl->out(false);

        // Get help links from settings.
        $helplinks = [];
        for ($i = 1; $i <= 5; $i++) {
            $text = get_config('aiplacement_modgen', "helplink{$i}_text");
            $url = get_config('aiplacement_modgen', "helplink{$i}_url");

            // Only include links that have both text and URL.
            if (!empty($text) && !empty($url)) {
                $helplinks[] = [
                    'text' => $text,
                    'url' => $url,
                ];
            }
        }

        $data->helplinks = $helplinks;
        $data->showhelplinks = !empty($helplinks);

        return $data;
    }
}
