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
 * CSV processing decision service.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Service class for determining CSV processing mode.
 * 
 * Consolidates the complex boolean logic repeated 4 times in prompt.php
 * to decide whether to use pure CSV parsing or AI enhancement.
 */
class csv_processing_service {
    
    /**
     * Determine if pure CSV parsing should be used (without AI enhancement).
     *
     * Pure CSV parsing is used only if:
     * - AI is disabled in plugin settings, OR
     * - AI is enabled AND has CSV file AND no user prompt AND no expand option AND no example generation
     *
     * @param bool $aienabled Whether AI is enabled in plugin settings
     * @param bool $hascsvfile Whether a CSV file is present
     * @param bool $hasuserprompt Whether user provided a text prompt
     * @param bool $expandonthemes Whether 'expand on themes' is checked
     * @param bool $generateexamples Whether 'generate examples' is checked
     * @return bool True if pure CSV parsing should be used
     */
    public function should_use_pure_csv_mode(
        bool $aienabled,
        bool $hascsvfile,
        bool $hasuserprompt,
        bool $expandonthemes,
        bool $generateexamples
    ): bool {
        // If AI is completely disabled, always use pure CSV
        if (!$aienabled) {
            return true;
        }
        
        // AI is enabled - use pure CSV only if: has CSV + no prompt + no expand + no examples
        return $hascsvfile && !$hasuserprompt && !$expandonthemes && !$generateexamples;
    }
    
    /**
     * Get CSV file from template or uploaded files.
     *
     * @param \stored_file|null $templatecsvfile CSV file from template (if selected)
     * @param int $draftitemid Draft item ID from file upload
     * @param int $contextid User context ID
     * @return \stored_file|null CSV file or null if not found
     */
    public function get_csv_file(?\stored_file $templatecsvfile, int $draftitemid, int $contextid): ?\stored_file {
        // If CSV file provided (uploaded file takes priority over template), use it
        if ($templatecsvfile !== null) {
            return $templatecsvfile;
        }
        
        // Otherwise, try to find CSV in uploaded files
        if (empty($draftitemid)) {
            return null;
        }
        
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'user', 'draft', $draftitemid, 'filename', false);
        
        if (empty($files)) {
            return null;
        }
        
        // Find first CSV file
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                return $file;
            }
        }
        
        // If no CSV found, return first file (might be processable as CSV)
        return array_shift($files);
    }
    
    /**
     * Build AI enhancement instructions for CSV-based generation.
     *
     * @param array $csvstructure Parsed CSV structure
     * @param bool $expandonthemes Whether to expand theme titles
     * @param string $moduletype Module type (theme, connected_theme, etc)
     * @return string AI instructions to append to prompt
     */
    public function build_csv_enhancement_instructions(
        array $csvstructure,
        bool $expandonthemes,
        string $moduletype
    ): string {
        // Count themes/weeks for explicit instruction
        $themecount = 0;
        $weekcount = 0;
        
        if (!empty($csvstructure['themes']) && is_array($csvstructure['themes'])) {
            $themecount = count($csvstructure['themes']);
            foreach ($csvstructure['themes'] as $theme) {
                if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                    $weekcount += count($theme['weeks']);
                }
            }
        } else if (!empty($csvstructure['sections']) && is_array($csvstructure['sections'])) {
            $weekcount = count($csvstructure['sections']);
        }
        
        $instructions = "\n\n*** BASE STRUCTURE FROM CSV ***\n";
        $instructions .= json_encode($csvstructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $instructions .= "\n\n*** CRITICAL STRUCTURAL REQUIREMENTS ***\n";
        $instructions .= "You MUST preserve the exact structure from the CSV:\n";
        
        if ($themecount > 0) {
            $instructions .= "- Create EXACTLY {$themecount} themes with {$weekcount} weeks total\n";
        } else {
            $instructions .= "- Create EXACTLY {$weekcount} sections\n";
        }
        
        $instructions .= "- Do NOT add extra themes, weeks, or sessions\n";
        $instructions .= "- Do NOT remove any themes, weeks, or sessions\n";
        $instructions .= "- Do NOT merge or split sections\n";
        $instructions .= "- Maintain the EXACT organizational hierarchy\n";
        $instructions .= "- Keep the SAME session structure within each theme/week\n";
        
        if ($themecount > 0) {
            $instructions .= "- Your output MUST have EXACTLY {$themecount} themes (this is non-negotiable)\n";
        }
        
        $instructions .= "- Return ONLY the exact structure shown above - no modifications to theme/week count\n";
        $instructions .= "- The number of sections in your output MUST match the CSV exactly\n\n";
        
        if ($expandonthemes) {
            $instructions .= "*** TITLE ENHANCEMENT REQUESTED ***\n";
            $instructions .= "The user has requested 'expand on themes'. You MAY enhance titles for clarity:\n";
            $instructions .= "- Make theme/week titles more descriptive and pedagogically clear\n";
            $instructions .= "- Ensure titles accurately reflect the content and learning goals\n";
            $instructions .= "- Use student-friendly language\n";
            $instructions .= "BUT: Do NOT change the number of themes/weeks/sessions\n\n";
        } else {
            $instructions .= "*** PRESERVE EXACT TITLES ***\n";
            $instructions .= "- Keep all theme, week, and activity titles EXACTLY as provided in the CSV\n";
            $instructions .= "- Do NOT modify, expand, or enhance any titles\n";
            $instructions .= "- Only generate descriptions/summaries if explicitly requested\n\n";
        }
        
        return $instructions;
    }
}
