@aiplacement @aiplacement_modgen @javascript @_file_upload
Feature: Building a module from a CSV file
  In order to build a module from a plan I already have
  As a teacher with the template permission
  I need to upload a CSV, review the structure it describes, approve it, and get exactly that structure

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

  Scenario: The AI statement must be accepted before the form is shown
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I click on "Quick Add" "link"
    And I click on "Structure from file" "link"
    Then I should see "AI statement Acceptance Required"
    And I should not see "Upload structure file"
    And I click on "I agree to the terms of AI use in this system" "checkbox"
    And I press "Accept"
    And I should see "Select or Upload Structure File"
    And I should see "Upload structure file"

  Scenario: The form offers an upload and a preview button, and leaves the summary option to the preview
    Given I open the Structure from file form in "C1" as "teacher1"
    Then I should see "Select or Upload Structure File"
    And I should see "Upload structure file"
    And I should not see "Add a section summary activity to each section"
    And "Preview Structure" "button" should exist
    And I should not see "Select a pre-made structure"
    And I should not see "we advise deleting the existing content"

  Scenario: The form warns, but still offers the build, when the module already has content
    Given the following "activities" exist:
      | activity | name        | course | section |
      | page     | Course page | C1     | 0       |
    When I open the Structure from file form in "C1" as "teacher1"
    Then I should see "we advise deleting the existing content"
    And "Preview Structure" "button" should exist

  Scenario: A theme file is previewed and then built as themes, weeks and sessions
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then I should see "Check your module structure"
    And I should see "2 theme(s)"
    And I should see "3 week(s)"
    And the field "Title of theme 1" matches value "Foundations"
    And the field "Description of theme 1" matches value "About foundations"
    And the field "Title of week 1" matches value "Introduction"
    And the field "Title of week 2" matches value "Methods"
    And the field "Title of theme 2" matches value "Application"
    And the field "Title of week 3" matches value "Practice"
    And the field "Add a section summary activity to each section" matches value ""
    And I press "Create"
    And I should see "Creating 5 section(s) from uploaded file"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent       | collapsed | depth | summary           |
      | Foundations  | -            | 1         | 1     | About foundations |
      | Introduction | Foundations  | 1         | 2     | Intro week        |
      | Pre-session  | Introduction | 0         | 3     |                   |
      | Session      | Introduction | 0         | 3     |                   |
      | Post-session | Introduction | 0         | 3     |                   |
      | Methods      | Foundations  | 1         | 2     |                   |
      | Pre-session  | Methods      | 0         | 3     |                   |
      | Session      | Methods      | 0         | 3     |                   |
      | Post-session | Methods      | 0         | 3     |                   |
      | Application  | -            | 1         | 1     |                   |
      | Practice     | Application  | 1         | 2     |                   |
      | Pre-session  | Practice     | 0         | 3     |                   |
      | Session      | Practice     | 0         | 3     |                   |
      | Post-session | Practice     | 0         | 3     |                   |
    And the course "C1" structure should be sound

  Scenario: A weekly file is built as top-level weeks
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then the field "Title of week 1" matches value "Alpha"
    And the field "Title of week 2" matches value "Beta"
    And the field "Title of week 3" matches value "Gamma"
    And I press "Create"
    And I should see "Creating 3 section(s) from uploaded file"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent | collapsed | depth | summary    |
      | Alpha        | -      | 1         | 1     | First week |
      | Pre-session  | Alpha  | 0         | 2     |            |
      | Session      | Alpha  | 0         | 2     |            |
      | Post-session | Alpha  | 0         | 2     |            |
      | Beta         | -      | 1         | 1     |            |
      | Pre-session  | Beta   | 0         | 2     |            |
      | Session      | Beta   | 0         | 2     |            |
      | Post-session | Beta   | 0         | 2     |            |
      | Gamma        | -      | 1         | 1     |            |
      | Pre-session  | Gamma  | 0         | 2     |            |
      | Session      | Gamma  | 0         | 2     |            |
      | Post-session | Gamma  | 0         | 2     |            |
    And the course "C1" structure should be sound

  Scenario: A week added on the preview is built at the end, and one added then removed is not
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I should see "3 week(s)"
    And I press "Add week"
    And I set the field "Title of the new week" to "Delta"
    And I set the field "Description of the new week" to "An extra week"
    Then I should see "4 week(s)"
    And I press "Add week"
    And I should see "5 week(s)"
    And I click on "(//button[@data-action='remove'])[2]" "xpath_element"
    And I should see "4 week(s)"
    And I press "Create"
    And I should see "Creating 4 section(s) from uploaded file"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent | depth | summary       |
      | Alpha        | -      | 1     | First week    |
      | Pre-session  | Alpha  | 2     |               |
      | Session      | Alpha  | 2     |               |
      | Post-session | Alpha  | 2     |               |
      | Beta         | -      | 1     |               |
      | Pre-session  | Beta   | 2     |               |
      | Session      | Beta   | 2     |               |
      | Post-session | Beta   | 2     |               |
      | Gamma        | -      | 1     |               |
      | Pre-session  | Gamma  | 2     |               |
      | Session      | Gamma  | 2     |               |
      | Post-session | Gamma  | 2     |               |
      | Delta        | -      | 1     | An extra week |
      | Pre-session  | Delta  | 2     |               |
      | Session      | Delta  | 2     |               |
      | Post-session | Delta  | 2     |               |
    And the course "C1" structure should be sound

  Scenario: A theme added on the preview, with a week of its own, is built after the others
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Add theme"
    And I set the field "Title of the new theme" to "Review"
    And I click on "//section[.//label[text()='Title of the new theme']]//button[@data-action='addweek']" "xpath_element"
    And I set the field "Title of the new week" to "Revision"
    Then I should see "3 theme(s)"
    And I should see "4 week(s)"
    And I press "Create"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent       | depth |
      | Foundations  | -            | 1     |
      | Introduction | Foundations  | 2     |
      | Pre-session  | Introduction | 3     |
      | Session      | Introduction | 3     |
      | Post-session | Introduction | 3     |
      | Methods      | Foundations  | 2     |
      | Pre-session  | Methods      | 3     |
      | Session      | Methods      | 3     |
      | Post-session | Methods      | 3     |
      | Application  | -            | 1     |
      | Practice     | Application  | 2     |
      | Pre-session  | Practice     | 3     |
      | Session      | Practice     | 3     |
      | Post-session | Practice     | 3     |
      | Review       | -            | 1     |
      | Revision     | Review       | 2     |
      | Pre-session  | Revision     | 3     |
      | Session      | Revision     | 3     |
      | Post-session | Revision     | 3     |
    And the course "C1" structure should be sound

  Scenario: Themes and weeks on the preview fold away, one at a time or all together
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/themes_described.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And "Description of week 1" "field" should be visible
    And I click on "//div[@id='aiplacement-modgen-body-w0_0']/preceding-sibling::div//button[@data-action='toggle']" "xpath_element"
    Then "Description of week 1" "field" should not be visible
    And "Description of week 2" "field" should be visible
    And the field "Title of week 1" matches value "Introduction"
    And I press "Collapse all"
    And "Description of theme 1" "field" should not be visible
    And "Description of theme 2" "field" should not be visible
    And I press "Expand all"
    And "Description of week 1" "field" should be visible
    And "Description of theme 2" "field" should be visible

  Scenario: Titles and descriptions edited on the preview are the ones built
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I set the field "Title of week 2" to "Beta revised"
    And I set the field "Description of week 1" to "First week, & more"
    And I set the field "Description of week 3" to "Gamma notes"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name         | parent       | depth | summary            |
      | Alpha        | -            | 1     | First week, & more |
      | Pre-session  | Alpha        | 2     |                    |
      | Session      | Alpha        | 2     |                    |
      | Post-session | Alpha        | 2     |                    |
      | Beta revised | -            | 1     |                    |
      | Pre-session  | Beta revised | 2     |                    |
      | Session      | Beta revised | 2     |                    |
      | Post-session | Beta revised | 2     |                    |
      | Gamma        | -            | 1     | Gamma notes        |
      | Pre-session  | Gamma        | 2     |                    |
      | Session      | Gamma        | 2     |                    |
      | Post-session | Gamma        | 2     |                    |

  Scenario: The summary option adds an activity to every week and session
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I set the field "Add a section summary activity to each section" to "1"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name         | parent | activities | activitynames         |
      | Alpha        | -      | 1          | Alpha                 |
      | Pre-session  | Alpha  | 1          | Pre-session activity  |
      | Session      | Alpha  | 1          | Session activity      |
      | Post-session | Alpha  | 1          | Post-session activity |
      | Beta         | -      | 1          | Beta                  |
      | Pre-session  | Beta   | 1          | Pre-session activity  |
      | Session      | Beta   | 1          | Session activity      |
      | Post-session | Beta   | 1          | Post-session activity |
      | Gamma        | -      | 1          | Gamma                 |
      | Pre-session  | Gamma  | 1          | Pre-session activity  |
      | Session      | Gamma  | 1          | Session activity      |
      | Post-session | Gamma  | 1          | Post-session activity |

  Scenario Outline: Whatever the file is called, the same structure is built
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/<file>" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name         | parent      | depth |
      | Foundations  | -           | 1     |
      | Introduction | Foundations | 2     |
      | Pre-session  | Introduction | 3    |
      | Session      | Introduction | 3    |
      | Post-session | Introduction | 3    |
      | Methods      | Foundations | 2     |
      | Pre-session  | Methods     | 3     |
      | Session      | Methods     | 3     |
      | Post-session | Methods     | 3     |
    And the course "C1" structure should be sound

    Examples:
      | file                                                                                                                      |
      | My module plan.csv                                                                                                        |
      | Plan (v2) - final.csv                                                                                                     |
      | PLAN.CSV                                                                                                                  |
      | module.2026.term1.csv                                                                                                     |
      | long-name-long-name-long-name-long-name-long-name-long-name-long-name-long-name-long-name-long-name-long-name-long-name-.csv |

  Scenario Outline: Files exported by different programs build the same structure
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/<file>" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then the field "Title of theme 1" matches value "Café studies"
    And I press "Create"
    And I run all adhoc tasks
    And the course "C1" structure should be:
      | name         | parent       | depth |
      | Café studies | -            | 1     |
      | Intro        | Café studies | 2     |
      | Pre-session  | Intro        | 3     |
      | Session      | Intro        | 3     |
      | Post-session | Intro        | 3     |
      | Practice     | Café studies | 2     |
      | Pre-session  | Practice     | 3     |
      | Session      | Practice     | 3     |
      | Post-session | Practice     | 3     |
    And the course "C1" structure should be sound

    Examples:
      | file                    |
      | config_lf.csv           |
      | config_crlf.csv         |
      | config_mac_cr.csv       |
      | config_bom.csv          |
      | config_windows_1252.csv |
      | config_quoted.csv       |

  Scenario: Markup in a file is removed and Unicode is kept
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/markup_and_unicode.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" structure should be:
      | name              | parent |
      | Bold & beautiful  | -      |
      | Pre-session       | Bold & beautiful |
      | Session           | Bold & beautiful |
      | Post-session      | Bold & beautiful |
      | Plan, do, review  | -      |
      | Pre-session       | Plan, do, review |
      | Session           | Plan, do, review |
      | Post-session      | Plan, do, review |
      | 日本語            | -      |
      | Pre-session       | 日本語 |
      | Session           | 日本語 |
      | Post-session      | 日本語 |

  Scenario: Titles are previewed as written, and the JSON download keeps every character
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/ampersand_hash_percent.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    Then the field "Title of week 1" matches value "Q&A on C# basics"
    And the field "Title of week 2" matches value "Rivers: 100% coverage"
    And I should not see "&amp;"
    And the "href" attribute of "Download as JSON" "link" should contain "C%23%20basics"
    And the "href" attribute of "Download as JSON" "link" should contain "100%25%20coverage"
  Scenario: A title longer than a section name can hold is cut to fit and the build still completes
    Given I open the Structure from file form in "C1" as "teacher1"
    When I upload "ai/placement/modgen/tests/fixtures/csv/long_title.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" should have "12" sections
    And the course "C1" structure should be sound

  Scenario: Building into a course that already has structure adds to it
    Given the course "C1" has "1" themes with "1" weeks each
    And I open the Structure from file form in "C1" as "teacher1"
    And I should see "we advise deleting the existing content"
    When I upload "ai/placement/modgen/tests/fixtures/csv/weekly.csv" file to "Upload structure file" filemanager
    And I press "Preview Structure"
    And I press "Create"
    And I run all adhoc tasks
    Then the course "C1" should have "17" sections
    And the course "C1" structure should be sound

