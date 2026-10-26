@aiplacement @aiplacement_modgen
Feature: Checking and repairing a course's structure
  In order to keep the section hierarchy sound
  As a teacher with the check structure permission
  I need to see what is wrong with a course's sections and repair it, without losing anything else

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       | numsections |
      | Course 1 | C1        | flexsections | 0           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | aiplacement/modgen:checkstructure | Allow      | editingteacher | Course       | C1        |
  @javascript
  Scenario: A healthy course has nothing to repair
    Given the course "C1" has "2" themes with "2" weeks each
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Check Structure" "link"
    Then I should see "Course Structure Diagnostics"
    And I should see "No structural issues detected."
    And I should not see "Repair Structure Data"
    And I should not see "Renumber Sections"
    And the course "C1" structure should be sound

  Scenario: A section with a parent that does not exist is found, shown and repaired
    Given the course "C1" has "2" themes with "1" weeks each
    And section "Theme 1 - Week 1" of "C1" has parent "99"
    And I log in as "teacher1"
    When I am on the Check Structure page of "C1"
    Then I should see "1 issue type(s) detected."
    And I should see "Invalid parent references"
    And I should see "Repair Structure Data"
    And I should see "Theme 1 - Week 1" in the "#checkstructure-sectiontable" "css_element"
    And I click on "Repair Structure Data" "link"
    And I should see "Repair structure data for this course?"
    And I click on "Continue" "button"
    Then I should see "Sections moved to top level"
    And I should see "Theme 1 - Week 1" in the ".table-warning" "css_element"
    And the course "C1" structure should be sound
    And the course "C1" structure should be:
      | name             | parent           | depth |
      | Assessments      | -                | 1     |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | -                | 1     |
      | Pre-session      | Theme 1 - Week 1 | 2     |
      | Session          | Theme 1 - Week 1 | 2     |
      | Post-session     | Theme 1 - Week 1 | 2     |
      | Theme 2          | -                | 1     |
      | Theme 2 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 2 - Week 1 | 3     |
      | Session          | Theme 2 - Week 1 | 3     |
      | Post-session     | Theme 2 - Week 1 | 3     |
    And I click on "Apply" "button" in the "Theme 1 - Week 1" "table_row"
    And the course "C1" structure should be:
      | name             | parent           | depth |
      | Assessments      | -                | 1     |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | Theme 1          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
      | Theme 2          | -                | 1     |
      | Theme 2 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 2 - Week 1 | 3     |
      | Session          | Theme 2 - Week 1 | 3     |
      | Post-session     | Theme 2 - Week 1 | 3     |
    And the course "C1" structure should be sound
    And I click on "Run Check" "link"
    And I should see "No structural issues detected."

  Scenario: A section added without renumbering is found and the sections renumbered
    Given the course "C1" has "2" themes with "1" weeks each
    And section "Late child" was added under "Theme 1" in "C1" unnumbered
    And I log in as "teacher1"
    When I am on the Check Structure page of "C1"
    Then I should see "issue type(s) detected."
    And I should see "Renumber Sections"
    And I click on "Renumber Sections" "link"
    And I click on "Continue" "button"
    And the course "C1" structure should be sound
    And the course "C1" structure should be:
      | name             | parent           | depth |
      | Assessments      | -                | 1     |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | Theme 1          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
      | Late child       | Theme 1          | 2     |
      | Theme 2          | -                | 1     |
      | Theme 2 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 2 - Week 1 | 3     |
      | Session          | Theme 2 - Week 1 | 3     |
      | Post-session     | Theme 2 - Week 1 | 3     |

  Scenario: Leftover options for a deleted section are found and removed
    Given the course "C1" has "1" themes with "1" weeks each
    And leftover format options exist in "C1" for a section that no longer exists
    And I log in as "teacher1"
    When I am on the Check Structure page of "C1"
    Then I should see "Orphaned format options"
    And I click on "Repair Structure Data" "link"
    And I click on "Continue" "button"
    And I should see "Structure data repaired."
    And I click on "Run Check" "link"
    And I should see "No structural issues detected."
    And the course "C1" structure should be sound

  Scenario: Sections that point at each other are found and the loop broken
    Given the course "C1" has "1" themes with "1" weeks each
    And section "Theme 1" of "C1" has parent "3"
    And section "Theme 1 - Week 1" of "C1" has parent "2"
    And I log in as "teacher1"
    When I am on the Check Structure page of "C1"
    Then I should see "Circular"
    And I click on "Attempt Circular Reference Auto Fix" "link"
    And I click on "Continue" "button"
    Then I should see "circular reference(s) fixed."
    And I should see "Theme 1" in the ".table-warning" "css_element"
    And I should see "Theme 1 - Week 1" in the ".table-warning" "css_element"
    And the course "C1" structure should be sound
    And the course "C1" structure should be:
      | name             | parent           | depth |
      | Assessments      | -                | 1     |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | -                | 1     |
      | Pre-session      | Theme 1 - Week 1 | 2     |
      | Session          | Theme 1 - Week 1 | 2     |
      | Post-session     | Theme 1 - Week 1 | 2     |

  Scenario: A section can be given a new parent from the details table
    Given the course "C1" has "2" themes with "1" weeks each
    And I log in as "teacher1"
    And I am on the Check Structure page of "C1"
    When I set the field with xpath "//select[@aria-label='New parent for section 3']" to "7: Theme 2"
    And I click on "//tr[td[2][@title='Theme 1 - Week 1']]//button" "xpath_element"
    Then I should see "Section 3 reparented."
    # Reparenting does not renumber; the page then offers to.
    And I should see "Renumber Sections"
    And I click on "Renumber Sections" "link"
    And I click on "Continue" "button"
    And the course "C1" structure should be:
      | name             | parent           | depth |
      | Assessments      | -                | 1     |
      | Theme 1          | -                | 1     |
      | Theme 2          | -                | 1     |
      | Theme 1 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
      | Theme 2 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 2 - Week 1 | 3     |
      | Session          | Theme 2 - Week 1 | 3     |
      | Post-session     | Theme 2 - Week 1 | 3     |
    And the course "C1" structure should be sound

  @javascript
  Scenario: Clean sections stay hidden until asked for
    Given the course "C1" has "1" themes with "1" weeks each
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Check Structure" "link"
    Then I should see "No sections have issues."
    And I click on "Show all sections (including clean ones)" "checkbox"
    And I should see "Theme 1 - Week 1" in the "#checkstructure-sectiontable" "css_element"
    And I should not see "No sections have issues."

  Scenario: A course in another format has nothing to check
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 2 | C2        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | aiplacement/modgen:checkstructure | Allow      | editingteacher | Course       | C2        |
    And I log in as "teacher1"
    When I am on the Check Structure page of "C2"
    Then I should see "Check Structure works on courses in the Flexible sections format"
    And I should not see "No structural issues detected."
