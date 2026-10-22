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

namespace aiplacement_modgen;

use aiplacement_modgen\local\template_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * The CSV template library: adding, editing, ordering, removing, and what is stored for each.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\template_manager
 */
final class template_manager_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * An administrator, as only they manage the library.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Add a template from a CSV with the given name and content.
     *
     * @param string $name Template name.
     * @param string $filename Name of the uploaded file.
     * @param string $content CSV content.
     * @param string $description Template description.
     * @return int Template id.
     */
    private function add(
        string $name,
        string $filename = 'plan.csv',
        string $content = "Week 1,A\n",
        string $description = ''
    ): int {
        return template_manager::create($name, $description, $this->draft_file($filename, $content)['file']);
    }

    /**
     * Names of the templates in library order.
     *
     * @return string[]
     */
    private function names(): array {
        return array_values(array_map(fn($template) => $template->name, template_manager::get_all()));
    }

    /**
     * A new template is stored with its file in the plugin's file area, in the system context.
     */
    public function test_a_template_is_stored_with_its_file(): void {
        $id = $this->add('Standard module', 'Plan (v2).csv', "Week 1,Stored\n", 'Twelve weeks');

        $template = template_manager::get_by_id($id);
        $this->assertSame('Standard module', $template->name);
        $this->assertSame('Twelve weeks', $template->description);
        $file = get_file_storage()->get_file_by_id($template->fileid);
        $this->assertSame(\context_system::instance()->id, (int) $file->get_contextid());
        $this->assertSame('aiplacement_modgen', $file->get_component());
        $this->assertSame('csvtemplates', $file->get_filearea());
        $this->assertSame("Week 1,Stored\n", $file->get_content());
        $this->assertSame(clean_filename('Plan (v2).csv'), $file->get_filename());
    }

    /**
     * Templates are listed in the order they were added, and each new one goes last.
     */
    public function test_new_templates_go_last(): void {
        $this->add('First');
        $this->add('Second');
        $this->add('Third');

        $this->assertSame(['First', 'Second', 'Third'], $this->names());
        $orders = array_values(array_map(fn($template) => (int) $template->sortorder, template_manager::get_all()));
        $this->assertSame($orders, array_unique($orders), 'Each template has its own position.');
        $this->assertSame($orders, array_values($orders));
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders);
    }

    /**
     * Names and descriptions may hold any text, and two templates may share a name.
     */
    public function test_names_and_descriptions_may_be_anything(): void {
        $first = $this->add("Caf\u{e9} \u{65e5}\u{672c}\u{8a9e} & <b>co</b>", 'a.csv', "Week 1,A\n", "Line one\nLine two");
        $second = $this->add("Caf\u{e9} \u{65e5}\u{672c}\u{8a9e} & <b>co</b>", 'b.csv');

        $this->assertNotEquals($first, $second);
        $this->assertSame("Caf\u{e9} \u{65e5}\u{672c}\u{8a9e} & <b>co</b>", template_manager::get_by_id($first)->name);
        $this->assertSame("Line one\nLine two", template_manager::get_by_id($first)->description);
    }

    /**
     * Editing changes the name and description; a new file replaces the old one.
     */
    public function test_editing_replaces_the_file(): void {
        $id = $this->add('Old name', 'old.csv', "Week 1,Old\n");
        $oldfileid = template_manager::get_by_id($id)->fileid;

        template_manager::update($id, 'New name', 'New description');
        $this->assertSame($oldfileid, template_manager::get_by_id($id)->fileid, 'Without a new file the file stays.');

        template_manager::update($id, 'New name', 'New description', $this->draft_file('new.csv', "Week 1,New\n")['file']);

        $template = template_manager::get_by_id($id);
        $this->assertSame('New name', $template->name);
        $this->assertSame('New description', $template->description);
        $this->assertNotEquals($oldfileid, $template->fileid);
        $this->assertFalse(get_file_storage()->get_file_by_id($oldfileid), 'The replaced file is removed.');
        $this->assertSame("Week 1,New\n", get_file_storage()->get_file_by_id($template->fileid)->get_content());
    }

    /**
     * Moving a template swaps it with its neighbour; at either end nothing happens.
     */
    public function test_reordering(): void {
        $a = $this->add('A');
        $b = $this->add('B');
        $c = $this->add('C');

        $this->assertTrue(template_manager::reorder($c, 'up'));
        $this->assertSame(['A', 'C', 'B'], $this->names());
        $this->assertTrue(template_manager::reorder($a, 'down'));
        $this->assertSame(['C', 'A', 'B'], $this->names());

        $this->assertFalse(template_manager::reorder($c, 'up'), 'The first template cannot move up.');
        $this->assertFalse(template_manager::reorder($b, 'down'), 'The last template cannot move down.');
        $this->assertSame(['C', 'A', 'B'], $this->names());
    }

    /**
     * Deleting removes the record and its file, and leaves the others in order.
     */
    public function test_deleting(): void {
        $a = $this->add('A');
        $b = $this->add('B');
        $c = $this->add('C');
        $fileid = template_manager::get_by_id($b)->fileid;

        template_manager::delete($b);

        $this->assertSame(['A', 'C'], $this->names());
        $this->assertFalse(get_file_storage()->get_file_by_id($fileid));
        $this->expectException(\dml_missing_record_exception::class);
        template_manager::get_by_id($b);
    }

    /**
     * Asking for a template that does not exist is an error, not an empty result.
     */
    public function test_unknown_templates_are_errors(): void {
        $this->expectException(\dml_missing_record_exception::class);
        template_manager::delete(987654);
    }

    /**
     * An empty library lists nothing.
     */
    public function test_an_empty_library(): void {
        $this->assertSame([], template_manager::get_all());
    }
}
