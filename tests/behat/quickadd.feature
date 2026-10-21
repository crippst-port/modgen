@aiplacement @aiplacement_modgen @javascript
Feature: Quick Add builds sections in the background
  In order to scaffold a course quickly
  As a teacher with structure permissions
  I need Quick Add to queue a job that creates the requested sections

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

  Scenario: Queuing new weeks creates them once the background task has run
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "2"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "Creating 2 standalone week(s)"
    And I run all adhoc tasks
    And I am on the "Course 1" "course" page
    And I should see "Week 1" in the "region-main" "region"
    And I should see "Week 2" in the "region-main" "region"

  Scenario: Quick Add can be switched off by the administrator
    Given the following config values are set as admin:
      | enable_quickadd | 0 | aiplacement_modgen |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    Then I should not see "New Theme(s)"
    And I should not see "New Week(s)"

  Scenario: A Quick Add that cannot fit is refused instead of queued
    Given the following config values are set as admin:
      | maxtotalsections | 10 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "5"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should not see "Creating 5 standalone week(s)"
    And I should see "That's too many sections to create at once"
