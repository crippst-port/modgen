-- Sections whose stored flexsections parent value is not a plain run of digits.
--
-- Anything here reads correctly through format_flexsections and the plugin's integrity
-- checker, both of which parse the value the way PHP casts a string to an integer:
-- leading whitespace and a sign are tolerated. A stricter reader is not so forgiving,
-- and will place such a section at top level instead of under its real parent, which
-- silently changes the shape of the tree it derives everything else from.
--
-- Runs across every flexsections course. An empty result means the ordering report can be
-- taken at face value. Rows here mean the parent values want tidying to bare digits.
--
-- Same house rules as the other files here: no question mark, no semicolon and no
-- data-modifying SQL keyword anywhere in the text, comments included.
SELECT cs.course                             AS "Course ID",
       c.shortname                           AS "Short name",
       cs.section                            AS "Number",
       cs.id                                 AS "Section ID",
       '[' || cfo.value || ']'               AS "Stored parent",
       length(cfo.value)                     AS "Length",
       COALESCE((substring(cfo.value from '^\s*([+-]{0,1}[0-9]{1,})'))::int, 0)
                                             AS "Parent as read",
       COALESCE(cs.name, 'unnamed')          AS "Name"
  FROM prefix_course_sections cs
  JOIN prefix_course c
       ON c.id = cs.course AND c.format = 'flexsections'
  JOIN prefix_course_format_options cfo
       ON cfo.courseid  = cs.course
      AND cfo.format    = 'flexsections'
      AND cfo.sectionid = cs.id
      AND cfo.name      = 'parent'
 WHERE cfo.value IS NOT NULL
   AND cfo.value <> ''
   AND cfo.value !~ '^[0-9]{1,}$'
 ORDER BY cs.course, cs.section
