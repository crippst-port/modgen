@aiplacement @aiplacement_modgen @javascript
Feature: New themes can only be added from the top level of the module
  In order to keep themes as the top-level containers of a module
  As a teacher with structure permissions
  I need Quick Add to offer New Theme only at the top of the course, not from inside a section

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_quickadd          | 1 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       |
      | Course 1 | C1        | flexsections |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                         | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure | Allow      | editingteacher | Course       | C1        |

  Scenario: At the top of the course Quick Add offers both New Theme and New Week
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    Then I should see "New Theme(s)"
    And I should see "New Week(s)"

  Scenario: A theme queued from the top level is created once the background task has run
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "Creating 1 theme(s) with 1 week(s) each"
    And I run all adhoc tasks
    And I am on the "Course 1" "course" page
    And I should see "Theme 1" in the "region-main" "region"

  Scenario: Inside a section Quick Add offers New Week but not New Theme
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "1"
    And I set the field "How many weeks per theme?" to "1"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I run all adhoc tasks
    When I am on the "Course 1" "course" page
    And I click on "Theme 1" "link" in the "region-main" "region"
    And I click on "Quick Add" "link"
    Then I should see "New Week(s)"
    And I should not see "New Theme(s)"
