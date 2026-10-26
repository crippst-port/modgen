@aiplacement @aiplacement_modgen @javascript
Feature: The job status page reports how a background build went
  In order to know whether my structure was built
  As a teacher who queued a build
  I need the job page to show success, problems or failure,

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
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
      | capability                         | permission | role           | contextlevel | reference |
      | aiplacement/modgen:managestructure | Allow      | editingteacher | Course       | C1        |

  Scenario: A build that finished with problems says so and stays open
    Given the following "aiplacement_modgen > jobs" exist:
      | course | user     | action           | status    | result                                                                                                                         |
      | C1     | teacher1 | create_from_json | completed | {"success":true,"messages":["Section created: Week 1"],"warnings":["The week \"Week 2\" could not be created: Something broke"]} |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I am on the job status page of the latest job in "C1"
    Then I should see "Completed with problems"
    And I should see "The week \"Week 2\" could not be created: Something broke"
    And I should see "Section created: Week 1"
    And I should not see "Completed successfully!"
    # A clean job returns to the course after a few seconds; one with problems must not.
    And I wait "5" seconds
    And I should see "Completed with problems"

  Scenario: A clean build is reported as a success
    Given the following "aiplacement_modgen > jobs" exist:
      | course | user     | action      | status    | result                                                         |
      | C1     | teacher1 | create_weeks | completed | {"success":true,"messages":["Section created: Week 1"],"warnings":[]} |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I am on the job status page of the latest job in "C1"
    Then I should see "Completed successfully!"
    And I should not see "Completed with problems"
    And "//span[@id='status-badge' and normalize-space(.)='Completed']" "xpath_element" should exist

  Scenario: A failed build shows the error
    Given the following "aiplacement_modgen > jobs" exist:
      | course | user     | action      | status | result                                                              |
      | C1     | teacher1 | create_weeks | failed | {"success":false,"error":"The course is too large.","will_retry":false} |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I am on the job status page of the latest job in "C1"
    Then I should see "Job failed"
    And I should see "The course is too large."


  Scenario: A failed build that will be retried keeps being watched until the retry succeeds
    Given the following "aiplacement_modgen > jobs" exist:
      | course | user     | action       | status | result                                                         |
      | C1     | teacher1 | create_weeks | failed | {"success":false,"error":"Deadlock found","will_retry":true} |
    When I am on the "Course 1" "course" page logged in as "teacher1"
    And I am on the job status page of the latest job in "C1"
    Then I should see "Deadlock found"
    And I should see "This job will automatically retry in a moment"
    When the latest structure job in "C1" completes successfully
    Then I should see "Completed successfully!"
