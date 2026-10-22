@aiplacement @aiplacement_modgen @javascript
Feature: The toolbar respects permissions and the master switch
  In order to keep structure tools to the people allowed to use them
  As a site administrator
  I need the toolbar to appear only for users with a Module Assistant permission

  Background:
    Given the following "courses" exist:
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

  Scenario: A teacher with a Module Assistant permission sees the toolbar
    Given the following config values are set as admin:
      | enabled         | 1 | aiplacement_modgen |
      | enable_quickadd | 1 | aiplacement_modgen |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Quick Add"

  Scenario: The toolbar does not appear while the placement is switched off
    Given the following config values are set as admin:
      | enabled | 0 | aiplacement_modgen |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should not see "Quick Add"

  Scenario: Check Structure disappears when its permission is withdrawn
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | aiplacement/modgen:checkstructure | Allow      | editingteacher | Course       | C1        |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Check Structure"
    And I log out
    And the following "permission overrides" exist:
      | capability                        | permission | role           | contextlevel | reference |
      | aiplacement/modgen:checkstructure | Prevent    | editingteacher | Course       | C1        |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I should see "Quick Add"
    And I should not see "Check Structure"

  Scenario: A teacher who may only generate from a template does not see the structure entries
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_quickadd          | 1 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure      | Prevent    | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    Then I should see "Structure from file"
    And I should not see "New Theme(s)"
    And I should not see "New Week(s)"
