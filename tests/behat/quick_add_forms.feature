@aiplacement @aiplacement_modgen @javascript
Feature: The Quick Add forms
  In order to add themes and weeks without mistakes
  As a teacher with structure permissions
  I need forms that show sensible defaults and limits, refuse impossible requests, and can be cancelled

  Background:
    Given the following config values are set as admin:
      | enabled         | 1 | aiplacement_modgen |
      | enable_quickadd | 1 | aiplacement_modgen |
      | enable_ai       | 0 | aiplacement_modgen |
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

  Scenario: The theme form starts with one theme, one week and no summary activities
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    Then the field "How many themes do you want to create?" matches value "1"
    And the field "How many weeks per theme?" matches value "1"
    And the field "Add a section summary activity to each section" matches value ""
    And I should see "Enter a number between 1 and 30"
    And I should see "Enter a number between 1 and 5"

  Scenario: The week form starts with one week and shows the limit
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    Then the field "How many weeks do you want to create?" matches value "1"
    And the field "Add a section summary activity to each section" matches value ""
    And I should see "Enter a number between 1 and 30"

  Scenario: The limits shown follow the administrator's settings
    Given the following config values are set as admin:
      | maxquicksections | 4 | aiplacement_modgen |
      | maxweeksperTheme | 2 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    Then I should see "Enter a number between 1 and 4"
    And I should see "Enter a number between 1 and 2"

  Scenario Outline: A request outside the limits is refused and nothing is queued
    Given the following config values are set as admin:
      | maxquicksections | 4 | aiplacement_modgen |
      | maxweeksperTheme | 2 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "<themes>"
    And I set the field "How many weeks per theme?" to "<weeks>"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "<message>"
    And there should be "0" structure jobs in "C1"
    And the course "C1" should have "0" sections

    Examples:
      | themes | weeks | message                           |
      | 0      | 1     | Please enter a number between 1 and 4 |
      | 5      | 1     | Please enter a number between 1 and 4 |
      | 1      | 0     | Please enter a number between 1 and 2 |
      | 1      | 3     | Please enter a number between 1 and 2 |

  Scenario Outline: A weeks request outside the limit is refused and nothing is queued
    Given the following config values are set as admin:
      | maxquicksections | 4 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "<weeks>"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    Then I should see "Please enter a number between 1 and 4"
    And there should be "0" structure jobs in "C1"

    Examples:
      | weeks |
      | 0     |
      | 5     |
      | 99    |

  Scenario: Cancelling a form queues nothing
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I set the field "How many themes do you want to create?" to "3"
    And I click on "Cancel" "button" in the ".modal-footer" "css_element"
    Then I should not see "How many themes do you want to create?"
    And there should be "0" structure jobs in "C1"
    And the course "C1" should have "0" sections

  Scenario: The help icons explain the fields
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    And I click on "Help with How many themes do you want to create?" "icon"
    Then I should see "Enter the number of themes to create. The maximum is set by your site administrator."

  Scenario: The steppers change the counts within their limits, and the summary follows
    Given the following config values are set as admin:
      | maxweeksperTheme | 2 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Theme(s)" "link"
    Then I should see "This will add 1 theme(s) with 1 week(s) between them: 5 sections in all."
    And the "Decrease How many themes do you want to create?" "button" should be disabled
    And I click on "Increase How many themes do you want to create?" "button"
    And I click on "Increase How many weeks per theme?" "button"
    And the field "How many themes do you want to create?" matches value "2"
    And the field "How many weeks per theme?" matches value "2"
    And the "Increase How many weeks per theme?" "button" should be disabled
    And I should see "This will add 2 theme(s) with 4 week(s) between them: 18 sections in all."
    And I set the field "How many themes do you want to create?" to "3"
    And I should see "This will add 3 theme(s) with 6 week(s) between them: 27 sections in all."
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I should see "Creating 3 theme(s) with 2 week(s) each"
