-- Raw section rows for one flexsections course: the number each section holds and the
-- parent value stored against it. The starting point when a course is behaving oddly and
-- you want to see the state the format is actually working from.
--
-- The WHERE clause below carries a report_customsql parameter named courseid, so the
-- report asks for a course id when it runs. Note that its name cannot be written out
-- here in full, colon included, without Moodle's DML counting the mention itself as a
-- second parameter and refusing the query.
--
-- The parent value is shown wrapped in square brackets with its length beside it, because
-- the failure this query exists to catch is invisible otherwise: a value of "5 " or " 5"
-- is read as section 5 by format_flexsections and by the plugin's integrity checker, but
-- a stricter reader sees no integer at all and treats the section as top level. A Length
-- longer than the digits shown means there is whitespace in the value.
--
-- Same house rules as the other files here: no question mark, no semicolon and no
-- data-modifying SQL keyword anywhere in the text, comments included.
SELECT cs.section                            AS "Number",
       cs.id                                 AS "Section ID",
       '[' || COALESCE(cfo.value, '') || ']' AS "Stored parent",
       COALESCE(length(cfo.value), 0)        AS "Length",
       COALESCE((substring(cfo.value from '^\s*([+-]{0,1}[0-9]{1,})'))::int, 0)
                                             AS "Parent as read",
       COALESCE(cs.name, 'unnamed')          AS "Name"
  FROM prefix_course_sections cs
  LEFT JOIN prefix_course_format_options cfo
         ON cfo.courseid  = cs.course
        AND cfo.format    = 'flexsections'
        AND cfo.sectionid = cs.id
        AND cfo.name      = 'parent'
 WHERE cs.course = :courseid
 ORDER BY cs.section
