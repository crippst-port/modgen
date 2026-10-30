@aiplacement @aiplacement_modgen @javascript @_file_upload
Feature: Building in Custom sections and Weekly sections
  In order to use the Module Assistant without the Flexible sections format
  As a teacher with Module Assistant permissions
  I need weeks built with their sessions as subsections, and anything with themes refused

  Background:
    Given the following config values are set as admin:
      | enabled                  | 1 | aiplacement_modgen |
      | enable_ai                | 0 | aiplacement_modgen |
      | enable_quickadd          | 1 | aiplacement_modgen |
      | enable_structurefromfile | 1 | aiplacement_modgen |
      | enable_coreformats       | 1 | aiplacement_modgen |
    And subsections are enabled
    And the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Course 1 | C1        | topics | 0           |
      | Course 2 | C2        | weeks  | 0           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure      | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:generatefromtemplate | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:checkstructure       | Allow      | editingteacher | Course       | C1        |
      | aiplacement/modgen:managestructure      | Allow      | editingteacher | Course       | C2        |

  Scenario: The toolbar offers weeks and Assessments, but no themes or Check Structure
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    Then I should see "New Week(s)"
    And I should see "Assessments section"
    And I should see "Structure from file"
    And I should not see "New Theme(s)"
    And I should not see "Check Structure"

  Scenario Outline: New Week(s) builds each week as a section with its sessions as subsections
    Given I am on the "<course>" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "New Week(s)" "link"
    And I set the field "How many weeks do you want to create?" to "2"
    And I click on "Create" "button" in the ".modal-footer" "css_element"
    And I should see "Creating 2 standalone week(s)"
    And I run all adhoc tasks
    Then the course "<shortname>" structure should be:
      | name         | parent | depth | activities |
      | Week 1       | -      | 1     | 0          |
      | Pre-session  | Week 1 | 2     | 0          |
      | Session      | Week 1 | 2     | 0          |
      | Post-session | Week 1 | 2     | 0          |
      | Week 2       | -      | 1     | 0          |
      | Pre-session  | Week 2 | 2     | 0          |
      | Session      | Week 2 | 2     | 0          |
      | Post-session | Week 2 | 2     | 0          |
    And the course "<shortname>" structure should be sound
    And the course "<shortname>" should use the "<format>" format
    And I am on the "<course>" "course" page
    And I should see "Week 2" in the "region-main" "region"

    Examples:
      | course   | shortname | format |
      | Course 1 | C1        | topics |
      | Course 2 | C2        | weeks  |

  Scenario: With Custom sections and Weekly sections switched off there is no toolbar in those courses
    Given the following config values are set as admin:
      | enable_coreformats | 0 | aiplacement_modgen |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then "nav[aria-label='Module Assistant']" "css_element" should not exist
    And I should not see "Quick Add"

  Scenario: While subsections are switched off the toolbar says why, in place of the Quick Add menu
    Given subsections are disabled
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    Then I should see "subsections are switched off on this site"
    And I should not see "Quick Add"

  Scenario: A Quick Add request is refused while subsections are switched off
    Given subsections are disabled
    And I log in as "teacher1"
    When I post "action=create_weeks&weekcount=1" to the modgen endpoint "ajax/create_sections.php" for "C1"
    Then the modgen endpoint response should contain "subsections are switched off on this site"
    And there should be "0" structure jobs in "C1"

  Scenario: A file with themes is refused before the preview
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "This structure uses themes, which need the Flexible sections format"
    And I should not see "Module Structure"
    And there should be "0" structure jobs in "C1"

  Scenario: A weekly file is built as weeks with their sessions as subsections
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I should see "Module Structure"
    And I press "Create"
    And I should see "Creating 3 section(s) from uploaded file"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name         | parent | depth | summary    |
      | Alpha        | -      | 1     | First week |
      | Pre-session  | Alpha  | 2     |            |
      | Session      | Alpha  | 2     |            |
      | Post-session | Alpha  | 2     |            |
      | Beta         | -      | 1     |            |
      | Pre-session  | Beta   | 2     |            |
      | Session      | Beta   | 2     |            |
      | Post-session | Beta   | 2     |            |
      | Gamma        | -      | 1     |            |
      | Pre-session  | Gamma  | 2     |            |
      | Session      | Gamma  | 2     |            |
      | Post-session | Gamma  | 2     |            |
    And the course "C1" structure should be sound
    And the course "C1" should use the "topics" format

  Scenario: The Assessments section goes first, before weeks added earlier, and only once
    Given the course "C1" has "1" standalone weeks
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Assessments section" "link"
    And I click on "Add section" "button" in the ".modal-footer" "css_element"
    Then I should see "section has been added"
    And the course "C1" structure should be:
      | name         | parent | depth |
      | Assessments  | -      | 1     |
      | Week 1       | -      | 1     |
      | Pre-session  | Week 1 | 2     |
      | Session      | Week 1 | 2     |
      | Post-session | Week 1 | 2     |
    And the course "C1" structure should be sound
    And I am on the "Course 1" "course" page
    And I click on "Quick Add" "link"
    And I click on "Assessments section" "link"
    And I click on "Add section" "button" in the ".modal-footer" "css_element"
    And I should see "already has a section called"
