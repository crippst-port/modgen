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
    
    /** @var bool Whether to show suggest button */
    private $showsuggest;
    
    /** @var bool Whether to show manage structure buttons (themes/weeks) */
    private $showmanagestructure;
    
    /** @var bool Whether to show manage dates button */
    private $showmanagedates;
    
    /** @var bool Whether to show template from file button */
    private $showtemplatefromfile;
    
    /** @var bool Whether to show template from prompt button */
    private $showtemplatefromptompt;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param bool $showgenerator Whether to show generator button (legacy, use showtemplatefromptompt)
     * @param bool $showsuggest Whether to show suggest button
     * @param bool $showmanagestructure Whether to show structure management buttons
     * @param bool $showmanagedates Whether to show dates to sections button
     * @param bool $showtemplatefromfile Whether to show template from file button
     * @param bool $showtemplatefromptompt Whether to show template from prompt button
     */
    public function __construct(
        int $courseid, 
        bool $showgenerator = false, 
        bool $showsuggest = false,
        bool $showmanagestructure = false,
        bool $showmanagedates = false,
        bool $showtemplatefromfile = false,
        bool $showtemplatefromptompt = false
    ) {
        $this->courseid = $courseid;
        $this->showgenerator = $showgenerator || $showtemplatefromptompt; // Legacy support
        $this->showsuggest = $showsuggest;
        $this->showmanagestructure = $showmanagestructure;
        $this->showmanagedates = $showmanagedates;
        $this->showtemplatefromfile = $showtemplatefromfile;
        $this->showtemplatefromptompt = $showtemplatefromptompt;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output Renderer
     * @return stdClass Template data
     */
    public function export_for_template(renderer_base $output): stdClass {
        global $DB;
        
        $data = new stdClass();
        $data->showgenerator = $this->showgenerator;
        $data->showsuggest = $this->showsuggest;
        $data->showmanagestructure = $this->showmanagestructure;
        $data->showmanagedates = $this->showmanagedates;
        $data->showtemplatefromfile = $this->showtemplatefromfile;
        $data->showtemplatefromptompt = $this->showtemplatefromptompt;
        
        // Always provide generator URL (needed for "Template from file" link)
        $generatorurl = new moodle_url('/ai/placement/modgen/modal.php', ['id' => $this->courseid]);
        $data->generatorurl = $generatorurl->out(false);
        
        // Get count of unedited AI-generated activities in this course.
        // Use the same logic as aigen_list.php - check if modules exist in modinfo and clean up orphans.
        $sql = "SELECT ag.id, ag.cmid
                  FROM {aiplacement_modgen_aigen} ag
                  JOIN {course_modules} cm ON cm.id = ag.cmid
                 WHERE ag.courseid = :courseid
                   AND cm.deletioninprogress = 0";
        
        $records = $DB->get_records_sql($sql, ['courseid' => $this->courseid]);
        $course = get_course($this->courseid);
        $modinfo = get_fast_modinfo($course);
        
        $validcount = 0;
        foreach ($records as $record) {
            if (!isset($modinfo->cms[$record->cmid])) {
                // Activity no longer exists in modinfo, clean up the record.
                $DB->delete_records('aiplacement_modgen_aigen', ['id' => $record->id]);
            } else {
                $validcount++;
            }
        }
        
        $data->aigencount = $validcount;
        $data->hasaigen = $validcount > 0;
        
        if ($data->hasaigen) {
            $aigenlisturl = new moodle_url('/ai/placement/modgen/aigen_list.php', ['id' => $this->courseid]);
            $data->aigenlisturl = $aigenlisturl->out(false);
        }
        
        // Get help links from settings
        $helplinks = [];
        for ($i = 1; $i <= 5; $i++) {
            $text = get_config('aiplacement_modgen', "helplink{$i}_text");
            $url = get_config('aiplacement_modgen', "helplink{$i}_url");
            
            // Only include links that have both text and URL
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
