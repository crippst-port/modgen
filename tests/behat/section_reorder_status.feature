@aiplacement @aiplacement_modgen @javascript
Feature: Automatic section renumbering and its status icon
  In order to keep Flexible sections courses numbered in hierarchy order
  As a site administrator
  I need sections renumbered automatically after edits, a status icon showing where that is, and a switch to turn it off

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
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
      | capability                        | permission | role           | contextlevel | reference |
      | aiplacement/modgen:checkstructure | Allow      | editingteacher | Course       | C1        |

  Scenario: Adding a section on the course page shows a queued renumber
    Given I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    When I click on "Add section" "link" in the "li#section-0" "css_element"
    Then the section reorder status should show "queued"
    And there should be "1" section renumbers queued for "C1"

  Scenario: A running renumber shows as running
    Given the automatic section renumber for "C1" is queued
    And the automatic section renumber for "C1" is running
    When I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    Then the section reorder status should show "running"

  Scenario: The icon follows the renumber from queued to done without a page reload
    Given the course "C1" has "2" themes with "1" weeks each
    And section "Late week" was added under "Theme 1" in "C1" unnumbered
    And I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    And the section reorder status should show "queued"
    When the automatic section renumber for "C1" runs
    Then the section reorder status should show "renumbered"
    And the course "C1" structure should be sound
    And there should be "0" section renumbers queued for "C1"

  Scenario: A run that finds the sections already in order still shows it finished
    Given the course "C1" has "2" themes with "1" weeks each
    And I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    And the section reorder status should show "queued"
    When the automatic section renumber for "C1" runs
    Then the section reorder status should show "checked"
    And there should be "0" section renumbers queued for "C1"

  Scenario: A renumber queued from elsewhere replaces a finished one without a page reload
    Given the course "C1" has "2" themes with "1" weeks each
    And the automatic section renumber for "C1" runs
    And I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    And the section reorder status should show "checked"
    When the automatic section renumber for "C1" is queued
    Then the section reorder status should show "queued"

  # The refusal itself is covered by reorder_sections_task_test. A course whose hierarchy is broken
  # enough to refuse also stops the Flexible sections course page loading, so it is logged here instead.
  Scenario: A refused renumber points at Check Structure
    Given the course "C1" has "2" themes with "1" weeks each
    And the section renumber queue for "C1" is empty
    And the automatic section renumber for "C1" was refused
    When I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    Then the section reorder status should show "blocked"
    And I click on "[data-region='aiplacement-modgen-reorder-status'] a" "css_element"
    And I should see "Course Structure Diagnostics"

  Scenario: An administrator who switches to a teacher role no longer sees the status icon
    Given the automatic section renumber for "C1" is queued
    And I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    And the section reorder status should show "queued"
    When I follow "Switch role to..." in the user menu
    And I press "Teacher"
    And I turn editing mode on
    Then I should see "Check Structure"
    And "[data-region='aiplacement-modgen-reorder-status']" "css_element" should not exist

  Scenario: A teacher asking the status endpoint directly is refused
    Given the automatic section renumber for "C1" is queued
    And I am on the "Course 1" "course" page logged in as "teacher1"
    When I post "" to the modgen endpoint "ajax/reorder_status.php" for "C1"
    Then the modgen endpoint response should contain "you do not currently have permissions"
    And the modgen endpoint response should not contain "state"
  Scenario: The Check Structure page shows the last renumber to an administrator
    Given the course "C1" has "2" themes with "1" weeks each
    And the automatic section renumber for "C1" runs
    And I log in as "admin"
    When I am on the Check Structure page of "C1"
    Then I should see "Automatic section renumbering"
    And I should see "the sections were already in order, so nothing changed"

  Scenario: The Check Structure page says when no renumber has run
    Given the section renumber queue for "C1" is empty
    And I log in as "admin"
    When I am on the Check Structure page of "C1"
    Then I should see "No automatic renumber has run on this course yet."

  Scenario: Teachers do not see the last renumber on the Check Structure page
    Given the course "C1" has "2" themes with "1" weeks each
    And the automatic section renumber for "C1" runs
    And I log in as "teacher1"
    When I am on the Check Structure page of "C1"
    Then I should see "Course Structure Diagnostics"
    And I should not see "Automatic section renumbering"

  Scenario: Teachers do not see the status icon
    Given the automatic section renumber for "C1" is queued
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Check Structure"
    And "[data-region='aiplacement-modgen-reorder-status']" "css_element" should not exist

  Scenario: Switching the renumber off stops it being queued and hides the icon
    Given the following config values are set as admin:
      | enable_autoreorder | 0 | aiplacement_modgen |
    And the section renumber queue for "C1" is empty
    And I am on the "Course 1" "course" page logged in as "admin"
    And I turn editing mode on
    When I click on "Add section" "link" in the "li#section-0" "css_element"
    And I wait until the page is ready
    Then there should be "0" section renumbers queued for "C1"
    And "[data-region='aiplacement-modgen-reorder-status']" "css_element" should not exist

  Scenario: The switch is on by default and can be turned off from the settings page
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=aiplacement_modgen"
    Then the field "Renumber sections automatically" matches value "1"
    And I set the field "Renumber sections automatically" to "0"
    And I press "Save changes"
    And the field "Renumber sections automatically" matches value "0"
