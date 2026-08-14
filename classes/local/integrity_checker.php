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

        // Check 1: Section 0 with a parent value.
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

        // Check 2: Orphaned format options (options for deleted sections).
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

        // Check 3: Invalid parent references (parent points to non-existent section number).
        // Null/empty values are check 4's domain (null_parents below) — exclude them here,
        // otherwise a non-numeric parent value fails the CAST for the whole query rather
        // than just being skipped.
        $invalidsql = "SELECT cs.*, cfo.value AS parentnum
                         FROM {course_sections} cs
                         JOIN {course_format_options} cfo
                           ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                        WHERE cs.course = ?
                          AND cfo.value IS NOT NULL AND cfo.value <> ''
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

        // Check 4: Null or empty parent values (sections > 0).
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

        // Check 5: Sections missing the parent format option entirely.
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

        // Check 6: Duplicate section numbers.
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

        // Check 7: Circular references.
        // Null/empty parent values are excluded before the CAST in both arms below — same
        // reasoning as check 3: a non-numeric value must not fail the cast for every row.
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
                               AND cfo.value IS NOT NULL AND cfo.value <> ''
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
                               AND cfo.value IS NOT NULL AND cfo.value <> ''
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
                if (
                    !isset($bysection[$row->root_section])
                        || strlen($row->path) < strlen($bysection[$row->root_section]->path)
                ) {
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

        // Check 8: Orphaned sections (hidden, no activities, cleanup target).
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

        // Section detail table data.
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
     * @return array ['fixed' => int, 'details' => string[], 'reparented' => array{id:int,
     *   section:int, name:string, suggestedparent:int|null}[]] reparented covers only the
     *   invalid_parents branch; the other branches (missing/null/section-0/orphaned) don't
     *   represent lost nesting information, so there's nothing to suggest for them.
     */
    public static function fix_integrity(int $courseid): array {
        global $DB;

        $fixed = 0;
        $details = [];
        $reparented = [];
        $transaction = $DB->start_delegated_transaction();

        try {
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
            // Null/empty values are handled separately below (null_parents) — excluded here so
            // they don't fail the CAST for every row in this query.
            $invalidsql = "SELECT cs.*, cfo.value AS parentnum
                             FROM {course_sections} cs
                             JOIN {course_format_options} cfo
                               ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                            WHERE cs.course = ?
                              AND cfo.value IS NOT NULL AND cfo.value <> ''
                              AND " . $DB->sql_cast_char2int('cfo.value') . " > 0
                              AND " . $DB->sql_cast_char2int('cfo.value') . " NOT IN (
                                  SELECT section FROM {course_sections} WHERE course = ?
                              )";
            $rows = $DB->get_records_sql($invalidsql, [$courseid, $courseid]);
            if (!empty($rows)) {
                // Snapshot parent links before any of them get reset below, so a suggestion for
                // one broken row isn't thrown off by another broken row in the same batch having
                // already been flattened to top-level a moment earlier.
                $parentmap = self::get_parent_map($courseid);
                foreach ($rows as $row) {
                    $DB->set_field('course_format_options', 'value', '0', [
                        'sectionid' => $row->id,
                        'name'      => 'parent',
                    ]);
                    $fixed++;
                    $reparented[] = [
                        'id'              => (int) $row->id,
                        'section'         => (int) $row->section,
                        'name'            => format_string($row->name),
                        'suggestedparent' => self::suggest_parent_from_map((int) $row->section, $parentmap),
                    ];
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

        return ['fixed' => $fixed, 'details' => $details, 'reparented' => $reparented];
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
     * @return array ['fixed' => int, 'details' => string[], 'reparented' => array{id:int,
     *   section:int, name:string, suggestedparent:int|null}[]]
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
        $parentmap = [];
        foreach ($sections as $s) {
            $bynum[$s->section] = $s;
            $parentmap[(int) $s->section] = $s->parent;
        }

        $fixed = 0;
        $details = [];
        $reparented = [];
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
                if ($s->section == 0 || !$s->parent || $s->parent === '0') {
                    continue;
                }

                // Only $s itself walking back to $s counts as $s being part of a cycle.
                // Merely passing through a node the walk has already seen is not enough:
                // that also happens when $s sits downstream of a cycle it isn't a member
                // of (e.g. B -> A where A is self-looping) and must be left untouched.
                $visited = [(int) $s->section => true];
                $current = $s;
                $depth = 0;
                $hascircular = false;

                while ($current && $current->parent !== null && $current->parent !== '0' && $depth < 20) {
                    $parentnum = $current->parent;
                    $current = $bynum[$parentnum] ?? null;
                    $depth++;

                    if (!$current) {
                        break;
                    }
                    if ((int) $current->section === (int) $s->section) {
                        $hascircular = true;
                        break;
                    }
                    if (isset($visited[(int) $current->section])) {
                        // Wandered into a different, pre-existing cycle that doesn't loop
                        // back to $s — that cycle's own members will be detected when the
                        // loop reaches them directly.
                        break;
                    }
                    $visited[(int) $current->section] = true;
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
                    $reparented[] = [
                        'id'              => (int) $s->id,
                        'section'         => (int) $s->section,
                        'name'            => format_string($s->name),
                        'suggestedparent' => self::suggest_parent_from_map((int) $s->section, $parentmap),
                    ];
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

        return ['fixed' => $fixed, 'details' => $details, 'reparented' => $reparented];
    }

    /**
     * Build a map of section number => raw 'parent' format option value for a course.
     *
     * @param int $courseid Course ID
     * @return array<int, string|null> Section number => parent value (null if no option row)
     */
    private static function get_parent_map(int $courseid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT cs.section, cfo.value AS parent
               FROM {course_sections} cs
               LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = ?",
            [$courseid]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->section] = $row->parent;
        }
        return $map;
    }

    /**
     * Whether adopting $candidateparent as the parent of $sectionnum would create a cycle.
     *
     * Walks up from $candidateparent following existing parent links; if the walk reaches
     * $sectionnum, then $sectionnum is already an ancestor of $candidateparent, so the
     * proposed link would loop back on itself. Bounded so an existing, unrelated cycle
     * elsewhere in $parentmap can't spin this forever.
     *
     * @param int $candidateparent Proposed parent section number
     * @param int $sectionnum Section that would receive this parent
     * @param array<int, string|null> $parentmap Section number => parent value
     * @return bool
     */
    private static function would_create_cycle(int $candidateparent, int $sectionnum, array $parentmap): bool {
        $walk = $candidateparent;
        $depth = 0;
        while ($walk !== null && (int) $walk !== 0 && $depth < 50) {
            if ((int) $walk === $sectionnum) {
                return true;
            }
            $walk = $parentmap[(int) $walk] ?? null;
            $depth++;
        }
        return false;
    }

    /**
     * The depth $sectionnum would end up at if reparented to $newparent (1 = top-level),
     * matching format_flexsections::get_section_depth()'s numbering.
     *
     * Walks $parentmap itself rather than calling the course format's own get_section_depth(),
     * which recurses with no cycle protection at all: fine for a known-good tree, but this
     * method exists specifically to validate moves on courses that may still have other,
     * unrelated broken parent chains elsewhere at the moment it runs, and that call would hang
     * on one of those. A visited-set catches any cycle among $newparent's ancestors (whether
     * or not it involves $sectionnum) and reports the move as unsafe instead of looping.
     *
     * @param int $newparent Proposed parent section number (0 = top-level)
     * @param int $sectionnum Section that would receive this parent
     * @param array<int, string|null> $parentmap Section number => parent value
     * @return int|null Resulting depth (1 = top-level), or null if it can't be safely determined
     */
    private static function depth_after_move(int $newparent, int $sectionnum, array $parentmap): ?int {
        if ($newparent === 0) {
            return 1;
        }

        // Walk up from $newparent to compute *its* depth, then $sectionnum would sit one level
        // below that.
        $visited = [];
        $walk = $newparent;
        $parentdepth = 1;
        while (true) {
            if ($walk === $sectionnum || isset($visited[$walk])) {
                return null;
            }
            $visited[$walk] = true;

            $parent = $parentmap[$walk] ?? null;
            $parentnum = ($parent === null || $parent === '') ? 0 : (int) $parent;
            if ($parentnum === 0) {
                return $parentdepth + 1;
            }
            $walk = $parentnum;
            $parentdepth++;
        }
    }

    /**
     * Whether a section's own parent link is directly broken: self-referencing, or pointing
     * at a section number that doesn't exist in the course. Deliberately a one-hop check:
     * a candidate whose own ancestor further up is broken is still a perfectly fine immediate
     * parent for someone else, so this does not walk the whole chain.
     *
     * @param int $sectionnum Section number to check
     * @param array<int, string|null> $parentmap Section number => parent value
     * @return bool
     */
    private static function is_parent_link_broken(int $sectionnum, array $parentmap): bool {
        $parent = $parentmap[$sectionnum] ?? null;
        if ($parent === null || $parent === '') {
            return false;
        }
        $parentnum = (int) $parent;
        if ($parentnum === 0) {
            return false;
        }
        if ($parentnum === $sectionnum) {
            return true;
        }
        return !array_key_exists($parentnum, $parentmap);
    }

    /**
     * Suggest a likely original parent for a section whose parent reference is broken.
     *
     * Flexsections always numbers a section's descendants contiguously immediately after it
     * (see format_flexsections::reorder_sections()), so when a parent link is lost, the
     * nearest earlier section, skipping over any other currently-broken section, is often
     * the one it was really nested under.
     *
     * This is a heuristic for a human to review and apply via set_parent(), never a value to
     * write automatically: a genuinely top-level section is also, just as often, preceded by
     * an unrelated but perfectly valid section, and this cannot tell the two cases apart.
     *
     * @param int $sectionnum Section number needing a parent suggestion
     * @param array<int, string|null> $parentmap Section number => parent value
     * @return int|null Suggested parent section number, or null if nothing usable precedes it
     */
    private static function suggest_parent_from_map(int $sectionnum, array $parentmap): ?int {
        for ($candidate = $sectionnum - 1; $candidate > 0; $candidate--) {
            if (!array_key_exists($candidate, $parentmap)) {
                continue;
            }
            if (self::is_parent_link_broken($candidate, $parentmap)) {
                continue;
            }
            if (self::would_create_cycle($candidate, $sectionnum, $parentmap)) {
                continue;
            }
            return $candidate;
        }
        return null;
    }

    /**
     * Suggest a likely original parent for a section, reading current course state.
     *
     * Public convenience wrapper around suggest_parent_from_map() for callers that only have
     * a course id and section number to hand (e.g. rendering a suggestion in the UI outside
     * of a fix_integrity()/fix_circular() run, where the pre-fix snapshot isn't available).
     *
     * @param int $courseid Course ID
     * @param int $sectionnum Section number needing a parent suggestion
     * @return int|null Suggested parent section number, or null if nothing usable precedes it
     */
    public static function suggest_parent(int $courseid, int $sectionnum): ?int {
        return self::suggest_parent_from_map($sectionnum, self::get_parent_map($courseid));
    }

    /**
     * Directly set a section's parent to an editor-chosen value.
     *
     * This is the manual counterpart to fix_circular(): where that method can only make the
     * safe automatic choice (top-level), this lets an editor point a section at wherever it
     * actually belongs in one step (e.g. re-homing sections fix_circular() already reset to
     * top-level), or resolving a self-parent without guessing.
     *
     * Writes the 'parent' format option directly via update_section_format_options(), the same
     * primitive theme_builder::create_section_with_parent() already uses elsewhere in this
     * plugin, deliberately NOT format_flexsections::move_section(), despite that being what a
     * native drag-and-drop move would call. That method is unreachable from the real UI
     * (flexsections' own JS disables the drag handles: "it does not really work yet"), skips
     * the core course_update_section() sync path, and, critically for a tool whose whole job
     * is repairing courses that may have other unrelated broken parent chains at the moment it
     * runs, both it and can_move_section_to() recurse over the section tree with no cycle
     * protection at all, risking an unrecoverable stack overflow rather than a clean error.
     *
     * Still refuses moves that would themselves be invalid (unknown section, section 0,
     * self-parent, a move that would create a new cycle, or one that would nest too deep),
     * using our own bounded ancestor walk rather than the format's unbounded one.
     *
     * Cascades visibility for the moved section itself, matching what a (hypothetical, working)
     * manual move would do: a visible section moved under a hidden parent shouldn't stay
     * visible, and vice versa, via the core set_section_visible(), which does go through
     * course_update_section() correctly. Deliberately scoped to just this section, not its own
     * descendants: recursing through an arbitrary subtree here would reintroduce the same
     * unbounded-walk hazard this method exists to avoid.
     *
     * Does not attempt to keep sibling numbering contiguous the way flexsections' own
     * reorder_sections() does: that logic lives only inside move_section() and isn't safely
     * reusable standalone, so repeated manual reparenting can leave section numbers out of the
     * tidy "children immediately follow their parent" order flexsections otherwise maintains.
     * Structurally harmless (parent references stay correct either way), just cosmetic.
     *
     * @param int $courseid Course ID
     * @param int $sectionnum Section number to update
     * @param int $newparent New parent section number (0 = top-level)
     * @return array ['success' => bool, 'error' => string|null] error is one of:
     *   'section0', 'selfparent', 'sectionnotfound', 'parentnotfound', 'wouldcreatecycle',
     *   'maxdepthexceeded'
     */
    public static function set_parent(int $courseid, int $sectionnum, int $newparent): array {
        global $DB;

        if ($sectionnum === 0) {
            return ['success' => false, 'error' => 'section0'];
        }
        if ($sectionnum === $newparent) {
            return ['success' => false, 'error' => 'selfparent'];
        }

        $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);
        if (!$section) {
            return ['success' => false, 'error' => 'sectionnotfound'];
        }

        // Fetched for section 0 too (always exists) so the visibility cascade below can treat
        // top-level moves the same way as any other, without a separate null-check branch.
        $parentrow = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $newparent]);
        if ($newparent !== 0 && !$parentrow) {
            return ['success' => false, 'error' => 'parentnotfound'];
        }

        if ($newparent !== 0) {
            $parentmap = self::get_parent_map($courseid);

            // Reject moves that would create a new cycle: walk up from the proposed parent. If
            // that walk reaches $sectionnum, then $sectionnum is already an ancestor of
            // $newparent, so making $newparent its parent would loop back on itself.
            if (self::would_create_cycle($newparent, $sectionnum, $parentmap)) {
                return ['success' => false, 'error' => 'wouldcreatecycle'];
            }

            $newdepth = self::depth_after_move($newparent, $sectionnum, $parentmap);
            if ($newdepth === null) {
                return ['success' => false, 'error' => 'wouldcreatecycle'];
            }
            if ($newdepth > \course_get_format($courseid)->get_max_section_depth()) {
                return ['success' => false, 'error' => 'maxdepthexceeded'];
            }
        }

        \course_get_format($courseid)->update_section_format_options(['id' => $section->id, 'parent' => $newparent]);

        if ($parentrow && (int) $parentrow->visible !== (int) $section->visible) {
            \set_section_visible($courseid, $sectionnum, (int) $parentrow->visible);
        }

        rebuild_course_cache($courseid, false, true);

        return ['success' => true, 'error' => null];
    }

    /**
     * Bulk counterpart to set_parent(): applies a batch of (section, newparent) pairs in one
     * go, e.g. every suggested-parent row from the reparented-sections flash table at once
     * instead of one submit per row. Each pair is applied independently via set_parent(), so
     * one failing pair (an unrelated 'wouldcreatecycle', say) doesn't block the rest.
     *
     * @param int $courseid Course ID
     * @param int[] $sections Section numbers to update, one per pair
     * @param int[] $newparents New parent section numbers, one per pair, same order as
     *   $sections. Any index missing from either array (a malformed/truncated pair) is skipped.
     * @return array ['applied' => int, 'failed' => int] counts, not which pairs succeeded
     */
    public static function set_parents_bulk(int $courseid, array $sections, array $newparents): array {
        $applied = 0;
        $failed = 0;

        foreach ($sections as $i => $sectionnum) {
            if (!array_key_exists($i, $newparents)) {
                continue;
            }
            $result = self::set_parent($courseid, (int) $sectionnum, (int) $newparents[$i]);
            if ($result['success']) {
                $applied++;
            } else {
                $failed++;
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
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
