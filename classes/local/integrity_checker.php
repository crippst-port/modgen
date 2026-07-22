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
 * Course structure integrity checker service.
 *
 * Pure data service — no HTML output. All methods return structured arrays.
 * Used by both check_structure.php (course editors) and admin_tools.php (admins).
 *
 * @package    aiplacement_modgen
 * @copyright  2025 University of Portsmouth
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Runs all course structure integrity checks and repairs.
 *
 * All methods are pure data operations (no HTML output).
 */
class integrity_checker {

    /**
     * Run all integrity checks for a course.
     *
     * @param int $courseid Course ID
     * @return array {
     *   course: stdClass,
     *   has_issues: bool,
     *   counts: array<string,int>,
     *   issues: array<string,array>,
     *   sections: array  (all sections with depth, activitycount, row_issues)
     * }
     */
    public static function check(int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $result = [
            'course'     => $course,
            'has_issues' => false,
            'counts'     => [
                'section0_with_parent' => 0,
                'orphaned_options'     => 0,
                'invalid_parents'      => 0,
                'null_parents'         => 0,
                'missing_parents'      => 0,
                'duplicate_sections'   => 0,
                'circular_refs'        => 0,
                'orphaned_sections'    => 0,
            ],
            'issues'     => [
                'section0_with_parent' => [],
                'orphaned_options'     => [],
                'invalid_parents'      => [],
                'null_parents'         => [],
                'missing_parents'      => [],
                'duplicate_sections'   => [],
                'circular_refs'        => [],
                'orphaned_sections'    => [],
            ],
            'sections'   => [],
        ];

        // --- Check 1: Section 0 with a parent value ---
        $section0sql = "SELECT cs.*, cfo.value AS parentval
                          FROM {course_sections} cs
                          JOIN {course_format_options} cfo
                            ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                         WHERE cs.course = ?
                           AND cs.section = 0
                           AND cfo.value IS NOT NULL";
        $rows = $DB->get_records_sql($section0sql, [$courseid]);
        if (!empty($rows)) {
            $result['issues']['section0_with_parent'] = array_values($rows);
            $result['counts']['section0_with_parent'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 2: Orphaned format options (options for deleted sections) ---
        $orphanedsql = "SELECT cfo.*
                          FROM {course_format_options} cfo
                         WHERE cfo.courseid = ?
                           AND cfo.sectionid NOT IN (
                               SELECT id FROM {course_sections} WHERE course = ?
                           )";
        $rows = $DB->get_records_sql($orphanedsql, [$courseid, $courseid]);
        if (!empty($rows)) {
            $result['issues']['orphaned_options'] = array_values($rows);
            $result['counts']['orphaned_options'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 3: Invalid parent references (parent points to non-existent section number) ---
        $invalidsql = "SELECT cs.*, cfo.value AS parentnum
                         FROM {course_sections} cs
                         JOIN {course_format_options} cfo
                           ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                        WHERE cs.course = ?
                          AND " . $DB->sql_cast_char2int('cfo.value') . " > 0
                          AND " . $DB->sql_cast_char2int('cfo.value') . " NOT IN (
                              SELECT section FROM {course_sections} WHERE course = ?
                          )";
        $rows = $DB->get_records_sql($invalidsql, [$courseid, $courseid]);
        if (!empty($rows)) {
            $result['issues']['invalid_parents'] = array_values($rows);
            $result['counts']['invalid_parents'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 4: Null or empty parent values (sections > 0) ---
        $nullsql = "SELECT cs.*, cfo.value AS parentval
                      FROM {course_sections} cs
                      JOIN {course_format_options} cfo
                        ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                     WHERE cs.course = ?
                       AND cs.section > 0
                       AND (cfo.value IS NULL OR cfo.value = '')";
        $rows = $DB->get_records_sql($nullsql, [$courseid]);
        if (!empty($rows)) {
            $result['issues']['null_parents'] = array_values($rows);
            $result['counts']['null_parents'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 5: Sections missing the parent format option entirely ---
        $missingsql = "SELECT cs.*
                         FROM {course_sections} cs
                        WHERE cs.course = ?
                          AND cs.section > 0
                          AND NOT EXISTS (
                              SELECT 1 FROM {course_format_options} cfo
                               WHERE cfo.sectionid = cs.id AND cfo.name = 'parent'
                          )";
        $rows = $DB->get_records_sql($missingsql, [$courseid]);
        if (!empty($rows)) {
            $result['issues']['missing_parents'] = array_values($rows);
            $result['counts']['missing_parents'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 6: Duplicate section numbers ---
        $dupsql = "SELECT section, COUNT(*) AS count
                     FROM {course_sections}
                    WHERE course = ?
                    GROUP BY section
                   HAVING COUNT(*) > 1";
        $rows = $DB->get_records_sql($dupsql, [$courseid]);
        if (!empty($rows)) {
            $result['issues']['duplicate_sections'] = array_values($rows);
            $result['counts']['duplicate_sections'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Check 7: Circular references ---
        $circularsql = "WITH RECURSIVE section_tree AS (
                            SELECT cs.id, cs.section, cs.course,
                                   CAST(cfo.value AS INTEGER) AS parent,
                                   cs.section AS root_section,
                                   1 AS depth,
                                   CAST(cs.section AS VARCHAR(1000)) AS path
                              FROM {course_sections} cs
                              JOIN {course_format_options} cfo
                                ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                             WHERE cs.course = ? AND cs.section > 0
                            UNION ALL
                            SELECT cs.id, cs.section, cs.course,
                                   CAST(cfo.value AS INTEGER) AS parent,
                                   st.root_section,
                                   st.depth + 1,
                                   CAST(st.path || '->' || cs.section AS VARCHAR(1000))
                              FROM {course_sections} cs
                              JOIN {course_format_options} cfo
                                ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                              JOIN section_tree st ON cs.section = st.parent
                             WHERE st.depth < 10 AND st.course = ?
                        )
                        SELECT DISTINCT root_section, path
                          FROM section_tree
                         WHERE section = root_section AND depth > 1";
        try {
            // Use a recordset and build a plain sequential array: the query has no unique
            // id column, and get_records_sql() would key (and silently collapse) rows by
            // root_section, which is not guaranteed unique across distinct cycles.
            $rows = [];
            $recordset = $DB->get_recordset_sql($circularsql, [$courseid, $courseid]);
            foreach ($recordset as $row) {
                $rows[] = $row;
            }
            $recordset->close();

            // The CTE keeps walking past the first time it detects a loop (up to the depth
            // cap), so a single cycle produces one row per extra lap around it — e.g. a
            // 1-section self-loop yields 9 rows (depths 2-10), not 1. Deduplicate to one
            // row per affected root_section (keeping the shortest path as the clearest
            // example) so the reported count matches the number of sections actually
            // involved, consistent with how row-level highlighting already deduplicates.
            $bysection = [];
            foreach ($rows as $row) {
                if (!isset($bysection[$row->root_section])
                        || strlen($row->path) < strlen($bysection[$row->root_section]->path)) {
                    $bysection[$row->root_section] = $row;
                }
            }
            $rows = array_values($bysection);

            if (!empty($rows)) {
                $result['issues']['circular_refs'] = $rows;
                $result['counts']['circular_refs'] = count($rows);
                $result['has_issues'] = true;
            }
        } catch (\dml_exception $e) {
            // Recursive CTE not supported on this DB engine, or a query-level failure —
            // log for developers/admins rather than silently reporting "no issues".
            debugging('Circular reference check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // --- Check 8: Orphaned sections (hidden, no activities, cleanup target) ---
        $orphanedsectionssql = "SELECT cs.id, cs.section, cs.name
                                  FROM {course_sections} cs
                                 WHERE cs.course = ?
                                   AND cs.visible = 0
                                   AND cs.section > 0
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {course_modules} cm WHERE cm.section = cs.id
                                   )";
        $rows = $DB->get_records_sql($orphanedsectionssql, [$courseid]);
        if (!empty($rows)) {
            $result['issues']['orphaned_sections'] = array_values($rows);
            $result['counts']['orphaned_sections'] = count($rows);
            $result['has_issues'] = true;
        }

        // --- Section detail table data ---
        $sectionssql = "SELECT cs.id, cs.course, cs.section, cs.name, cs.visible, cs.sequence,
                               cfo.value AS parent
                          FROM {course_sections} cs
                          LEFT JOIN {course_format_options} cfo
                            ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                         WHERE cs.course = ?
                         ORDER BY cs.section ASC";
        $sectionrows = $DB->get_records_sql($sectionssql, [$courseid]);

        // Index by section number for depth calc.
        $bynum = [];
        foreach ($sectionrows as $s) {
            $s->activitycount = empty($s->sequence) ? 0 : count(explode(',', trim($s->sequence, ',')));
            if ($s->section == 0) {
                $s->parent = null;
            } else {
                $s->parent = $s->parent !== null ? $s->parent : '0';
            }
            $bynum[$s->section] = $s;
        }

        // Build issue sets for quick row-level lookup.
        $invalids  = array_column($result['issues']['invalid_parents'], null, 'id');
        $nulls     = array_column($result['issues']['null_parents'], null, 'id');
        $missing   = array_column($result['issues']['missing_parents'], null, 'id');
        $orphsects = array_column($result['issues']['orphaned_sections'], null, 'id');

        // Build set of circular root sections.
        $circularsections = [];
        foreach ($result['issues']['circular_refs'] as $cr) {
            $circularsections[$cr->root_section] = true;
        }

        $depthcache = [];
        foreach ($bynum as $num => $s) {
            $depthcache[$num] = self::calculate_depth($num, $bynum, $depthcache);
        }

        foreach ($sectionrows as $s) {
            $rowissues = [];
            if ($s->section == 0 && $s->parent !== null) {
                $rowissues[] = get_string('row_section0hasparent', 'aiplacement_modgen');
            }
            if (isset($invalids[$s->id])) {
                $rowissues[] = get_string('row_invalidparent', 'aiplacement_modgen', $invalids[$s->id]->parentnum);
            }
            if (isset($nulls[$s->id])) {
                $rowissues[] = get_string('row_nullparent', 'aiplacement_modgen');
            }
            if (isset($missing[$s->id])) {
                $rowissues[] = get_string('row_missingparent', 'aiplacement_modgen');
            }
            if (isset($circularsections[$s->section])) {
                $rowissues[] = get_string('row_circularref', 'aiplacement_modgen');
            }
            if (isset($orphsects[$s->id])) {
                $rowissues[] = get_string('row_orphaned', 'aiplacement_modgen');
            }

            $s->depth = $depthcache[$s->section] ?? 0;
            $s->row_issues = $rowissues;
            $s->has_row_issues = !empty($rowissues);
            $result['sections'][] = $s;
        }

        return $result;
    }

    /**
     * Fix all repairable integrity issues: orphaned format options, invalid/null/missing
     * parents, section-0-with-parent. Does NOT fix circular refs or orphaned sections
     * (those have separate methods).
     *
     * @param int $courseid Course ID
     * @return array ['fixed' => int, 'details' => string[]]
     */
    public static function fix_integrity(int $courseid): array {
        global $DB;

        $fixed = 0;
        $details = [];

        // Fix section 0 with parent.
        $section0sql = "SELECT cfo.*
                          FROM {course_format_options} cfo
                          JOIN {course_sections} cs ON cs.id = cfo.sectionid
                         WHERE cs.course = ?
                           AND cs.section = 0
                           AND cfo.name = 'parent'
                           AND cfo.value IS NOT NULL";
        $rows = $DB->get_records_sql($section0sql, [$courseid]);
        foreach ($rows as $row) {
            $DB->delete_records('course_format_options', ['id' => $row->id]);
            $fixed++;
            $details[] = get_string('detail_removedsection0parent', 'aiplacement_modgen');
        }

        // Fix orphaned format options.
        $orphanedsql = "SELECT cfo.*
                          FROM {course_format_options} cfo
                         WHERE cfo.courseid = ?
                           AND cfo.sectionid NOT IN (
                               SELECT id FROM {course_sections} WHERE course = ?
                           )";
        $rows = $DB->get_records_sql($orphanedsql, [$courseid, $courseid]);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $DB->delete_records('course_format_options', ['id' => $row->id]);
                $fixed++;
            }
            $details[] = get_string('detail_removedorphanedoptions', 'aiplacement_modgen', count($rows));
        }

        // Fix invalid parent references (set to 0 = top level).
        $invalidsql = "SELECT cs.*, cfo.value AS parentnum
                         FROM {course_sections} cs
                         JOIN {course_format_options} cfo
                           ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                        WHERE cs.course = ?
                          AND " . $DB->sql_cast_char2int('cfo.value') . " > 0
                          AND " . $DB->sql_cast_char2int('cfo.value') . " NOT IN (
                              SELECT section FROM {course_sections} WHERE course = ?
                          )";
        $rows = $DB->get_records_sql($invalidsql, [$courseid, $courseid]);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $DB->set_field('course_format_options', 'value', '0', [
                    'sectionid' => $row->id,
                    'name'      => 'parent',
                ]);
                $fixed++;
            }
            $details[] = get_string('detail_resetinvalidparents', 'aiplacement_modgen', count($rows));
        }

        // Fix null/empty parent values.
        $nullsql = "SELECT cs.*, cfo.id AS cfoid
                      FROM {course_sections} cs
                      JOIN {course_format_options} cfo
                        ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                     WHERE cs.course = ?
                       AND cs.section > 0
                       AND (cfo.value IS NULL OR cfo.value = '')";
        $rows = $DB->get_records_sql($nullsql, [$courseid]);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $DB->set_field('course_format_options', 'value', '0', ['id' => $row->cfoid]);
                $fixed++;
            }
            $details[] = get_string('detail_fixednullparents', 'aiplacement_modgen', count($rows));
        }

        // Insert missing parent options.
        $missingsql = "SELECT cs.*
                         FROM {course_sections} cs
                        WHERE cs.course = ?
                          AND cs.section > 0
                          AND NOT EXISTS (
                              SELECT 1 FROM {course_format_options} cfo
                               WHERE cfo.sectionid = cs.id AND cfo.name = 'parent'
                          )";
        $rows = $DB->get_records_sql($missingsql, [$courseid]);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $DB->insert_record('course_format_options', (object)[
                    'courseid'  => $courseid,
                    'format'    => 'flexsections',
                    'sectionid' => $row->id,
                    'name'      => 'parent',
                    'value'     => '0',
                ]);
                $fixed++;
            }
            $details[] = get_string('detail_insertedmissingparents', 'aiplacement_modgen', count($rows));
        }

        if ($fixed > 0) {
            rebuild_course_cache($courseid, false, true);
        }

        return ['fixed' => $fixed, 'details' => $details];
    }

    /**
     * Delete hidden course sections with no activities (orphaned sections cleanup).
     *
     * @param int $courseid Course ID
     * @return array ['deleted' => int]
     */
    public static function cleanup_orphaned(int $courseid): array {
        global $DB;

        $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $sql = "SELECT cs.id, cs.section, cs.name
                  FROM {course_sections} cs
                 WHERE cs.course = ?
                   AND cs.visible = 0
                   AND cs.section > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM {course_modules} cm WHERE cm.section = cs.id
                   )";
        $sections = $DB->get_records_sql($sql, [$courseid]);

        if (empty($sections)) {
            return ['deleted' => 0];
        }

        foreach ($sections as $section) {
            $DB->delete_records('course_format_options', ['sectionid' => $section->id]);
            $DB->delete_records('course_sections', ['id' => $section->id]);
        }

        rebuild_course_cache($courseid, false, true);

        return ['deleted' => count($sections)];
    }

    /**
     * Fix circular parent references by resetting looping sections to top-level.
     *
     * @param int $courseid Course ID
     * @return array ['fixed' => int, 'details' => string[]]
     */
    public static function fix_circular(int $courseid): array {
        global $DB;

        $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $sql = "SELECT cs.id, cs.course, cs.section, cs.name, cfo.value AS parent
                  FROM {course_sections} cs
                  LEFT JOIN {course_format_options} cfo
                    ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                 WHERE cs.course = ?
                 ORDER BY cs.section ASC";
        $sections = $DB->get_records_sql($sql, [$courseid]);

        // Index by section number.
        $bynum = [];
        foreach ($sections as $s) {
            $bynum[$s->section] = $s;
        }

        $fixed = 0;
        $details = [];
        $transaction = $DB->start_delegated_transaction();

        try {
            // Fix section 0 if it has a parent.
            if (isset($bynum[0]) && !empty($bynum[0]->parent)) {
                $DB->delete_records('course_format_options', [
                    'sectionid' => $bynum[0]->id,
                    'name'      => 'parent',
                ]);
                $fixed++;
                $details[] = get_string('detail_removedsection0parent', 'aiplacement_modgen');
            }

            foreach ($sections as $s) {
                if (!$s->parent || $s->parent === '0') {
                    continue;
                }

                $visited = [];
                $current = $s;
                $depth = 0;
                $hascircular = false;

                while ($current && $current->parent !== '0' && $depth < 20) {
                    if (isset($visited[$current->section])) {
                        $hascircular = true;
                        break;
                    }
                    $visited[$current->section] = true;
                    $parentnum = $current->parent;
                    $current = $bynum[$parentnum] ?? null;
                    $depth++;
                }

                if ($hascircular) {
                    $DB->set_field('course_format_options', 'value', '0', [
                        'sectionid' => $s->id,
                        'name'      => 'parent',
                    ]);
                    $fixed++;
                    $details[] = get_string('detail_brokecircular', 'aiplacement_modgen', (object) [
                        'name'    => format_string($s->name),
                        'section' => $s->section,
                    ]);
                }
            }

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // Outside the transaction: allow_commit() disposes it, so a failure here must not
        // attempt a rollback (rollback() on a disposed transaction throws its own
        // "already disposed" exception, masking the real error).
        if ($fixed > 0) {
            rebuild_course_cache($courseid, false, true);
        }

        return ['fixed' => $fixed, 'details' => $details];
    }

    /**
     * Calculate section depth using a memoised recursive walk.
     *
     * @param int $sectionnum Section number
     * @param array $bynum Sections indexed by section number
     * @param array &$cache Memoisation cache (passed by ref)
     * @return int Depth (0 = top-level, max guard = 20)
     */
    private static function calculate_depth(int $sectionnum, array $bynum, array &$cache): int {
        if (isset($cache[$sectionnum])) {
            return $cache[$sectionnum];
        }

        $section = $bynum[$sectionnum] ?? null;
        if (!$section || !$section->parent || $section->parent === '0' || $section->section == 0) {
            $cache[$sectionnum] = 0;
            return 0;
        }

        // Guard against circular references during depth calculation.
        $cache[$sectionnum] = -1; // Sentinel.
        $parentdepth = self::calculate_depth((int)$section->parent, $bynum, $cache);
        if ($parentdepth === -1) {
            // Circular — stop here.
            $cache[$sectionnum] = 0;
        } else {
            $cache[$sectionnum] = $parentdepth + 1;
        }

        return $cache[$sectionnum];
    }
}
