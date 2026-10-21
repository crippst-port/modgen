@aiplacement @aiplacement_modgen @javascript
Feature: The structure generator offers only the current options
  In order to generate a course structure
  As a teacher with generator permissions
  I need a form without the options that were removed

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
      | enable_ai                | 1 | aiplacement_modgen |
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
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatewithprompt   | Allow      | editingteacher | Course       | C1        |

  Scenario: Structure from file has no existing-module, example-content or hide options
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Structure from file" "link"
    And I wait until the page is ready
    And I click on "acceptpolicy" "checkbox"
    And I press "Accept"
    Then I should see "Upload structure file"
    And I should see "Add a section summary activity to each section"
    And I should not see "Base on existing module"
    And I should not see "Expand on themes"
    And I should not see "Generate example content"
    And I should not see "Hide existing sections"
    And I should not see "Suggest Content"

  Scenario: Structure from prompt has no existing-module, example-content or hide options
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Structure from prompt" "link"
    Then I should see "Additional context or requests for the Assistant"
    And I should see "Module format"
    And I should not see "Base on existing module"
    And I should not see "Expand on themes"
    And I should not see "Generate example content"
    And I should not see "Hide existing sections"
    And I should not see "Suggest Content"
