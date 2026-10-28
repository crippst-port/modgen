@aiplacement @aiplacement_modgen @javascript @_file_upload
Feature: Structure from file refuses what it cannot build
  In order to trust what is built
  As a teacher with the template permission
  I need a clear message when a file cannot be used, and nothing built from it

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
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |

  Scenario: Previewing with nothing chosen asks for something
    Given I open the Structure from file form in "C1" as "teacher1"
    When I press "Preview Structure"
    Then I should see "You must provide at least one of the following"
    And there should be "0" structure jobs in "C1"

  Scenario Outline: A file with nothing to build is refused with the reason, and nothing is built
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/<file>" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "Generation Failed"
    And I should see "<message>"
    And "Try Again" "link" should exist
    And there should be "0" structure jobs in "C1"
    And the course "C1" should have "0" sections

    Examples:
      | file                     | message                                                                                                                     |
      | bad_headers_only.csv     | No data was found in the CSV file.                                                                                          |
      | bad_unknown_labels.csv   | No data was found in the CSV file.                                                                                          |
      | bad_prose.csv            | No data was found in the CSV file.                                                                                          |
      | bad_semicolons.csv       | No data was found in the CSV file.                                                                                          |
      | bad_single_column.csv    | No data was found in the CSV file.                                                                                          |
      | bad_week_before_theme.csv | before any theme. Add a                                                                                                      |

  Scenario: Trying again after a refused file returns to the form
    Given I open the Structure from file form in "C1" as "teacher1"
    And I upload "ai/placement/modgen/tests/fixtures/csv/bad_prose.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    When I click on "Try Again" "link"
    Then I should see "Select or Upload Structure File"
    And I should see "Upload structure file"

  Scenario: A file with more sections than the CSV limit allows is refused with the counts
    Given the following config values are set as admin:
      | maxcsvsections | 3 | aiplacement_modgen |
    And I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "CSV file contains 5 sections, which exceeds the maximum allowed limit of 3."
    And there should be "0" structure jobs in "C1"

  Scenario: A build that would take the course over the total section cap is refused when approved
    Given the course "C1" has "1" themes with "1" weeks each
    And the following config values are set as admin:
      | maxtotalsections | 10 | aiplacement_modgen |
    And I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Create"
    Then I should see "That's too many sections to create at once"
    And there should be "0" structure jobs in "C1"
    And the course "C1" should have "5" sections

  Scenario Outline: Files that are not CSV files are not accepted by the file picker
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/<file>" file to "Upload structure file" filemanager
    Then I should see "<message>"

    Examples:
      | file       | message                                                  |
      | notes.txt  | filetype cannot be accepted                              |
      | plan_noext | filetype cannot be accepted                              |
