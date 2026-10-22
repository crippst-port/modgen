@aiplacement @aiplacement_modgen @javascript @_file_upload
Feature: Building a module from a CSV file
  In order to build a module from a plan I already have
  As a teacher with the template permission
  I need to upload a CSV, review the structure it describes and approve it

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_ai                | 0 | aiplacement_modgen |
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
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |

  Scenario: An uploaded CSV is previewed and then built in the background
    # The teacher holds only the template permission, so this also covers following the job without the structure one.
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Structure from file" "link"
    And I click on "I agree to the terms of AI use in this system" "checkbox"
    And I press "Accept"
    And I upload "ai/placement/modgen/tests/fixtures/structure_themes.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "Foundations"
    And I should see "Introduction"
    And I should see "Application"
    And I should see "Methods"
    And I should see "Practice"
    And I press "Create"
    Then I should see "Creating 5 section(s) from uploaded file"
    And I run all adhoc tasks
    And I am on the "Course 1" "course" page
    And I should see "Foundations" in the "region-main" "region"
    And I should see "Application" in the "region-main" "region"
