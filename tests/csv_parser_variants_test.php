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

use aiplacement_modgen\local\csv_parser;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * How the CSV parser reads many different files: layouts, line endings, encodings, quoting,
 * labels, and the things it must refuse.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\csv_parser
 */
final class csv_parser_variants_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * Parse a CSV held in a draft file and reduce the result to titles and summaries.
     *
     * @param string $content File content.
     * @param string $moduletype connected_theme or connected_weekly.
     * @param string $filename Name of the uploaded file.
     * @return array Themes (with their weeks) or sections, as plain lists.
     */
    private function parse(string $content, string $moduletype, string $filename = 'structure.csv'): array {
        $this->setAdminUser();
        $file = $this->draft_file($filename, $content)['file'];
        $structure = csv_parser::parse_csv_to_structure($file, $moduletype);

        if (isset($structure['themes'])) {
            return array_map(fn($theme) => [
                'title' => $theme['title'],
                'summary' => $theme['summary'],
                'weeks' => array_map(fn($week) => ['title' => $week['title'], 'summary' => $week['summary']], $theme['weeks']),
            ], $structure['themes']);
        }
        return array_map(fn($section) => ['title' => $section['title'], 'summary' => $section['summary']], $structure['sections']);
    }

    /**
     * Theme layouts that are all read the same way.
     *
     * @return array
     */
    public static function theme_layouts(): array {
        $expected = [
            ['title' => 'Foundations', 'summary' => 'About foundations', 'weeks' => [
                ['title' => 'Introduction', 'summary' => 'Intro week'],
                ['title' => 'Methods', 'summary' => ''],
            ]],
            ['title' => 'Application', 'summary' => '', 'weeks' => [['title' => 'Practice', 'summary' => '']]],
        ];
        $rows = [
            ['Theme 1', 'Foundations'], ['Description', 'About foundations'],
            ['Week 1', 'Introduction'], ['Description', 'Intro week'],
            ['Week 2', 'Methods'],
            ['Theme 2', 'Application'], ['Week 3', 'Practice'],
        ];
        $join = fn(string $eol, string $sep = ',') => implode($eol, array_map(fn($r) => implode($sep, $r), $rows)) . $eol;
        $each = fn(callable $format, string $eol = "\n") => implode($eol, array_map($format, $rows)) . $eol;
        $relabel = fn(array $map) => str_replace(array_keys($map), array_values($map), $join("\n"));
        return [
            'numbered labels, LF' => [$join("\n"), $expected],
            'CRLF line endings' => [$join("\r\n"), $expected],
            'classic Mac carriage returns only' => [$join("\r"), $expected],
            'mixed line endings' => [
                implode("\r\n", array_map(fn($r) => implode(',', $r), array_slice($rows, 0, 3))) . "\r\n" .
                    implode("\n", array_map(fn($r) => implode(',', $r), array_slice($rows, 3))) . "\r",
                $expected,
            ],
            'no final newline' => [rtrim($join("\n")), $expected],
            'UTF-8 byte order mark' => ["\xEF\xBB\xBF" . $join("\n"), $expected],
            'upper case labels' => [str_replace(
                ['Theme', 'Week', 'Description'],
                ['THEME', 'WEEK', 'DESCRIPTION'],
                $join("\n")
            ), $expected],
            'lower case labels' => [str_replace(
                ['Theme', 'Week', 'Description'],
                ['theme', 'week', 'description'],
                $join("\n")
            ), $expected],
            'labels without numbers' => [$relabel([
                'Theme 1' => 'Theme', 'Theme 2' => 'Theme',
                'Week 1' => 'Week', 'Week 2' => 'Week', 'Week 3' => 'Week',
            ]), $expected],
            'labels with colons' => [$relabel([
                'Theme 1' => 'Theme:', 'Theme 2' => 'Theme:', 'Week 1' => 'Week:', 'Week 2' => 'Week:',
                'Week 3' => 'Week:', 'Description' => 'Description:',
            ]), $expected],
            'quoted labels and values' => [$each(fn($r) => '"' . $r[0] . '","' . $r[1] . '"'), $expected],
            'extra columns are ignored' => [$each(fn($r) => $r[0] . ',' . $r[1] . ',ignored,also ignored'), $expected],
            'blank lines and spaces around values' => [
                "\n\n" . $each(fn($r) => '  ' . $r[0] . ' ,  ' . $r[1] . '  ', "\n\n") . "\n",
                $expected,
            ],
            'rows with an empty value are skipped' => [$join("\n") . "Week 9,\nDescription,\n,Orphan value\n", $expected],
            'unknown labels are ignored' => [
                "Module,Intro to Testing\nCode,ABC123\n" . $join("\n") . "Notes,Remember this\n",
                $expected,
            ],
            'module title row is ignored' => ["Title,My Module\n" . $join("\n"), $expected],
        ];
    }

    /**
     * Theme files in many layouts give the same structure.
     *
     * @dataProvider theme_layouts
     * @param string $content File content.
     * @param array $expected Expected themes.
     */
    public function test_theme_layouts_are_all_read_the_same_way(string $content, array $expected): void {
        $this->resetAfterTest();
        $this->assertSame($expected, $this->parse($content, 'connected_theme'));
    }

    /**
     * Weekly layouts: "Week" and "Section" rows both make a section, and a description follows its week.
     */
    public function test_weekly_layouts(): void {
        $this->resetAfterTest();
        $content = "Week 1,Alpha\nDescription,First\nWeek 2,Beta\nSection 3,Gamma\nDescription,Third\nsection,Delta\n";

        $this->assertSame([
            ['title' => 'Alpha', 'summary' => 'First'],
            ['title' => 'Beta', 'summary' => ''],
            ['title' => 'Gamma', 'summary' => 'Third'],
            ['title' => 'Delta', 'summary' => ''],
        ], $this->parse($content, 'connected_weekly'));
    }

    /**
     * Values keep their punctuation, quotes, commas and line breaks.
     */
    public function test_quoted_values_keep_their_characters(): void {
        $this->resetAfterTest();
        $content = "Week 1,\"Plan, do, review\"\n" .
            "Description,\"He said \"\"hello\"\"\nand left\"\n" .
            "Week 2,\"Q&A / 50% off\"\n";

        $this->assertSame([
            ['title' => 'Plan, do, review', 'summary' => "He said \"hello\"\nand left"],
            ['title' => 'Q&A / 50% off', 'summary' => ''],
        ], $this->parse($content, 'connected_weekly'));
    }

    /**
     * Unicode text survives, whether the file is UTF-8 or a Windows-1252 export from Excel.
     */
    public function test_unicode_and_windows_1252_text(): void {
        $this->resetAfterTest();
        $utf8 = "Week 1,Caf\u{e9} culture \u{2013} \u{65e5}\u{672c}\u{8a9e} \u{1f600}\nWeek 2,\u{a3}100 \u{201c}quoted\u{201d}\n";
        $this->assertSame(
            [['title' => "Caf\u{e9} culture \u{2013} \u{65e5}\u{672c}\u{8a9e} \u{1f600}", 'summary' => ''],
                ['title' => "\u{a3}100 \u{201c}quoted\u{201d}", 'summary' => '']],
            $this->parse($utf8, 'connected_weekly')
        );

        // The same words as a Windows-1252 file: e-acute 0xE9, pound 0xA3, curly quotes 0x93/0x94.
        $cp1252 = "Week 1,Caf\xE9\nWeek 2,\xA3100 \x93quoted\x94\n";
        $this->assertSame(
            [['title' => "Caf\u{e9}", 'summary' => ''], ['title' => "\u{a3}100 \u{201c}quoted\u{201d}", 'summary' => '']],
            $this->parse($cp1252, 'connected_weekly')
        );
    }

    /**
     * A theme with no weeks is a valid theme.
     */
    public function test_theme_without_weeks(): void {
        $this->resetAfterTest();
        $this->assertSame(
            [['title' => 'Lonely', 'summary' => 'Just a theme', 'weeks' => []]],
            $this->parse("Theme 1,Lonely\nDescription,Just a theme\n", 'connected_theme')
        );
    }

    /**
     * Repeated titles are kept; nothing is merged.
     */
    public function test_duplicate_titles_are_kept(): void {
        $this->resetAfterTest();
        $this->assertSame(
            [['title' => 'Same', 'summary' => ''], ['title' => 'Same', 'summary' => '']],
            $this->parse("Week 1,Same\nWeek 2,Same\n", 'connected_weekly')
        );
    }

    /**
     * Descriptions that follow nothing they could describe are dropped, not attached to the wrong item.
     */
    public function test_descriptions_with_nothing_to_describe_are_dropped(): void {
        $this->resetAfterTest();
        $this->assertSame(
            [['title' => 'Alpha', 'summary' => '']],
            $this->parse("Description,Floating\nTitle,My module\nDescription,After the title\nWeek 1,Alpha\n", 'connected_weekly')
        );
    }

    /**
     * The file name does not matter to the parser: every kind of name is read the same way.
     *
     * @return array
     */
    public static function file_names(): array {
        return [
            'plain' => ['structure.csv'],
            'spaces' => ['My module plan.csv'],
            'brackets and version' => ['Plan (v2) - final.csv'],
            'unicode' => ["Pl\u{e4}n \u{65e5}\u{672c}\u{8a9e}.csv"],
            'upper case extension' => ['PLAN.CSV'],
            'mixed case extension' => ['plan.Csv'],
            'several dots' => ['module.2026.term1.csv'],
            'leading dot' => ['.hidden.csv'],
            'very long name' => [str_repeat('long-name-', 20) . '.csv'],
            'no extension' => ['plan'],
            'a different extension' => ['plan.txt'],
        ];
    }

    /**
     * A file name never changes how the contents are read.
     *
     * @dataProvider file_names
     * @param string $filename Name the file was uploaded with.
     */
    public function test_any_file_name_is_read_the_same(string $filename): void {
        $this->resetAfterTest();
        $this->assertSame(
            [['title' => 'Alpha', 'summary' => 'First']],
            $this->parse("Week 1,Alpha\nDescription,First\n", 'connected_weekly', $filename)
        );
    }

    /**
     * Format detection: any row labelled Theme makes it a theme file, whatever the case or position.
     */
    public function test_format_detection(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $detect = fn(string $content) => csv_parser::detect_csv_format($this->draft_file('f.csv', $content)['file']);

        $this->assertSame('connected_theme', $detect("Theme 1,A\nWeek 1,B\n"));
        $this->assertSame('connected_theme', $detect("Week 1,B\nTHEME 2,A\n"));
        $this->assertSame('connected_theme', $detect("Themes,A\n"));
        $this->assertSame('connected_weekly', $detect("Week 1,B\nSection 2,C\n"));
        $this->assertSame('connected_weekly', $detect(''));
        $this->assertSame('connected_weekly', $detect("Notes,Nothing to see\n"));
        // A description that merely begins a line with the word does not count: it is inside quotes.
        $this->assertSame('connected_weekly', $detect("Week 1,\"Alpha\nTheme of the week\"\n"));
    }

    /**
     * Things that cannot be built are refused with a specific message.
     *
     * @return array
     */
    public static function refused_files(): array {
        return [
            'empty file' => ['', 'connected_weekly', 'csvempty'],
            'blank lines only' => ["\n\n   \n", 'connected_weekly', 'csvnodata'],
            'a single column' => ["Week 1\nWeek 2\n", 'connected_weekly', 'csvnodata'],
            'semicolon separated' => ["Week 1;Alpha\nWeek 2;Beta\n", 'connected_weekly', 'csvnodata'],
            'tab separated' => ["Week 1\tAlpha\nWeek 2\tBeta\n", 'connected_weekly', 'csvnodata'],
            'a week before any theme' => ["Week 1,Early\nTheme 1,Late\n", 'connected_theme', 'csvweekbeforetheme'],
            'a weekly file read as themes' => ["Week 1,Alpha\n", 'connected_theme', 'csvweekbeforetheme'],
            'only unknown labels' => ["Module,Intro\nCode,ABC\n", 'connected_weekly', 'csvnodata'],
            'only descriptions' => ["Description,Nothing to describe\n", 'connected_theme', 'csvnodata'],
            'a header row only' => ["Label,Value\n", 'connected_weekly', 'csvnodata'],
        ];
    }

    /**
     * Files that cannot be built are refused with the right error code.
     *
     * @dataProvider refused_files
     * @param string $content File content.
     * @param string $moduletype Module type requested.
     * @param string $errorcode Expected error code.
     */
    public function test_files_that_cannot_be_built_are_refused(string $content, string $moduletype, string $errorcode): void {
        $this->resetAfterTest();
        try {
            $this->parse($content, $moduletype);
            $this->fail("Expected '$errorcode'.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The section cap counts themes, weeks and sections; 0 means no cap, and the message says how many there were.
     */
    public function test_the_csv_section_cap(): void {
        $this->resetAfterTest();
        $content = "Theme 1,A\nWeek 1,B\nWeek 2,C\nTheme 2,D\nWeek 3,E\n";

        set_config('maxcsvsections', 5, 'aiplacement_modgen');
        $this->assertCount(2, $this->parse($content, 'connected_theme'));

        set_config('maxcsvsections', 4, 'aiplacement_modgen');
        try {
            $this->parse($content, 'connected_theme');
            $this->fail('Five sections must not fit a cap of four.');
        } catch (\moodle_exception $e) {
            $this->assertSame('csvlimitexceeded', $e->errorcode);
            $this->assertStringContainsString('5', $e->getMessage());
            $this->assertStringContainsString('4', $e->getMessage());
        }

        set_config('maxcsvsections', 0, 'aiplacement_modgen');
        $this->assertCount(2, $this->parse($content, 'connected_theme'));
    }

    /**
     * Without a configured cap the default of 50 applies.
     */
    public function test_the_default_csv_section_cap_is_fifty(): void {
        $this->resetAfterTest();
        unset_config('maxcsvsections', 'aiplacement_modgen');

        $fifty = implode('', array_map(fn($n) => "Week $n,W$n\n", range(1, 50)));
        $this->assertCount(50, $this->parse($fifty, 'connected_weekly'));

        $this->expectException(\moodle_exception::class);
        $this->parse($fifty . "Week 51,W51\n", 'connected_weekly');
    }
}
