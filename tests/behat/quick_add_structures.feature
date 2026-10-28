@aiplacement @aiplacement_modgen @javascript
Feature: Quick Add builds the expected course structure
  In order to scaffold a module quickly
  As a teacher with structure permissions
  I need Quick Add to create themes, weeks, or both, and I need the stored structure to be what I asked for

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_quickadd          | 1 | aiplacement_modgen |
      | enable_ai                | 0 | aiplacement_modgen |
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
      | capability                         | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure | Allow      | editingteacher | Course       | C1        |

  Scenario: One theme with two weeks
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "2"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "Creating 1 theme(s) with 2 week(s) each"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name             | parent           | collapsed | activities | depth |
      | Theme 1          | -                | 1         | 0          | 1     |
      | Theme 1 - Week 1 | Theme 1          | 1         | 0          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 0         | 0          | 3     |
      | Session          | Theme 1 - Week 1 | 0         | 0          | 3     |
      | Post-session     | Theme 1 - Week 1 | 0         | 0          | 3     |
      | Theme 1 - Week 2 | Theme 1          | 1         | 0          | 2     |
      | Pre-session      | Theme 1 - Week 2 | 0         | 0          | 3     |
      | Session          | Theme 1 - Week 2 | 0         | 0          | 3     |
      | Post-session     | Theme 1 - Week 2 | 0         | 0          | 3     |
    And the course "C1" structure should be sound

  Scenario: Several themes, each with the weeks asked for
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "3"
    And I set the field "How many weeks per theme?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    Then the course "C1" should have "15" sections
    And the course "C1" structure should be sound

  Scenario: The summary activities option puts an activity in every week and session
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "1"
    And I set the field "Add a section summary activity to each section" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name             | parent           | activities | activitynames       |
      | Theme 1          | -                | 0          |                     |
      | Theme 1 - Week 1 | Theme 1          | 1          | Theme 1 - Week 1    |
      | Pre-session      | Theme 1 - Week 1 | 1          | Pre-session activity  |
      | Session          | Theme 1 - Week 1 | 1          | Session activity      |
      | Post-session     | Theme 1 - Week 1 | 1          | Post-session activity |
    And the course "C1" structure should be sound

  Scenario: Standalone weeks sit at the top level
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "2"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "Creating 2 standalone week(s)"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent | collapsed | depth |
      | Week 1       | -      | 1         | 1     |
      | Pre-session  | Week 1 | 0         | 2     |
      | Session      | Week 1 | 0         | 2     |
      | Post-session | Week 1 | 0         | 2     |
      | Week 2       | -      | 1         | 1     |
      | Pre-session  | Week 2 | 0         | 2     |
      | Session      | Week 2 | 0         | 2     |
      | Post-session | Week 2 | 0         | 2     |
    And the course "C1" structure should be sound

  Scenario: Themes and then weeks in the same course
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    And I am on the "Course 1" "course" page
    And I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name             | parent           | depth |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | Theme 1          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
      | Week 1           | -                | 1     |
      | Pre-session      | Week 1           | 2     |
      | Session          | Week 1           | 2     |
      | Post-session     | Week 1           | 2     |
    And the course "C1" structure should be sound

  Scenario: Weeks and then themes in the same course
    Given the course "C1" has "1" standalone weeks
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name             | parent           | depth |
      | Week 1           | -                | 1     |
      | Pre-session      | Week 1           | 2     |
      | Session          | Week 1           | 2     |
      | Post-session     | Week 1           | 2     |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | Theme 1          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
    And the course "C1" structure should be sound

  Scenario: Weeks added from inside a theme become that theme's children
    Given the course "C1" has "2" themes with "1" weeks each
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Theme 1" "link" in the "region-main" "region"
    And I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name             | parent           | depth |
      | Theme 1          | -                | 1     |
      | Theme 1 - Week 1 | Theme 1          | 2     |
      | Pre-session      | Theme 1 - Week 1 | 3     |
      | Session          | Theme 1 - Week 1 | 3     |
      | Post-session     | Theme 1 - Week 1 | 3     |
      | Week 1           | Theme 1          | 2     |
      | Pre-session      | Week 1           | 3     |
      | Session          | Week 1           | 3     |
      | Post-session     | Week 1           | 3     |
      | Theme 2          | -                | 1     |
      | Theme 2 - Week 1 | Theme 2          | 2     |
      | Pre-session      | Theme 2 - Week 1 | 3     |
      | Session          | Theme 2 - Week 1 | 3     |
      | Post-session     | Theme 2 - Week 1 | 3     |
    And the course "C1" structure should be sound

  Scenario: The Assessments section is added only from its own Quick Add entry, and only once
    Given the course "C1" has "1" standalone weeks
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Assessments section" "link"
    And I click on "Add section" "button" in the ".modal-footer" "css_element"
    Then I should see "section has been added"
    And the course "C1" structure should be:
      | name         | parent | depth |
      | Assessments  | -      | 1     |
      | Week 1       | -      | 1     |
      | Pre-session  | Week 1 | 2     |
      | Session      | Week 1 | 2     |
      | Post-session | Week 1 | 2     |
    And the course "C1" structure should be sound
    And I am on the "Course 1" "course" page
    And I click on "Quick Add" "link"
    And I click on "Assessments section" "link"
    And I click on "Add section" "button" in the ".modal-footer" "css_element"
    And I should see "already has a section called"

  Scenario: The Assessments section is only offered at the top of the module
    Given the course "C1" has "1" themes with "1" weeks each
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Theme 1" "link" in the "region-main" "region"
    And I click on "Quick Add" "link"
    Then I should see "New Week(s)"
    And I should not see "Assessments section"
