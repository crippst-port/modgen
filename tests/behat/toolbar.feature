@aiplacement @aiplacement_modgen @javascript
Feature: The Module Assistant toolbar offers only the current features
  In order to build course structure
  As a teacher with Module Assistant permissions
  I need a toolbar that shows the features that exist and nothing for those that were removed

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
      | capability                                | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure        | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatefromtemplate   | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:checkstructure         | Allow      | editingteacher | Course       | C1        |

  Scenario: The toolbar shows the Quick Add entries and Check Structure
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Module Assistant"
    And I should see "Check Structure"
    And I click on "Quick Add" "link"
    And I should see "New Theme(s)"
    And I should see "New Week(s)"
    And I should see "Structure from file"

  Scenario: There is no Suggest entry
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Module Assistant"
    And I should not see "Activities from section"
    And I should not see "Suggest"
