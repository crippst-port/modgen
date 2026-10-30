@aiplacement @aiplacement_modgen
Feature: The Module Assistant admin settings list only current features
  In order to configure the plugin
  As an administrator
  I need a settings page without the options for features that were removed

  Scenario: The settings page offers a switch for each feature
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=aiplacement_modgen"
    Then I should see "Enable New Theme(s) and New Week(s)"
    And I should see "Enable Assessments section"
    And I should see "Enable Structure from file"
    And I should see "Enable Structure from prompt"
    And I should see "Enable on core Moodle course formats: Weekly & Custom sections"
    And the field "Enable on core Moodle course formats: Weekly & Custom sections" matches value ""

  Scenario: The settings page no longer offers the removed features
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=aiplacement_modgen"
    Then I should see "Enable New Theme(s) and New Week(s)"
    And I should not see "Enable Suggest toolbar button"
    And I should not see "Pedagogical guidance for suggestions"
    And I should not see "Enable base on existing module"
    And I should not see "Hide existing sections"
