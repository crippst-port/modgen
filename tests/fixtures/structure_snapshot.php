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
 * Reads a course's structure straight from the database and compares it with an expected
 * description, for PHPUnit and Behat tests: Flexible sections, and Custom sections and Weekly
 * sections with core subsections.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Snapshot of a course's sections: numbers, names, parents, display option, activities.
 *
 * Everything is read from course_sections, course_format_options and course_modules, not from
 * the course cache, so it shows what is really stored.
 */
class aiplacement_modgen_structure_snapshot {
    /**
     * Rows for every section above section 0, in section-number order.
     *
     * Each row has: section (number), id, name, parentnumber, parent (the parent's name, or '-'
     * for a top-level section), depth (1 = top level), collapsed ('0' or '1'), activities (how
     * many course modules the section holds), summary (plain text), visible (0 or 1).
     *
     * @param int $courseid Course id.
     * @return array[]
     */
    public static function rows(int $courseid): array {
        global $DB;

        if (!self::is_flexsections($courseid)) {
            return self::core_rows($courseid);
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $options = [];
        foreach ($DB->get_records('course_format_options', ['courseid' => $courseid, 'format' => 'flexsections']) as $option) {
            $options[$option->sectionid][$option->name] = $option->value;
        }

        $bynumber = [];
        foreach ($sections as $section) {
            $bynumber[(int) $section->section] = $section;
        }

        $rows = [];
        foreach ($bynumber as $number => $section) {
            if ($number === 0) {
                continue;
            }
            $parentnumber = (int) ($options[$section->id]['parent'] ?? 0);
            $depth = 1;
            $cursor = $parentnumber;
            while ($cursor > 0 && $depth < 50) {
                $depth++;
                $cursor = (int) ($options[$bynumber[$cursor]->id ?? 0]['parent'] ?? 0);
            }
            $rows[] = [
                'section' => $number,
                'id' => (int) $section->id,
                'name' => (string) $section->name,
                'parentnumber' => $parentnumber,
                'parent' => $parentnumber > 0 && isset($bynumber[$parentnumber]) ? (string) $bynumber[$parentnumber]->name : '-',
                'depth' => $depth,
                'collapsed' => (string) ($options[$section->id]['collapsed'] ?? ''),
                'activities' => count(array_filter(explode(',', (string) $section->sequence))),
                'activitynames' => self::activity_names($section->sequence),
                'summary' => trim(html_entity_decode(strip_tags((string) $section->summary))),
                'visible' => (int) $section->visible,
            ];
        }
        return $rows;
    }

    /**
     * Names of the activities in a section's sequence, in order.
     *
     * @param string|null $sequence Comma separated course module ids.
     * @return string[]
     */
    private static function activity_names(?string $sequence): array {
        global $DB;

        $names = [];
        foreach (array_filter(explode(',', (string) $sequence)) as $cmid) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,module,instance');
            if (!$cm) {
                continue;
            }
            $module = $DB->get_field('modules', 'name', ['id' => $cm->module]);
            $names[] = (string) $DB->get_field($module, 'name', ['id' => $cm->instance]);
        }
        return $names;
    }

    /**
     * Compare the stored structure with an expected one.
     *
     * Expected rows are in section order and each has at least 'name' and 'parent' ('-' for a
     * top-level section). Any of 'collapsed', 'activities', 'depth', 'visible' that is present
     * is compared exactly, and 'summary' must be contained in the stored summary.
     *
     * @param int $courseid Course id.
     * @param array[] $expected Expected rows.
     * @return string[] Differences; empty when the structure matches.
     */
    public static function diff(int $courseid, array $expected): array {
        $actual = self::rows($courseid);
        $problems = [];

        if (count($actual) !== count($expected)) {
            $problems[] = 'Expected ' . count($expected) . ' sections above section 0 but found ' . count($actual) . '.';
        }

        foreach ($expected as $index => $want) {
            $got = $actual[$index] ?? null;
            $where = 'Row ' . ($index + 1) . ' (' . ($want['name'] ?? '?') . ')';
            if ($got === null) {
                $problems[] = "$where: missing.";
                continue;
            }
            foreach (['name', 'parent'] as $key) {
                if ((string) $want[$key] !== (string) $got[$key]) {
                    $problems[] = "$where: $key is '{$got[$key]}', expected '{$want[$key]}'.";
                }
            }
            if (isset($want['activitynames']) && $want['activitynames'] !== $got['activitynames']) {
                $problems[] = "$where: activities are [" . implode(', ', $got['activitynames']) . "], expected [" .
                    implode(', ', $want['activitynames']) . "].";
            }
            // Only Flexible sections has a collapsed option.
            $keys = ['activities', 'depth', 'visible'];
            if (self::is_flexsections($courseid)) {
                $keys[] = 'collapsed';
            }
            foreach ($keys as $key) {
                if (isset($want[$key]) && $want[$key] !== '' && (string) $want[$key] !== (string) $got[$key]) {
                    $problems[] = "$where: $key is '{$got[$key]}', expected '{$want[$key]}'.";
                }
            }
            $wantsummary = (string) ($want['summary'] ?? '');
            if ($wantsummary !== '' && strpos($got['summary'], $wantsummary) === false) {
                $problems[] = "$where: summary is '{$got['summary']}', expected it to contain '{$want['summary']}'.";
            }
        }

        if ($problems) {
            $problems[] = "Stored structure:\n" . self::describe($actual);
        }
        return $problems;
    }

    /**
     * Rules every Flexible sections course must satisfy, whatever built it.
     *
     * - section numbers run 0..N-1 with no gaps;
     * - every section above 0 has a parent and a collapsed option, the parent is an existing
     *   section number below the child's, and collapsed is 0 or 1;
     * - the numbering is the depth-first order of the tree (a section's children directly follow it);
     * - the depth never exceeds the format's limit;
     * - every course module is in exactly one section's sequence, and that section is the one the
     *   module row points to;
     * - there are no option rows for sections that do not exist.
     *
     * @param int $courseid Course id.
     * @return string[] Problems found; empty when sound.
     */
    public static function problems(int $courseid): array {
        global $DB;

        if (!self::is_flexsections($courseid)) {
            return self::core_problems($courseid);
        }

        $problems = [];
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $options = [];
        foreach ($DB->get_records('course_format_options', ['courseid' => $courseid]) as $option) {
            if ($option->format !== 'flexsections') {
                // Left over from the course's previous format; Moodle keeps these so the format can be switched back.
                continue;
            }
            if (isset($options[$option->sectionid][$option->name])) {
                $problems[] = "Duplicate option '{$option->name}' for section id {$option->sectionid}.";
            }
            $options[$option->sectionid][$option->name] = $option->value;
        }

        $numbers = array_map(fn($s) => (int) $s->section, $sections);
        if (array_values($numbers) !== range(0, count($sections) - 1)) {
            $problems[] = 'Section numbers are not contiguous from 0: ' . implode(',', $numbers);
        }
        $bynumber = [];
        foreach ($sections as $section) {
            $bynumber[(int) $section->section] = $section;
        }
        foreach (array_keys($options) as $sectionid) {
            if ($sectionid != 0 && !isset($sections[$sectionid])) {
                $problems[] = "Option rows exist for missing section id $sectionid.";
            }
        }

        $parents = [];
        foreach ($bynumber as $number => $section) {
            if ($number === 0) {
                continue;
            }
            $option = $options[$section->id] ?? [];
            if (!isset($option['parent']) || !ctype_digit((string) $option['parent'])) {
                $problems[] = "Section $number has no integer parent option.";
                continue;
            }
            $parent = (int) $option['parent'];
            $parents[$number] = $parent;
            if ($parent >= $number) {
                $problems[] = "Section $number has parent $parent, which is not before it.";
            }
            if ($parent > 0 && !isset($bynumber[$parent])) {
                $problems[] = "Section $number has parent $parent, which does not exist.";
            }
            if (!in_array((string) ($option['collapsed'] ?? ''), ['0', '1'], true)) {
                $problems[] = "Section $number has collapsed '" . ($option['collapsed'] ?? '') . "'.";
            }
        }

        $children = [];
        foreach ($parents as $number => $parent) {
            $children[$parent][] = $number;
        }
        $order = [];
        $walk = function (int $parent) use (&$walk, &$order, $children) {
            foreach ($children[$parent] ?? [] as $child) {
                $order[] = $child;
                $walk($child);
            }
        };
        $walk(0);
        if ($order !== array_keys($parents)) {
            $problems[] = 'Section numbering is not the depth-first order of the tree.';
        }

        $maxdepth = course_get_format($courseid)->get_max_section_depth();
        foreach (self::rows($courseid) as $row) {
            if ($row['depth'] > $maxdepth) {
                $problems[] = "Section {$row['section']} is nested {$row['depth']} deep; the limit is $maxdepth.";
            }
        }

        return array_merge($problems, self::module_problems($courseid, $sections));
    }

    /**
     * Rules every course module must satisfy: it is in exactly one section's sequence, and that section
     * is the one the module row points to.
     *
     * @param int $courseid Course id.
     * @param stdClass[] $sections The course's section records.
     * @return string[] Problems found.
     */
    private static function module_problems(int $courseid, array $sections): array {
        global $DB;

        $problems = [];
        $sequences = [];
        foreach ($sections as $section) {
            foreach (array_filter(explode(',', (string) $section->sequence)) as $cmid) {
                if (isset($sequences[$cmid])) {
                    $problems[] = "Course module $cmid is in two sections' sequences.";
                }
                $sequences[$cmid] = $section->id;
            }
        }
        foreach ($DB->get_records('course_modules', ['course' => $courseid], '', 'id,section,deletioninprogress') as $cm) {
            if (!isset($sequences[$cm->id])) {
                if (!$cm->deletioninprogress) {
                    $problems[] = "Course module {$cm->id} is in no section's sequence.";
                }
            } else if ((int) $sequences[$cm->id] !== (int) $cm->section) {
                $problems[] = "Course module {$cm->id} is in a sequence but points at section id {$cm->section}.";
            }
        }
        return $problems;
    }

    /**
     * Whether the course uses Flexible sections; anything else is read as a core format with subsections.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    private static function is_flexsections(int $courseid): bool {
        global $DB;
        return $DB->get_field('course', 'format', ['id' => $courseid], MUST_EXIST) === 'flexsections';
    }

    /**
     * Rows for a Custom sections or Weekly sections course, in the order the course page shows them.
     *
     * Each ordinary section, then a row for each subsection it holds, in the order of its activities.
     * A subsection's row is its delegated section: parent is the section holding the Subsection
     * activity, depth 2. 'activities' and 'activitynames' count everything in the section except
     * Subsection activities, which are the subsection rows themselves. Rows have the keys rows() gives,
     * with collapsed always '' (core formats have no such option).
     *
     * @param int $courseid Course id.
     * @return array[]
     */
    private static function core_rows(int $courseid): array {
        global $DB;

        $subsectionmodule = $DB->get_field('modules', 'id', ['name' => 'subsection']);
        $rows = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid, 'component' => null], 'section ASC') as $section) {
            if ((int) $section->section === 0) {
                continue;
            }
            [$own, $subsections] = self::split_sequence($section->sequence, (int) $subsectionmodule);
            $rows[] = self::core_row($section, '-', 1, $own);
            foreach ($subsections as $instance) {
                $delegated = $DB->get_record('course_sections', [
                    'course' => $courseid,
                    'component' => 'mod_subsection',
                    'itemid' => $instance,
                ]);
                if ($delegated) {
                    [$inner] = self::split_sequence($delegated->sequence, (int) $subsectionmodule);
                    $rows[] = self::core_row($delegated, (string) $section->name, 2, $inner);
                }
            }
        }
        return $rows;
    }

    /**
     * Split a sequence into the ordinary activities and the Subsection activities' instance ids.
     *
     * @param string|null $sequence Comma separated course module ids.
     * @param int $subsectionmodule The Subsection module's id.
     * @return array [cm ids of other activities, subsection instance ids], each in order.
     */
    private static function split_sequence(?string $sequence, int $subsectionmodule): array {
        global $DB;

        $own = [];
        $subsections = [];
        foreach (array_filter(explode(',', (string) $sequence)) as $cmid) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id,module,instance');
            if (!$cm) {
                continue;
            }
            if ((int) $cm->module === $subsectionmodule) {
                $subsections[] = (int) $cm->instance;
            } else {
                $own[] = (int) $cm->id;
            }
        }
        return [$own, $subsections];
    }

    /**
     * One row of core_rows().
     *
     * @param stdClass $section Section record.
     * @param string $parent Parent section name, or '-'.
     * @param int $depth 1 for an ordinary section, 2 for a subsection.
     * @param int[] $cmids The activities it holds, other than Subsection activities.
     * @return array
     */
    private static function core_row(stdClass $section, string $parent, int $depth, array $cmids): array {
        return [
            'section' => (int) $section->section,
            'id' => (int) $section->id,
            'name' => (string) $section->name,
            'parentnumber' => 0,
            'parent' => $parent,
            'depth' => $depth,
            'collapsed' => '',
            'activities' => count($cmids),
            'activitynames' => self::activity_names(implode(',', $cmids)),
            'summary' => trim(html_entity_decode(strip_tags((string) $section->summary))),
            'visible' => (int) $section->visible,
        ];
    }

    /**
     * Rules every Custom sections or Weekly sections course must satisfy, whatever built it.
     *
     * - section numbers run 0..N-1 with no gaps, every ordinary section before every delegated one,
     *   as core numbers them;
     * - every delegated section belongs to exactly one Subsection activity, which exists, is in an
     *   ordinary section (subsections do not nest) and has the section's name;
     * - every course module is in exactly one section's sequence, the one its row points to;
     * - the course cache, rebuilt from scratch, shows the sections in the same order as the database.
     *
     * @param int $courseid Course id.
     * @return string[] Problems found; empty when sound.
     */
    private static function core_problems(int $courseid): array {
        global $DB;

        $problems = [];
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $numbers = array_values(array_map(fn($s) => (int) $s->section, $sections));
        if ($numbers !== range(0, count($sections) - 1)) {
            $problems[] = 'Section numbers are not contiguous from 0: ' . implode(',', $numbers);
        }

        $lastordinary = -1;
        $firstdelegated = PHP_INT_MAX;
        foreach ($sections as $section) {
            if (empty($section->component)) {
                $lastordinary = max($lastordinary, (int) $section->section);
                continue;
            }
            $firstdelegated = min($firstdelegated, (int) $section->section);
            if ($section->component !== 'mod_subsection') {
                continue;
            }
            $cms = $DB->get_records_sql(
                "SELECT cm.id, cm.section, s.name
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'subsection'
                   JOIN {subsection} s ON s.id = cm.instance
                  WHERE cm.course = :courseid AND cm.instance = :instance",
                ['courseid' => $courseid, 'instance' => $section->itemid]
            );
            if (count($cms) !== 1) {
                $problems[] = "Delegated section {$section->section} belongs to " . count($cms) . ' Subsection activities.';
                continue;
            }
            $cm = reset($cms);
            $holder = $sections[$cm->section] ?? null;
            if (!$holder) {
                $problems[] = "The Subsection activity for section {$section->section} is in a section that does not exist.";
            } else if (!empty($holder->component)) {
                $problems[] = "The Subsection activity for section {$section->section} is inside another subsection.";
            }
            if ((string) $cm->name !== (string) $section->name) {
                $problems[] = "Subsection '{$cm->name}' and its section '{$section->name}' have different names.";
            }
        }
        if ($lastordinary > $firstdelegated) {
            $problems[] = 'An ordinary section is numbered after a delegated one.';
        }

        $problems = array_merge($problems, self::module_problems($courseid, $sections));

        get_fast_modinfo($courseid, 0, true);
        $cached = array_map(fn($s) => (int) $s->id, get_fast_modinfo($courseid)->get_section_info_all());
        if (array_values($cached) !== array_values(array_map(fn($s) => (int) $s->id, $sections))) {
            $problems[] = 'The course cache shows the sections in a different order from the database.';
        }
        return $problems;
    }

    /**
     * Readable listing of stored rows, for failure messages.
     *
     * @param array[] $rows Rows from rows().
     * @return string
     */
    public static function describe(array $rows): string {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '  %3d  %s%s  [parent: %s, collapsed: %s, activities: %d]',
                $row['section'],
                str_repeat('  ', $row['depth'] - 1),
                $row['name'],
                $row['parent'],
                $row['collapsed'],
                $row['activities']
            );
        }
        return implode("\n", $lines);
    }
}
