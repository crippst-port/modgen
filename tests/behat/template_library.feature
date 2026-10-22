@aiplacement @aiplacement_modgen @javascript @_file_upload
Feature: The CSV structure library
  In order to give teachers ready-made structures
  As an administrator
  I need to add, order and remove CSV structure files, and teachers need to build from them

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

  Scenario: An empty library invites the first structure
    When I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    Then I should see "No structures available yet. Add your first structure below."
    And I should see "Add new structure"
    And I should see "Structure name"
    And I should see "Description"
    And I should see "CSV structure file"

  Scenario: A structure is added and listed with its name and description
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    When I set the field "Structure name" to "Standard module"
    And I set the field "Description" to "Twelve week plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    Then I should see "Standard module" in the "generaltable" "table"
    And I should see "Twelve week plan" in the "Standard module" "table_row"
    And I should not see "No structures available yet"

  Scenario: A file that is not a usable structure is refused and nothing is added
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    When I set the field "Structure name" to "Broken"
    And I upload "ai/placement/modgen/tests/fixtures/csv/bad_headers_only.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    Then I should see "The uploaded CSV file does not have a valid structure: No data was found in the CSV file."
    And I should see "No structures available yet"

  Scenario: A structure needs a name and a file
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    When I press "Save changes"
    Then I should see "Required"
    And I should see "No structures available yet"

  Scenario: Two structures can be added from files with the same name
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I set the field "Structure name" to "First copy"
    And I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    When I set the field "Structure name" to "Second copy"
    And I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    Then I should see "First copy" in the "generaltable" "table"
    And I should see "Second copy" in the "generaltable" "table"

  Scenario: Structures are ordered, and can be moved up and down
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I set the field "Structure name" to "Alpha plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    And I set the field "Structure name" to "Beta plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    And "Alpha plan" "text" should appear before "Beta plan" "text"
    When I click on "Move down" "link" in the "Alpha plan" "table_row"
    Then "Beta plan" "text" should appear before "Alpha plan" "text"
    And I click on "Move up" "link" in the "Alpha plan" "table_row"
    And "Alpha plan" "text" should appear before "Beta plan" "text"

  Scenario: Deleting asks first, and can be cancelled
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I set the field "Structure name" to "Short lived"
    And I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    When I click on "Delete" "link" in the "Short lived" "table_row"
    Then I should see "Are you sure you want to delete the structure \"Short lived\"? This cannot be undone."
    And I click on "Cancel" "button"
    And I should see "Short lived" in the "generaltable" "table"
    And I click on "Delete" "link" in the "Short lived" "table_row"
    And I click on "Delete" "button"
    And I should see "No structures available yet"

  Scenario: A teacher builds from a library structure
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I set the field "Structure name" to "Standard module"
    And I set the field "Description" to "Twelve week plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    And I log out
    When I open the Structure from file form in "C1" as "teacher1"
    Then I should see "Select a pre-made structure"
    And the "Select a pre-made structure" select box should contain "-- None selected --"
    And the "Select a pre-made structure" select box should contain "Standard module - Twelve week plan"
    And I set the field "Select a pre-made structure" to "Standard module - Twelve week plan"
    And I press "Preview Structure"
    And I should see "Foundations"
    And I press "Create"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent       | summary           |
      | Assessments  | -            |                   |
      | Foundations  | -            | About foundations |
      | Introduction | Foundations  | Intro week        |
      | Pre-session  | Introduction |                   |
      | Session      | Introduction |                   |
      | Post-session | Introduction |                   |
      | Methods      | Foundations  |                   |
      | Pre-session  | Methods      |                   |
      | Session      | Methods      |                   |
      | Post-session | Methods      |                   |
      | Application  | -            |                   |
      | Practice     | Application  |                   |
      | Pre-session  | Practice     |                   |
      | Session      | Practice     |                   |
      | Post-session | Practice     |                   |
    And the course "C1" structure should be sound

  Scenario: An uploaded file is used instead of a library structure chosen in the same form
    Given I log in as "admin"
    And I visit "/ai/placement/modgen/manage_templates.php"
    And I set the field "Structure name" to "Library plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "CSV structure file" filemanager
    And I press "Save changes"
    And I log out
    When I open the Structure from file form in "C1" as "teacher1"
    And I set the field "Select a pre-made structure" to "Library plan"
    And I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "Alpha"
    And I should not see "Foundations"

  Scenario: The library is not offered when Structure from file is switched off
    Given the following config values are set as admin:
      | enable_structurefromfile | 0 | aiplacement_modgen |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "Module Assistant"
    And I should not see "Structure from file"
