@aiplacement @aiplacement_modgen @javascript
Feature: Choosing whether a build adds an Assessments section
  In order to keep one assessments section in my module
  As a teacher
  I need the build forms to offer an Assessments section only when the course has none

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_quickadd          | 1 | aiplacement_modgen |
      | enable_ai                | 0 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
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
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure      | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |

  Scenario: Quick Add offers the section, ticked, when the course has none
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    Then the field "Add an Assessments section" matches value "1"

  Scenario: Quick Add names the existing section instead of offering another
    Given the course "C1" has a top-level section called "Assessment Information"
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    Then I should see "Assessment Information"
    And I should see "so a new one will not be added"
    And "Add an Assessments section" "field" should not exist

  @_file_upload
  Scenario: A file built with the section unticked leaves it out
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/config_lf.csv" file to "Upload structure file" filemanager
    And I set the field "Add an Assessments section" to "0"
    And I press "Preview Structure"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name         | parent       | depth |
      | Café studies | -            | 1     |
      | Intro        | Café studies | 2     |
      | Pre-session  | Intro        | 3     |
      | Session      | Intro        | 3     |
      | Post-session | Intro        | 3     |
      | Practice     | Café studies | 2     |
      | Pre-session  | Practice     | 3     |
      | Session      | Practice     | 3     |
      | Post-session | Practice     | 3     |
    And the course "C1" structure should be sound
