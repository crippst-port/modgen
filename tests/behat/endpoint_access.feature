@aiplacement @aiplacement_modgen @javascript
Feature: Endpoints refuse crafted requests for features that are switched off or not permitted
  In order to rely on the admin switches and capabilities
  As an administrator
  I need each endpoint to enforce them, not just the menus that lead to it

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_ai                | 1 | aiplacement_modgen |
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
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure      | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |

  Scenario: Quick Add queues a job from a direct request while it is switched on
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    When I post "action=create_weeks&weekcount=2" to the modgen endpoint "ajax/create_sections.php" for "C1"
    Then the modgen endpoint response should contain "jobid"
    And there should be "1" structure jobs in "C1"

  Scenario: Quick Add refuses a direct request while it is switched off
    Given the following config values are set as admin:
      | enable_quickadd | 0 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    When I post "action=create_weeks&weekcount=2" to the modgen endpoint "ajax/create_sections.php" for "C1"
    Then the modgen endpoint response should contain "quickadddisabled"
    And there should be "0" structure jobs in "C1"

  Scenario: An approved structure is built from a direct request while a route is open
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I post "ajax=1&acceptpolicy=1" to the modgen endpoint "prompt.php" for "C1"
    When I post "ajax=1&_qf__aiplacement_modgen_approve_form=1&moduletype=connected_weekly&approvedjson={%22sections%22:[{%22title%22:%22Week 1%22}]}" to the modgen endpoint "prompt.php" for "C1"
    Then the modgen endpoint response should contain "jobid"
    And there should be "1" structure jobs in "C1"

  Scenario: An approved structure is refused while both generation routes are switched off
    Given the following config values are set as admin:
      | enable_ai                | 0 | aiplacement_modgen |
      | enable_structurefromfile | 0 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I post "ajax=1&acceptpolicy=1" to the modgen endpoint "prompt.php" for "C1"
    When I post "ajax=1&_qf__aiplacement_modgen_approve_form=1&moduletype=connected_weekly&approvedjson={%22sections%22:[{%22title%22:%22Week 1%22}]}" to the modgen endpoint "prompt.php" for "C1"
    Then the modgen endpoint response should contain "generationdisabled"
    And there should be "0" structure jobs in "C1"

  Scenario: Without the prompt capability, the prompt form is refused even though another route is open
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I post "ajax=1&acceptpolicy=1" to the modgen endpoint "prompt.php" for "C1"
    When I post "ajax=1&_qf__aiplacement_modgen_prompt_form=1&moduletype=connected_weekly&prompt=A module on rivers" to the modgen endpoint "prompt.php" for "C1"
    Then the modgen endpoint response should contain "nopermissions"
    And there should be "0" structure jobs in "C1"

  Scenario: A teacher whose enrolment is suspended cannot queue a build
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           | status |
      | teacher2 | C1     | editingteacher | 1      |
    And I log in as "teacher2"
    And I am on site homepage
    When I post "action=create_weeks&weekcount=2" to the modgen endpoint "ajax/create_sections.php" for "C1"
    Then the modgen endpoint response should contain "errorcode"
    And there should be "0" structure jobs in "C1"
