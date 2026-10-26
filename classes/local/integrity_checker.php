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
            'possible_format_switch_wipe' => false,
            'counts'     => [
                'section0_with_parent' => 0,
                'orphaned_options'     => 0,
                'invalid_parents'      => 0,
                'null_parents'         => 0,
                'missing_parents'      => 0,
                'duplicate_sections'   => 0,
                'circular_refs'        => 0,
                'orphaned_sections'    => 0,
                'section_order'        => 0,
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
                'section_order'        => [],
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
                           AND cfo.sectionid <> 0 -- Course-level format options are stored with section id 0.
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

            // Distinguish an isolated missing row (e.g. a raw import gap, harmless — the
            // section was never nested) from every non-root section losing its parent row at
            // once. The latter is the signature of a course being switched away from
            // flexsections and back: core Moodle hard-deletes all course_format_options rows
            // for the old format on switch-away (course/lib.php update_course()), and
            // switching back re-creates sections with no 'parent' row at all rather than an
            // explicit '0'. Once every section is missing the row, defaulting each to 0 makes
            // a formerly-nested course look like it was always flat — real nesting information
            // is gone, not merely defaulted, so this is surfaced as a distinct warning rather
            // than silently folded into the generic missing_parents count. Gated at >= 2
            // sections so a single-section course (where "all" and "one" coincide) doesn't
            // read as a wipe from what's just as likely an isolated gap.
            $nonrootcount = $DB->count_records_select('course_sections', 'course = ? AND section > 0', [$courseid]);
            if ($nonrootcount >= 2 && count($rows) === $nonrootcount) {
                $result['possible_format_switch_wipe'] = true;
            }
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
        // Walked in PHP over the parent map rather than with a recursive CTE, whose casts and
        // string concatenation are not portable across the databases Moodle supports.
        $rows = self::find_circular_refs(self::get_parent_map($courseid));
        if (!empty($rows)) {
            $result['issues']['circular_refs'] = $rows;
            $result['counts']['circular_refs'] = count($rows);
            $result['has_issues'] = true;
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

        // Check 9: Section numbering order (see check_section_order() for what "order" means
        // here and why it can only be judged on an otherwise-sound hierarchy). Runs last
        // because it is the one check that is meaningless while any of the checks above are
        // reporting — it declines to judge in exactly those cases.
        $ordercheck = self::check_section_order($courseid);
        if (!empty($ordercheck['misordered'])) {
            $result['issues']['section_order'] = $ordercheck['misordered'];
            $result['counts']['section_order'] = count($ordercheck['misordered']);
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
        $misordered = array_column($result['issues']['section_order'], null, 'id');

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
            if (isset($misordered[$s->id])) {
                $rowissues[] = get_string('row_wrongorder', 'aiplacement_modgen', $misordered[$s->id]->expected);
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
     *   represent lost nesting information in the ordinary case, so there's nothing to suggest
     *   for them. The exception is a wholesale missing_parents wipe (see check()'s
     *   possible_format_switch_wipe flag) — there the original parent values are genuinely
     *   gone, not merely un-set, and no heuristic here can recover them; this method still
     *   just resets those rows to top-level, which is the only safe automatic choice.
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
                               AND cfo.sectionid <> 0 -- Course-level format options are stored with section id 0.
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

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $sql = "SELECT cs.id, cs.section, cs.name
                  FROM {course_sections} cs
                 WHERE cs.course = ?
                   AND cs.visible = 0
                   AND cs.section > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM {course_modules} cm WHERE cm.section = cs.id
                   )
              ORDER BY cs.section DESC";
        $sections = $DB->get_records_sql($sql, [$courseid]);

        if (empty($sections)) {
            return ['deleted' => 0];
        }

        // Delete through the format rather than from the tables: it renumbers the sections that
        // follow (a gap in the numbers breaks the course) and keeps their parent references right.
        // A section that still has subsections is left alone, as deleting it would orphan them.
        $format = course_get_format($course);
        $deleted = 0;
        foreach ($sections as $section) {
            $info = get_fast_modinfo($courseid)->get_section_info_by_id((int) $section->id);
            if (!$info || $format->get_subsections($info)) {
                continue;
            }
            [$deletedsections] = $format->delete_section_with_children($info);
            $deleted += count($deletedsections);
        }

        return ['deleted' => $deleted];
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
     * Check whether section numbers follow the depth-first order format_flexsections expects.
     *
     * flexsections treats section numbers as positional, not as identities: after any move it
     * renumbers every section so that a pre-order depth-first walk of the tree (starting at
     * section 0, visiting each node's children in ascending section-number order) yields
     * 0, 1, 2, 3... with no gaps — i.e. a section's children always immediately follow it.
     * That invariant is maintained only inside format_flexsections::move_section(), which
     * set_parent() deliberately does not call (see its docblock). Repeated manual reparenting
     * therefore leaves the parent references correct but the numbering scrambled. That is not
     * a display problem: delete_section_with_children() deletes the chosen section and every
     * section numbered after it, on the assumption that what follows a section is its own
     * subtree. Out of order, that assumption is false in both directions — the delete either
     * takes unrelated sections with it, or leaves the doomed section's descendants behind
     * pointing at a number that a later section will quietly reoccupy — and neither outcome
     * is reported as an error. section_has_parent() makes the same assumption, refusing to
     * recognise an ancestor whose number is higher than its descendant's, which lets
     * can_move_section_to() approve a move of a section into its own subtree.
     *
     * Refuses to judge ordering on a course whose hierarchy is not sound in the first place:
     * a duplicate number, an unresolvable parent, a cycle or a section unreachable from
     * section 0 all mean there is no single correct traversal to compare against, and
     * renumbering such a course would shuffle sections on the basis of a broken tree. Those
     * problems have their own checks and fixes, which must be applied first.
     *
     * @param int $courseid Course ID
     * @return array {
     *   checkable: bool,     whether an expected order could be determined at all
     *   blockedby: string|null  'notflexsections' or 'brokenhierarchy' when not checkable
     *   misordered: array    rows {id, section, name, expected} for sections whose number
     *                        differs from its position in the expected traversal
     *   expected: array<int,int>  section id => expected section number (empty if unchecked)
     * }
     */
    public static function check_section_order(int $courseid): array {
        global $DB;

        $result = ['checkable' => false, 'blockedby' => null, 'misordered' => [], 'expected' => []];

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        if ($course->format !== 'flexsections') {
            $result['blockedby'] = 'notflexsections';
            return $result;
        }

        $rows = $DB->get_records_sql(
            "SELECT cs.id, cs.section, cs.name, cfo.value AS parent
               FROM {course_sections} cs
               LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = ?
              ORDER BY cs.section ASC",
            [$courseid]
        );

        $expected = self::build_canonical_order($rows);
        if ($expected === null) {
            $result['blockedby'] = 'brokenhierarchy';
            return $result;
        }

        $result['checkable'] = true;
        $result['expected'] = $expected;

        foreach ($rows as $row) {
            $target = $expected[(int) $row->id];
            if ((int) $row->section !== $target) {
                $result['misordered'][] = (object) [
                    'id'       => (int) $row->id,
                    'section'  => (int) $row->section,
                    'name'     => $row->name,
                    'expected' => $target,
                ];
            }
        }

        return $result;
    }

    /**
     * Renumber a course's sections into the depth-first order format_flexsections expects.
     *
     * Reproduces exactly what format_flexsections::move_section() does after a move — the
     * traversal of reorder_sections() to derive the new numbers, the two-pass write through
     * negative section values to stay inside the (course, section) unique index, the rewrite
     * of every 'parent' option that pointed at a renumbered section, and the marker fix-up —
     * but with no section actually changing place, which is the one thing move_section()
     * cannot express: can_move_section_to() rejects every positional no-op ("do not insert
     * section directly before or after itself"), so there is no argument combination that
     * makes it renumber while leaving the tree alone. Driving it into doing so anyway would
     * mean a real move followed by a move back — two rebuilds, and a genuinely relocated
     * section left behind if the second leg fails.
     *
     * Calling the format's own methods is avoided here for the same reason set_parent() avoids
     * them: move_section(), can_move_section_to() and reorder_sections() all recurse over the
     * section tree with no cycle protection, and this runs on courses that may be broken.
     * check_section_order() has already established the tree is sound before we get here.
     *
     * Only parent references and section numbers are touched — no section changes parent, so
     * unlike a real move there is no visibility cascade to apply.
     *
     * @param int $courseid Course ID
     * @param bool $locksheld True when the caller already holds the structure locks (the
     *   post-build tidy-up in theme_builder does), so they must not be acquired again.
     * @return array {
     *   fixed: int           number of sections given a new number (0 if already in order)
     *   checkable: bool      false if the ordering could not be determined; nothing was written
     *   blockedby: string|null  'notflexsections' or 'brokenhierarchy' when not checkable
     * }
     */
    public static function fix_section_order(int $courseid, bool $locksheld = false): array {
        // The check_section_order() call snapshots every section's number and the whole write below
        // is derived from that snapshot. A concurrent format_flexsections delete or move
        // renumbers the same rows, so snapshot and write have to happen under the same
        // structure locks a build holds - otherwise this commits a stale numbering over
        // whatever the other operation just did. Callers already inside those locks
        // (theme_builder's post-build tidy-up) pass $locksheld and must not re-acquire.
        $locks = $locksheld ? [] : theme_builder::acquire_structure_locks($courseid);

        try {
            return self::apply_section_order($courseid);
        } finally {
            if (!$locksheld) {
                theme_builder::release_structure_locks($locks);
            }
        }
    }

    /**
     * Renumber the course into hierarchy order. Caller guarantees the structure locks are held.
     *
     * @param int $courseid Course ID
     * @return array Same shape as fix_section_order()
     */
    private static function apply_section_order(int $courseid): array {
        global $DB;

        $check = self::check_section_order($courseid);
        if (!$check['checkable']) {
            return ['fixed' => 0, 'checkable' => false, 'blockedby' => $check['blockedby']];
        }
        if (empty($check['misordered'])) {
            return ['fixed' => 0, 'checkable' => true, 'blockedby' => null];
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $format = \course_get_format($courseid);

        // Old number => new number, for every section that moves. Also drives the parent
        // rewrite below: a parent option stores a section *number*, so every option pointing
        // at a renumbered section has to follow it.
        $renumber = [];
        foreach ($check['misordered'] as $row) {
            $renumber[$row->section] = $row->expected;
        }

        // Sections whose stored parent number is about to change meaning. Read before the
        // renumbering writes so the old values are still in place.
        $sections = $DB->get_records_sql(
            "SELECT cs.id, cs.section, cfo.value AS parent
               FROM {course_sections} cs
               LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = ?",
            [$courseid]
        );
        $changeparent = [];
        foreach ($sections as $s) {
            $parentnum = ($s->parent === null || $s->parent === '') ? 0 : (int) $s->parent;
            if (isset($renumber[$parentnum])) {
                $changeparent[(int) $s->id] = $renumber[$parentnum];
            }
        }

        $changemarker = null;
        if ($course->marker && isset($renumber[(int) $course->marker])) {
            $changemarker = $renumber[(int) $course->marker];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // Two passes via negative numbers: the target number is almost always already
            // taken by another section that is itself about to move, so a single pass would
            // violate the (course, section) unique index part-way through.
            foreach ($check['misordered'] as $row) {
                $DB->set_field('course_sections', 'section', -$row->expected, ['id' => $row->id]);
            }
            foreach ($check['misordered'] as $row) {
                $DB->set_field('course_sections', 'section', $row->expected, ['id' => $row->id]);
            }
            foreach ($changeparent as $sectionid => $newparentnum) {
                $format->update_section_format_options(['id' => $sectionid, 'parent' => $newparentnum]);
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // Outside the transaction: allow_commit() disposes it, so a failure from here on must
        // not attempt a rollback, which would throw its own "already disposed" exception and
        // mask the real error.
        rebuild_course_cache($courseid, true);
        if ($changemarker !== null) {
            \course_set_marker($courseid, $changemarker);
        }

        return ['fixed' => count($check['misordered']), 'checkable' => true, 'blockedby' => null];
    }

    /**
     * Derive the section number each section should have, by the traversal flexsections uses.
     *
     * Pre-order depth-first from section 0, children visited in ascending current section
     * number — the ordering format_flexsections::get_subsections() imposes, since it filters
     * get_sections(), which is keyed by section number ascending.
     *
     * @param array $rows course_sections rows with an added 'parent' column, ordered by
     *   section number ascending
     * @return array<int,int>|null section id => expected section number, or null when the
     *   hierarchy cannot be traversed (no section 0, duplicate numbers, a parent that doesn't
     *   resolve, a cycle, or a section unreachable from section 0)
     */
    private static function build_canonical_order(array $rows): ?array {
        $bynum = [];
        $children = [];

        foreach ($rows as $row) {
            $num = (int) $row->section;
            if (isset($bynum[$num])) {
                // Duplicate section number: the tree has no single well-defined traversal.
                return null;
            }
            $bynum[$num] = $row;
        }
        if (!isset($bynum[0])) {
            return null;
        }

        foreach ($rows as $row) {
            $num = (int) $row->section;
            if ($num === 0) {
                continue;
            }
            // A missing or empty parent option means top-level everywhere else in this class,
            // and means the same here.
            $parentnum = ($row->parent === null || $row->parent === '') ? 0 : (int) $row->parent;
            if (!isset($bynum[$parentnum]) || $parentnum === $num) {
                return null;
            }
            $children[$parentnum][] = $num;
        }

        // Rows arrive ordered by section number, so each child list is already ascending.
        $order = [];
        $stack = [0];
        $visited = [];
        while ($stack) {
            $num = array_pop($stack);
            if (isset($visited[$num])) {
                // Reached twice: a cycle, or two parents claiming the same child.
                return null;
            }
            $visited[$num] = true;
            $order[(int) $bynum[$num]->id] = count($order);
            // Pushed in reverse so the lowest-numbered child is popped (and so numbered) first.
            foreach (array_reverse($children[$num] ?? []) as $childnum) {
                $stack[] = $childnum;
            }
        }

        if (count($order) !== count($bynum)) {
            // Sections not reachable from section 0 — a detached subtree hanging off a cycle.
            return null;
        }

        return $order;
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
     * The sections that lie on a loop of parent links.
     *
     * From each section, follows parent links up to 10 steps; if the walk comes back to the
     * starting section, that section is reported once, with the shortest such loop. Missing,
     * empty or non-numeric parent values end a walk rather than failing the check.
     *
     * @param array<int, string|null> $parentmap Section number => parent value, from get_parent_map().
     * @return \stdClass[] One row per section on a loop: root_section, and path such as "5->7->5".
     */
    private static function find_circular_refs(array $parentmap): array {
        $parentof = function (int $section) use ($parentmap): ?int {
            $value = $parentmap[$section] ?? null;
            return ($value !== null && $value !== '' && ctype_digit((string) $value)) ? (int) $value : null;
        };

        $rows = [];
        foreach (array_keys($parentmap) as $root) {
            if ($root <= 0) {
                continue;
            }
            $path = [$root];
            $current = $parentof($root);
            for ($depth = 1; $current !== null && $depth < 10 && array_key_exists($current, $parentmap); $depth++) {
                $path[] = $current;
                if ($current === $root) {
                    $rows[] = (object) ['root_section' => $root, 'path' => implode('->', $path)];
                    break;
                }
                $current = $parentof($current);
            }
        }
        return $rows;
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
     * plugin, deliberately NOT format_flexsections::move_section(), which is what the section
     * control menu's own Move dialog calls (only drag-and-drop is disabled there: "it does not
     * really work yet"). That method skips the core course_update_section() sync path, and,
     * critically for a tool whose whole job is repairing courses that may have other unrelated
     * broken parent chains at the moment it runs, both it and can_move_section_to() recurse
     * over the section tree with no cycle protection, and move_section() answers a hierarchy
     * it cannot traverse with a bare die('Error in sections hierarchy') mid-request rather
     * than an exception this tool could catch and report.
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
     * Does not renumber, so repeated reparenting leaves section numbers out of the tidy
     * "children immediately follow their parent" order flexsections otherwise maintains. The
     * parent references stay correct, but the numbering does not merely look untidy: section
     * deletion is unsafe until it is repaired (see check_section_order()). That is the
     * deliberate split rather than an accepted cost — reparent freely here, then renumber
     * once via fix_section_order(), which is what check_structure.php's "Renumber Sections"
     * does and what theme_builder runs at the end of every build.
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
