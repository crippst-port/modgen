-- Every individual section that is numbered out of hierarchy order, across all
-- flexsections courses. The per-section companion to
-- courses_with_misordered_sections.sql -- see that file for what "order" means here and
-- why it matters. Same PostgreSQL and report_customsql assumptions.
--
-- To narrow to one course, add its id to the fs CTE:  AND c.id = 2
--
-- As in the companion file, and for the same reasons set out there: no question mark, no
-- semicolon and no data-modifying SQL keyword anywhere in the text, comments included.
WITH RECURSIVE fs AS (
    SELECT c.id AS courseid, c.shortname
      FROM prefix_course c
     WHERE c.format = 'flexsections'
),
sect AS (
    SELECT cs.course   AS courseid,
           cs.id       AS sectionid,
           cs.section  AS num,
           cs.name     AS name,
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
    SELECT courseid, sectionid, num, ARRAY[num] AS path, 1 AS depth
      FROM sect
     WHERE num = 0
    UNION ALL
    SELECT c.courseid, c.sectionid, c.num, w.path || c.num, w.depth + 1
      FROM sect c
      JOIN walk w ON w.courseid = c.courseid AND w.num = c.parentnum
     WHERE c.num <> 0
       AND w.depth < 25
),
expected AS (
    SELECT courseid, sectionid, num, depth,
           ROW_NUMBER() OVER (PARTITION BY courseid ORDER BY path) - 1 AS shouldbe
      FROM walk
)
SELECT s.courseid                       AS "Course ID",
       fs.shortname                     AS "Short name",
       s.sectionid                      AS "Section ID",
       s.num                            AS "Current number",
       e.shouldbe                       AS "Should be",
       e.depth - 1                      AS "Depth",
       s.parentnum                      AS "Parent number",
       COALESCE(s.name, '(unnamed)')    AS "Section name"
  FROM sect s
  JOIN fs ON fs.courseid = s.courseid
  JOIN expected e ON e.sectionid = s.sectionid
 WHERE e.shouldbe <> s.num
 ORDER BY s.courseid, s.num
