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

    /** @var bool Whether to show AI prompt generator button */
    private $showgenerator;

    /** @var bool Whether to show manage structure buttons (themes/weeks) */
    private $showmanagestructure;

    /** @var bool Whether to show template from file button */
    private $showtemplatefromfile;

    /** @var bool Whether to show the Quick add options (New theme / New week) */
    private $showquickadd;

    /** @var bool Whether to show the New Theme entry (top of the course only) */
    private $showaddtheme;

    /** @var bool Whether to show template from prompt button */
    private $showtemplatefromptompt;

    /** @var bool Whether to show check structure button */
    private $showcheckstructure;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param bool $showgenerator Whether to show generator button (legacy, use showtemplatefromptompt)
     * @param bool $showmanagestructure Whether to show structure management buttons
     * @param bool $showtemplatefromfile Whether to show template from file button
     * @param bool $showtemplatefromptompt Whether to show template from prompt button
     * @param bool $showcheckstructure Whether to show check structure button
     * @param bool $showquickadd Whether to show the Quick add options
     * @param bool $showaddtheme Whether to show the New Theme entry
     */
    public function __construct(
        int $courseid,
        bool $showgenerator = false,
        bool $showmanagestructure = false,
        bool $showtemplatefromfile = false,
        bool $showtemplatefromptompt = false,
        bool $showcheckstructure = false,
        bool $showquickadd = false,
        bool $showaddtheme = true
    ) {
        $this->courseid = $courseid;
        $this->showgenerator = $showgenerator || $showtemplatefromptompt; // Legacy support.
        $this->showmanagestructure = $showmanagestructure;
        $this->showtemplatefromfile = $showtemplatefromfile;
        $this->showtemplatefromptompt = $showtemplatefromptompt;
        $this->showcheckstructure = $showcheckstructure;
        $this->showquickadd = $showquickadd;
        $this->showaddtheme = $showaddtheme;
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
        $data->showgenerator = $this->showgenerator;
        $data->showmanagestructure = $this->showmanagestructure;
        $data->showtemplatefromfile = $this->showtemplatefromfile;
        $data->showtemplatefromptompt = $this->showtemplatefromptompt;
        $data->showcheckstructure = $this->showcheckstructure;
        $data->showquickadd = $this->showquickadd;
        $data->showaddtheme = $this->showaddtheme;
        // The Quick Add menu also holds Structure from file and the AI generator, so it shows if any entry does.
        $data->showquickaddmenu = $this->showquickadd || $this->showtemplatefromfile || $this->showgenerator;
        if ($this->showcheckstructure) {
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
