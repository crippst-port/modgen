@aiplacement @aiplacement_modgen @javascript
Feature: Downloading a library structure's file
  In order to see what a library structure contains before building from it
  As a teacher or an administrator
  I need to download its CSV file, and nobody else may

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_ai                | 0 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       | numsections |
      | Course 1 | C1        | flexsections | 0           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |
    And the following "aiplacement_modgen > templates" exist:
      | name            | filename    | content                                         |
      | Standard module | twelve.csv  | Theme 1,Library theme\nWeek 1,Library week\n |

  Scenario: An administrator downloads a structure from the library
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    When I request the file of the structure template "Standard module"
    Then the modgen endpoint response should contain "Theme 1,Library theme"
    And the modgen endpoint response should contain "Week 1,Library week"
    And the modgen endpoint response should contain "attachment"
    And the modgen endpoint response should contain "Standard module.csv"

  Scenario: A teacher who may build from a file downloads a structure in their course
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I request the file of the structure template "Standard module" for "C1"
    Then the modgen endpoint response should contain "Theme 1,Library theme"

  Scenario: A teacher without the permission to build from a file cannot download it
    Given the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:generatefromtemplate | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "C1" "Course" page logged in as "teacher2"
    When I request the file of the structure template "Standard module" for "C1"
    Then the modgen endpoint response should contain "do not currently have permissions to do that"
    And the modgen endpoint response should not contain "Library theme"

  Scenario: A teacher cannot download from the library without a course, which needs the library permission
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I request the file of the structure template "Standard module"
    Then the modgen endpoint response should contain "do not currently have permissions to do that"
    And the modgen endpoint response should not contain "Library theme"

  Scenario: Teachers cannot download while Structure from file is switched off, but the library still can
    Given the following config values are set as admin:
      | enable_structurefromfile | 0 | aiplacement_modgen |
    And I am on the "C1" "Course" page logged in as "teacher1"
    When I request the file of the structure template "Standard module" for "C1"
    Then the modgen endpoint response should contain "Building a structure from a file is currently disabled"
    And the modgen endpoint response should not contain "Library theme"
    And I log out
    And I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I request the file of the structure template "Standard module"
    And the modgen endpoint response should contain "Library theme"

  Scenario: Nothing can be downloaded while the placement is switched off
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And the following config values are set as admin:
      | enabled | 0 | aiplacement_modgen |
    When I request the file of the structure template "Standard module" for "C1"
    Then the modgen endpoint response should contain "The Module Assistant is currently disabled"
    And the modgen endpoint response should not contain "Library theme"

  Scenario: A structure that does not exist is reported
    Given I am on the "C1" "Course" page logged in as "teacher1"
    When I request the file of the structure template "a missing template" for "C1"
    Then the modgen endpoint response should contain "Structure file not found"
