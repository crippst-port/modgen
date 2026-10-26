@aiplacement @aiplacement_modgen @javascript
Feature: Structure from prompt form
  In order to describe a module in words
  As a teacher
  I need a prompt form that checks my input and leaves the course alone until I approve a structure

  Background:
    Given the following config values are set as admin:
      | enabled   | 1 | aiplacement_modgen |
      | enable_ai | 1 | aiplacement_modgen |
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
      | capability                            | permission | role           | contextlevel | reference |
      | aiplacement/modgen:generatewithprompt | Allow      | editingteacher | Course       | C1        |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I click on "Quick Add" "link"
    And I click on "Structure from prompt" "link"

  Scenario: The form offers both module formats and a required prompt
    Then I should see "Enter prompt"
    And the "Module format" select box should contain "Connected Weekly"
    And the "Module format" select box should contain "Connected Themed"

  Scenario: An empty prompt asks for the AI policy, then is refused and nothing is built
    When I click on "Preview Structure" "button" in the ".modal-footer" "css_element"
    Then I should see "AI statement Acceptance Required"
    When I click on "I agree to the terms of AI use in this system" "checkbox"
    And I click on "Accept" "button" in the ".modal-body" "css_element"
    Then I should see "You must supply a value here"
    And I should see "Enter prompt"
    And the course "C1" should have "0" sections
    And there should be "0" structure jobs in "C1"

  Scenario: Cancel closes the form and leaves the course untouched
    When I click on "Cancel" "button" in the ".modal-footer" "css_element"
    Then I should not see "Enter prompt"
    And the course "C1" should have "0" sections

  Scenario: Accepting the AI policy without ticking the box leaves the policy unaccepted
    When I set the field "Additional context or requests for the Assistant" to "A twelve week module on rivers"
    And I click on "Preview Structure" "button" in the ".modal-footer" "css_element"
    And I click on "Accept" "button" in the ".modal-body" "css_element"
    And I wait "1" seconds
    Then I should see "AI statement Acceptance Required"
    And there should be "0" structure jobs in "C1"

  Scenario: A prompt entered before accepting the policy is sent once it is accepted, and with no provider nothing is built
    When I set the field "Additional context or requests for the Assistant" to "A twelve week module on rivers"
    And I click on "Preview Structure" "button" in the ".modal-footer" "css_element"
    And I click on "I agree to the terms of AI use in this system" "checkbox"
    And I click on "Accept" "button" in the ".modal-body" "css_element"
    Then I should not see "AI statement Acceptance Required"
    And I should not see "You must supply a value here"
    And the course "C1" should have "0" sections
    And there should be "0" structure jobs in "C1"
