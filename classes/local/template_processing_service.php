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
 * Template processing service for Module Generator.
 *
 * Handles CSV template processing
 * and AI instruction building.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/csv_parser.php');
require_once(__DIR__ . '/ai_service.php');

require_once(__DIR__ . '/csv_parser.php');
require_once(__DIR__ . '/ai_service.php');

require_once(__DIR__ . '/csv_parser.php');
require_once(__DIR__ . '/ai_service.php');

/**
 * Service for processing templates and generating module structures.
 */
class template_processing_service {
    /**
     * Process template data and generate module structure.
     *
     * @param \stdClass $pdata Form data from the generator
     * @param int $courseid Course ID
     * @param string $compositeprompt The composite prompt for AI
     * @return array Generated JSON structure
     * @throws \Exception If template processing fails
     */
    public function process_and_generate(
        \stdClass $pdata,
        int $courseid,
        string $compositeprompt
    ): array {
        $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'connected_weekly';

        // Check if a CSV template was selected.
        $csvfile = $this->load_csv_template($pdata);

        $aienabled = get_config('aiplacement_modgen', 'enable_ai');
        $hasuserprompt = !empty($pdata->prompt) && trim($pdata->prompt) !== '';
        $hascsvfile = !empty($pdata->supportingfiles) || !empty($csvfile);

        $csvservice = new csv_processing_service();

        if ($csvservice->should_use_pure_csv_mode($aienabled, $hascsvfile, $hasuserprompt)) {
            return $this->process_pure_csv_mode($csvfile, $pdata, $moduletype);
        }

        return $this->process_with_ai_enhancement(
            $pdata,
            $courseid,
            $compositeprompt,
            $moduletype,
            $csvfile
        );
    }

    /**
     * Load CSV template - uploaded files take priority over dropdown selection.
     *
     * @param \stdClass $pdata Form data

     * @return \stored_file|null CSV file or null
     */
    private function load_csv_template(\stdClass $pdata): ?\stored_file {
        global $USER;

        // Check if user uploaded a file - this takes priority over template dropdown.
        if (!empty($pdata->supportingfiles)) {
            $draftitemid = $pdata->supportingfiles;
            $usercontext = \context_user::instance($USER->id);

            $fs = get_file_storage();
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);

            // Look for CSV file in uploaded files.
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }

                $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    return $file;
                }
            }

            // If user uploaded a non-CSV file, try to use it anyway.
            if (!empty($files)) {
                $file = array_shift($files);

                return $file;
            }
        }

        // Fall back to template dropdown only if no file was uploaded.
        $selectedtemplateid = !empty($pdata->selected_template_id) ? $pdata->selected_template_id : 0;

        if ($selectedtemplateid > 0) {
            require_once(__DIR__ . '/template_manager.php');

            try {
                $template = template_manager::get_by_id($selectedtemplateid);
                $fs = get_file_storage();
                $csvfile = $fs->get_file_by_id($template->fileid);

                if (!$csvfile) {
                    throw new \moodle_exception('csvfilenotfound', 'aiplacement_modgen');
                }

                return $csvfile;
            } catch (\Exception $e) {
                throw new \moodle_exception('templateloaderror', 'aiplacement_modgen', '', $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Process in pure CSV mode (no AI).
     *
     * @param \stored_file|null $csvfile CSV file
     * @param \stdClass $pdata Form data
     * @param string $moduletype Module type
     * @return array Parsed CSV structure
     * @throws \Exception If CSV file is missing
     */
    private function process_pure_csv_mode(?\stored_file $csvfile, \stdClass $pdata, string $moduletype): array {
        global $USER;

        if (empty($csvfile)) {
            $draftitemid = $pdata->supportingfiles;
            $usercontext = \context_user::instance($USER->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);

            if (empty($files)) {
                throw new \moodle_exception('csvnodraft', 'aiplacement_modgen');
            }

            $csvfile = array_shift($files);
        }

        // Auto-detect CSV format if needed.
        if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
            $detectedformat = csv_parser::detect_csv_format($csvfile);
            $moduletype = $detectedformat;
        }

        $result = csv_parser::parse_csv_to_structure($csvfile, $moduletype);

        // Return structure with the detected/used module type.
        $result['_detected_moduletype'] = $moduletype;

        return $result;
    }

    /**
     * Process with AI enhancement.
     *
     * @param \stdClass $pdata Form data
     * @param int $courseid Course ID
     * @param string $compositeprompt Composite prompt
     * @param string $moduletype Module type
     * @param \stored_file|null $csvfile CSV file if loaded
     * @return array Generated JSON structure
     */
    private function process_with_ai_enhancement(
        \stdClass $pdata,
        int $courseid,
        string $compositeprompt,
        string $moduletype,
        ?\stored_file $csvfile
    ): array {
        global $USER;

        $csvstructure = null;
        $hascsvfile = !empty($pdata->supportingfiles) || !empty($csvfile);

        if ($hascsvfile) {
            if (empty($csvfile)) {
                $usercontext = \context_user::instance($USER->id);
                $csvservice = new csv_processing_service();
                $csvfile = $csvservice->get_csv_file($csvfile, $pdata->supportingfiles, $usercontext->id);
            }

            if (!empty($csvfile)) {
                if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                    $detectedformat = csv_parser::detect_csv_format($csvfile);
                    $moduletype = $detectedformat;
                }

                $csvstructure = csv_parser::parse_csv_to_structure($csvfile, $moduletype);
            }
        }

        // Build AI instructions.
        $aiinstructions = $this->build_ai_instructions($csvstructure);
        $compositeprompt = $compositeprompt . $aiinstructions;

        $result = \aiplacement_modgen\ai_service::generate_module(
            $compositeprompt,
            $moduletype,
            $courseid
        );

        // Return structure with the detected/used module type.
        $result['_detected_moduletype'] = $moduletype;

        return $result;
    }

    /**
     * Build AI instructions based on the CSV structure.
     *
     * @param array|null $csvstructure CSV structure if available
     * @return string AI instructions
     */
    private function build_ai_instructions(?array $csvstructure): string {
        if ($csvstructure === null) {
            return '';
        }

        // Count themes/weeks.
        $themecount = 0;
        $weekcount = 0;

        if (!empty($csvstructure['themes']) && is_array($csvstructure['themes'])) {
            $themecount = count($csvstructure['themes']);
            foreach ($csvstructure['themes'] as $theme) {
                if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                    $weekcount += count($theme['weeks']);
                }
            }
        }

        $instructions = "\n\n*** BASE STRUCTURE FROM CSV ***\n";
        $instructions .= json_encode($csvstructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $instructions .= "\n\n*** CRITICAL STRUCTURAL REQUIREMENTS ***\n";
        $instructions .= "You MUST preserve the exact structure from the CSV:\n";
        $instructions .= "- Create EXACTLY " . $themecount . " themes with " . $weekcount . " weeks total\n";
        $instructions .= "- Do NOT add extra themes, weeks, or sessions\n";
        $instructions .= "- Do NOT remove any themes, weeks, or sessions\n";
        $instructions .= "- Do NOT merge or split sections\n";
        $instructions .= "- Maintain the EXACT organizational hierarchy\n";
        $instructions .= "- Keep the SAME session structure within each theme/week\n";
        $instructions .= "- Your output MUST have EXACTLY " . $themecount . " themes (this is non-negotiable)\n";
        $instructions .= "- Return ONLY the exact structure shown above - no modifications to theme/week count\n\n";
        $instructions .= "- The number of sections in your output MUST match the CSV exactly\n\n";

        $instructions .= "*** NO TITLE CHANGES ***\n";
        $instructions .= "- Do NOT modify section titles from the CSV\n";
        $instructions .= "- Return titles exactly as provided\n";
        $instructions .= "- Only add content if explicitly requested\n";

        return $instructions;
    }
}
