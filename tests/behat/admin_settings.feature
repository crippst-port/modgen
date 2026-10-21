@aiplacement @aiplacement_modgen
Feature: The Module Assistant admin settings list only current features
  In order to configure the plugin
  As an administrator
  I need a settings page without the options for features that were removed

  Scenario: The settings page offers the current feature switches
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=aiplacement_modgen"
    Then I should see "Enable AI generation"
    And I should see "Enable Quick add"
    And I should see "Enable Structure from file"

  Scenario: The settings page no longer offers the removed features
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=aiplacement_modgen"
    Then I should see "Enable Quick add"
    And I should not see "Enable Suggest toolbar button"
    And I should not see "Pedagogical guidance for suggestions"
    And I should not see "Enable base on existing module"
    And I should not see "Hide existing sections"
