@aiplacement @aiplacement_modgen
Feature: The administrator's structure tools
  In order to diagnose and repair courses across the site
  As an administrator
  I need tools to check a course by its id, repair it, analyse it and export its hierarchy

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       | numsections |
      | Course 1 | C1        | flexsections | 0           |
    And the course "C1" has "2" themes with "1" weeks each
    And I log in as "admin"
    And I visit "/ai/placement/modgen/admin_tools.php"

  Scenario: The page offers the integrity checker, the export and the statistics
    Then I should see "Database Integrity"
    And I should see "Integrity Checker"
    And I should see "Check Integrity"
    And I should see "Repair Invalid Parents"
    And I should see "Delete Hidden Empty Sections"
    And I should see "Analyze Hierarchy"
    And I should see "Quick Hierarchy Export"
    And I should see "Download as JSON (Machine Readable)"
    And I should see "Download as HTML (Human Readable)"
    And I should see "Download as Text (Plain Text)"
    And I should see "Plugin Usage Statistics"
    And I should see "Courses using flexsections"

  Scenario: A healthy course passes the integrity check
    When I set the field "Course ID" to the id of course "C1"
    And I press "Check Integrity"
    Then I should see "Integrity Check Results"
    And I should see "No integrity issues found."
    And I should not see "Fix"

  Scenario: A broken parent is reported and repaired
    Given section "Theme 1 - Week 1" of "C1" has parent "99"
    When I set the field "Course ID" to the id of course "C1"
    And I press "Check Integrity"
    Then I should see "Invalid parent references"
    And I click on "Repair Invalid Parents" "link"
    And I should see "Fix integrity issues in this course?"
    And I click on "Continue" "button"
    Then I should see "Structure data repaired. 1 item(s) fixed."
    And I should see "Reset 1 invalid parent references to top-level"
    And I should see "No integrity issues found."
    And the course "C1" structure should be sound
    And the course "C1" structure should be:
      | name             | parent           |
      | Theme 1          | -                |
      | Theme 1 - Week 1 | -                |
      | Pre-session      | Theme 1 - Week 1 |
      | Session          | Theme 1 - Week 1 |
      | Post-session     | Theme 1 - Week 1 |
      | Theme 2          | -                |
      | Theme 2 - Week 1 | Theme 2          |
      | Pre-session      | Theme 2 - Week 1 |
      | Session          | Theme 2 - Week 1 |
      | Post-session     | Theme 2 - Week 1 |

  Scenario: Leftover options for a deleted section are reported and removed
    Given leftover format options exist in "C1" for a section that no longer exists
    When I set the field "Course ID" to the id of course "C1"
    And I press "Check Integrity"
    Then I should see "Orphaned format options"
    And I click on "Repair Invalid Parents" "link"
    And I click on "Continue" "button"
    And I should see "Structure data repaired."
    And I should see "No integrity issues found."

  Scenario: A hidden empty section is reported and can be deleted
    Given section "Pre-session" of "C1" is hidden in the database
    When I set the field "Course ID" to the id of course "C1"
    And I press "Check Integrity"
    Then I should see "Orphaned empty sections"
    And I click on "Delete Hidden Empty Sections" "link"
    And I click on "Continue" "button"
    And I should see "1 orphaned section(s) deleted."
    And the course "C1" structure should be sound

  Scenario: The hierarchy can be analysed
    When I set the field "Course ID" to the id of course "C1"
    And I press "Analyze Hierarchy"
    Then I should see "Hierarchy Analysis"
    And I should see "Analyzing hierarchy for course: Course 1"
    And I should see "Section Hierarchy Tree"
    And I should see "Section 2: Theme 1 - Week 1"

  Scenario Outline: The hierarchy can be exported in each format
    When I set the field with xpath "(//input[@name='courseid'])[2]" to the id of course "C1"
    And I press "<button>"
    Then the response body should contain "<content>"

    Examples:
      | button                               | content          |
      | Download as JSON (Machine Readable)  | Theme 1 - Week 1 |
      | Download as HTML (Human Readable)    | Theme 1 - Week 1 |
      | Download as Text (Plain Text)        | Theme 1 - Week 1 |
