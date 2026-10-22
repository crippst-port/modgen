@aiplacement @aiplacement_modgen @javascript
Feature: Reviewing the AI-generated activities
  In order to check what the assistant created
  As a teacher who can generate structure
  I need a list of the unedited generated activities that I can open and hide

  Background:
    Given the following config values are set as admin:
      | enabled         | 1 | aiplacement_modgen |
      | enable_quickadd | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       |
      | Course 1 | C1        | flexsections |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name           | intro | course | idnumber |
      | page     | Generated page | Hello | C1     | gen1     |
    And the following "aiplacement_modgen > markers" exist:
      | activity |
      | gen1     |

  Scenario: A teacher who can generate sees and uses the list
    Given the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "View 1 unedited suggestions" "link"
    Then I should see "Generated page"
    And I should see "Visible" in the ".visibility-status" "css_element"
    And I should not see "Hidden" in the ".visibility-status" "css_element"
    And I click on "Hide from students" "button" in the "Generated page" "table_row"
    And I should see "Hidden" in the "Generated page" "table_row"
    And I click on "Show to students" "button" in the "Generated page" "table_row"
    And I should see "Visible" in the ".visibility-status" "css_element"
    And I should not see "Hidden" in the ".visibility-status" "css_element"

  Scenario: A teacher without a generate permission is not offered the list
    Given the following "permission overrides" exist:
      | capability                         | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure | Allow      | editingteacher | Course       | C1        |
    When I am on the "Course 1" "course" page logged in as "teacher2"
    And I turn editing mode on
    Then I should see "Module Assistant"
    And "//a[contains(@href, 'aigen_list.php')]" "xpath_element" should not exist
