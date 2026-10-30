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
    Then "nav[aria-label='Module Assistant']" "css_element" should exist
    And I should see "Check Structure"
    And I click on "Quick Add" "link"
    And I should see "New Theme(s)"
    And I should see "New Week(s)"
    And I should see "Structure from file"

  Scenario: There is no Suggest entry
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then "nav[aria-label='Module Assistant']" "css_element" should exist
    And I should not see "Activities from section"
    And I should not see "Suggest"

  Scenario: Help links configured by the administrator appear in the toolbar
    Given the following config values are set as admin:
      | helplink1_text | Building for the Connected Curriculum | aiplacement_modgen |
      | helplink1_url  | https://example.com/connected         | aiplacement_modgen |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Help and Advice" "link"
    Then I should see "Building for the Connected Curriculum"

  Scenario: There is no Help and Advice menu until a help link is configured
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then "nav[aria-label='Module Assistant']" "css_element" should exist
    And I should not see "Help and Advice"

  Scenario: A finished job's error is shown as text, not markup
    Given the following "aiplacement_modgen > jobs" exist:
      | course | user     | action       | status | result                                                                       |
      | C1     | teacher1 | create_weeks | failed | {"success":false,"error":"Could not build <b>Week 2</b>","will_retry":false} |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Job failed: Could not build <b>Week 2</b>"

  Scenario: On a narrow screen the toolbar opens from its own toggle button
    Given I change viewport size to "mobile"
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I should not see "Check Structure"
    # The site menu button belongs to the theme and leaves the toolbar alone.
    When I click on "button[data-toggler='drawers'][data-target='theme_boost-drawers-primary']" "css_element"
    Then I should not see "Check Structure"
    And I click on "#theme_boost-drawers-primary [data-toggler='drawers'][data-action='closedrawer']" "css_element"
    When I click on ".aiplacement-modgen-navbar .navbar-toggler" "css_element"
    Then I should see "Check Structure"
    When I click on ".aiplacement-modgen-navbar .navbar-toggler" "css_element"
    Then I should not see "Check Structure"
