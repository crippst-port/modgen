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
 * Book activity handler for creating Moodle book modules.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates Book activities with chapters populated from extracted content.
 */
class book implements activity_type {
    
    /** @inheritDoc */
    public static function get_type(): string {
        return 'book';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_book';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle Book activity containing structured chapters and content pages. Each chapter can include HTML markup with Bootstrap 4/5 classes for layout (cards, grid layouts, alerts, etc.). Ideal for organizing structured content into navigable chapters.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');

        $name = trim($activitydata->name ?? '');
        
        if ($name === '') {
            error_log('BOOK: Empty name, returning null');
            return null;
        }

        error_log('BOOK: Creating book activity: ' . $name);
        error_log('BOOK: Course ID: ' . $course->id . ', Section: ' . $sectionnumber);

        $intro = trim($activitydata->intro ?? '');
        $chapters = $activitydata->chapters ?? [];
        
        // Ensure chapters is an array (might be string from JSON)
        if (!is_array($chapters)) {
            $chapters = [];
        }

        error_log('BOOK: Chapters count: ' . count($chapters));

        // Create the book module using the same pattern as quiz/label
        $moduleinfo = new stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->modulename = 'book';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        
        // Book intro - use same editor format as quiz/label
        $moduleinfo->introeditor = [
            'text' => $intro,
            'format' => 1,
            'itemid' => 0
        ];
        
        // Book-specific fields
        $moduleinfo->introformat = 1;
        $moduleinfo->numbering = 0;  // 0 = no numbering
        $moduleinfo->customtitles = 0;  // 0 = standard numbering

        error_log('BOOK: Module info prepared: ' . print_r($moduleinfo, true));

        try {
            error_log('BOOK: Calling create_module');
            $cm = \create_module($moduleinfo);
            error_log('BOOK: create_module succeeded');
            
            $bookid = $cm->instance;
            $cmid = $cm->coursemodule;

            error_log('BOOK: Book created with ID: ' . $bookid . ', CM ID: ' . $cmid);

            // Add chapters to the book
            if (!empty($chapters) && is_array($chapters)) {
                error_log('BOOK: Adding ' . count($chapters) . ' chapters');
                $this->add_chapters_to_book($bookid, $chapters);
            }

            error_log('BOOK: Creation successful');
            return [
                'coursemodule' => $cmid,
                'instance' => $bookid
            ];
        } catch (\Exception $e) {
            error_log('BOOK: Exception caught: ' . $e->getMessage());
            error_log('BOOK: Trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Add chapters to a book activity.
     *
     * @param int $bookid Book activity instance ID.
     * @param array $chapters Array of chapter data with 'title' and 'content' keys.
     */
    private function add_chapters_to_book(int $bookid, array $chapters): void {
        global $DB;

        $chapternum = 1;
        foreach ($chapters as $chapter) {
            if (!is_array($chapter) && !is_object($chapter)) {
                continue;
            }

            $chapterdata = is_object($chapter) ? (array) $chapter : $chapter;
            
            $title = trim($chapterdata['title'] ?? 'Chapter ' . $chapternum);
            $content = trim($chapterdata['content'] ?? '');

            if ($title === '') {
                $title = 'Chapter ' . $chapternum;
            }

            $chapterrecord = new stdClass();
            $chapterrecord->bookid = $bookid;
            $chapterrecord->pagenum = $chapternum;
            $chapterrecord->subchapter = 0;
            $chapterrecord->title = $title;
            $chapterrecord->content = $content;
            $chapterrecord->contentformat = FORMAT_HTML;
            $chapterrecord->hidden = 0;
            $chapterrecord->imprint = 0;

            $DB->insert_record('book_chapters', $chapterrecord);
            $chapternum++;
        }
    }
}
