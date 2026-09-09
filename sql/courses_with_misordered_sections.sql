-- Courses whose flexsections section numbering has drifted out of hierarchy order.
--
-- format_flexsections numbers sections by their position in the hierarchy: section 0
-- first, then every section followed immediately by its own subsections. Only
-- move_section() maintains that. Reparenting a section without moving it -- which is
-- what aiplacement_modgen's builder did before it renumbered, and what the parent
-- pickers in its Check Structure tool still do -- leaves the parent references correct
-- but the numbering scrambled.
--
-- That is not cosmetic and it is invisible on the course page (the renderer walks the
-- parent tree, and only uses numbers to order siblings within one level). It matters
-- because removing a section removes it and every section numbered after it, on the
-- assumption that what follows a section is its own subtree. Out of order, that
-- assumption fails in both directions: the removal either takes unrelated sections with
-- it, or strands the descendants of the section that was removed. See
-- format_flexsections' section-removal routine, and check_section_order() in
-- aiplacement_modgen's integrity checker.
--
-- The last column links to the plugin's structure tools for each listed course. Note
-- that the numbering check and its "Renumber Sections" repair only exist in the plugin
-- from the section-numbering work onwards. This query needs none of that -- it reads the
-- tables directly, so it finds affected courses whatever version is installed -- but on
-- an older build the linked tool will not report or repair the numbering, and moving any
-- one section in such a course is the workaround, since that makes format_flexsections
-- renumber the whole course itself.
--
-- Three things this file must never contain, anywhere in its text -- comments included,
-- because both report_customsql and Moodle's DML scan the whole submission rather than
-- just the executable part:
--
--   1. A data-modifying SQL keyword. report_customsql rejects those on sight, which is
--      why the prose says "removing a section" and "renumbers to" where the natural
--      phrasing would not. It names the word it objected to, so re-read any comment you
--      edit here.
--   2. A semicolon. Also rejected by report_customsql, so the prose uses full stops
--      where it would otherwise use one.
--   3. A question mark. Not a plugin rule but a Moodle one -- see the note further down
--      beside chr(63).
--
-- PostgreSQL only: uses recursive CTEs, array ordering, the ~ regex operator and
-- FILTER. Written for report_customsql, so table names carry the prefix_ placeholder.
--
-- Performance: the format filter is the whole story -- everything downstream sees only
-- sections belonging to flexsections courses, and the format_options join names all four
-- columns of the 'formatoption' unique index so it is an index scan rather than a lookup
-- on sectionid alone. What does not index is the recursive step: PostgreSQL cannot index
-- a CTE, so each iteration rescans the section list, making this roughly
-- (sections in flexsections courses) x (deepest nesting). Fine at a few thousand
-- sections. If most of a large site uses flexsections, run it scheduled rather than
-- interactively, or add a course filter to the fs CTE below.
WITH RECURSIVE fs AS (
    -- Nothing but flexsections numbers sections hierarchically, so this is both the
    -- correctness boundary and the thing that keeps the query cheap on a large site.
    SELECT c.id AS courseid, c.shortname
      FROM prefix_course c
     WHERE c.format = 'flexsections'
),
sect AS (
    -- One row per section with its parent resolved to an integer. A missing, empty or
    -- non-numeric 'parent' means top level, matching how both format_flexsections and
    -- the modgen integrity checker read it.
    -- The format_options join names courseid, format, sectionid and name so it matches
    -- the full 'formatoption' unique index rather than scanning on sectionid alone.
    SELECT cs.course   AS courseid,
           cs.id       AS sectionid,
           cs.section  AS num,
           CASE WHEN cfo.value ~ '^[0-9]+$' THEN cfo.value::int ELSE 0 END AS parentnum
      FROM prefix_course_sections cs
      JOIN fs ON fs.courseid = cs.course
      LEFT JOIN prefix_course_format_options cfo
             ON cfo.courseid  = cs.course
            AND cfo.format    = 'flexsections'
            AND cfo.sectionid = cs.id
            AND cfo.name      = 'parent'
),
walk AS (
    -- Depth-first from section 0, children visited in ascending section number. Sorting
    -- by the accumulated path array reproduces exactly the pre-order that
    -- format_flexsections::reorder_sections() renumbers to.
    SELECT courseid, sectionid, num, ARRAY[num] AS path, 1 AS depth
      FROM sect
     WHERE num = 0
    UNION ALL
    SELECT c.courseid, c.sectionid, c.num, w.path || c.num, w.depth + 1
      FROM sect c
      JOIN walk w ON w.courseid = c.courseid AND w.num = c.parentnum
     WHERE c.num <> 0
       AND w.depth < 25   -- Guard only. flexsections caps real depth far below this.
),
expected AS (
    SELECT courseid, sectionid, num,
           ROW_NUMBER() OVER (PARTITION BY courseid ORDER BY path) - 1 AS shouldbe
      FROM walk
)
SELECT fs.courseid                                                          AS "Course ID",
       fs.shortname                                                         AS "Short name",
       COUNT(*)                                                             AS "Sections",
       COUNT(*) FILTER (WHERE e.shouldbe IS NOT NULL AND e.shouldbe <> s.num) AS "Out of order",
       -- Sections the walk could not reach from section 0: a dangling parent reference or
       -- a detached loop. There is no single correct order for such a course, so its
       -- numbering is not judged here -- repair the parent references first.
       COUNT(*) FILTER (WHERE e.sectionid IS NULL)                          AS "Unreachable",
       -- chr(63) is a question mark. Moodle's DML counts every question mark in a
       -- query as a positional placeholder (moodle_database::fix_sql_params() just calls
       -- substr_count, so string literals and even comments count), and report_customsql
       -- runs queries through it. Writing these URLs the obvious way therefore fails with
       -- "Incorrect number of query parameters". Do not reintroduce the character
       -- anywhere in this file, comments included.
       '%%WWWROOT%%/course/view.php'
           || chr(63) || 'id=' || fs.courseid                               AS "Course",
       -- admin_tools.php rather than check_structure.php: the former is what the plugin
       -- version currently in production exposes. It takes courseid (not id) and
       -- prefills its course box from the URL, but every action there needs a sesskey,
       -- so this link lands on the tools page with the course filled in and the check is
       -- one click away. Site admins only, same as this report.
       '%%WWWROOT%%/ai/placement/modgen/admin_tools.php'
           || chr(63) || 'courseid=' || fs.courseid                         AS "Structure tools"
  FROM sect s
  JOIN fs ON fs.courseid = s.courseid
  LEFT JOIN expected e ON e.sectionid = s.sectionid
 GROUP BY fs.courseid, fs.shortname
HAVING COUNT(*) FILTER (WHERE e.shouldbe IS NOT NULL AND e.shouldbe <> s.num) > 0
    OR COUNT(*) FILTER (WHERE e.sectionid IS NULL) > 0
 ORDER BY 4 DESC, 1
