@aiplacement @aiplacement_modgen @javascript
Feature: Section changes on a Flexible sections course wait for each other
  In order to keep a Flexible sections course in order when sections are changed quickly
  As a teacher
  I need each section change to wait until the last one, mine or anyone else's, is done

  Background:
    Given the following config values are set as admin:
      | enabled | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format       | numsections |
      | Course 1 | C1        | flexsections | 3           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: A section delete waits while another change to the sections is under way
    # An empty section is deleted without asking first.
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    Then I should see "Another change to this module is in progress"
    And the course "C1" should have "3" sections
    When the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    Then the course "C1" should have "2" sections

  Scenario: A section duplicate waits while another change to the sections is under way
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    Then I should see "Another change to this module is in progress"
    And the course "C1" should have "3" sections
    When the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    And I wait until the page is ready
    Then the course "C1" should have "4" sections

  Scenario: Nothing is held back when the guard is switched off
    Given the following config values are set as admin:
      | enable_structureguard | 0 | aiplacement_modgen |
    And the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    And I wait until the page is ready
    Then I should not see "Another change to this module is in progress"
    And the course "C1" should have "4" sections

  Scenario: Switching the guard off takes effect on a course page that is already open
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And the following config values are set as admin:
      | enable_structureguard | 0 | aiplacement_modgen |
    And the sections of "C1" are being changed elsewhere
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    And I wait until the page is ready
    Then I should not see "Another change to this module is in progress"
    And the course "C1" should have "4" sections

  Scenario: The loader goes once a section with content is deleted, and again for the next one
    Given the following "activities" exist:
      | activity | name    | course | section |
      | page     | Page A  | C1     | 2       |
      | page     | Page B  | C1     | 3       |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I click on "Yes" "button" in the ".modal-dialog" "css_element"
    And I wait until "Page B" "text" does not exist
    Then the section change loader should be gone
    And the course "C1" should have "2" sections
    When I open section "2" edit menu
    And I click on "Delete" "link" in the "li#section-2" "css_element"
    And I click on "Yes" "button" in the ".modal-dialog" "css_element"
    And I wait until "Page A" "text" does not exist
    Then the section change loader should be gone
    And the course "C1" should have "1" sections

  Scenario: A delete that waits names itself, then confirms it is done and closes
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I should see "Waiting for another change to finish"
    And the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    Then the section change loader should have said, in order:
      | Waiting for another change to finish |
      | Deleting the section                 |
      | Section deleted                      |
    And the section change loader should be gone
    And the course "C1" should have "2" sections

  Scenario: A quick change shows no loader and no confirmation
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait until the page is ready
    Then the section change loader should not have appeared
    And the section change loader should be gone
    And the course "C1" should have "2" sections

  Scenario: A move that waits names itself and confirms it
    # Unnamed sections are called after their position, so the activities in them tell them apart.
    Given the following "activities" exist:
      | activity | name   | course | section |
      | page     | Page A | C1     | 1       |
      | page     | Page C | C1     | 3       |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    And I open section "3" edit menu
    And I click on "Move" "link" in the "#section-3 .action-menu" "css_element"
    And I should see "Move Topic 3 to this location:" in the "Move section" "dialogue"
    And the sections of "C1" are being changed elsewhere
    When I click on "Before 'Topic 1'" "link" in the "Move section" "dialogue"
    And I should see "Waiting for another change to finish"
    And the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    Then the section change loader should have said, in order:
      | Moving the section |
      | Section moved      |
    And the section change loader should be gone
    And "Page C" "text" should appear before "Page A" "text" in the ".course-content" "css_element"

  Scenario: Adding a section that waits names itself and confirms it
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    When I click on "Add section" "link" in the "li#section-1" "css_element"
    And I should see "Waiting for another change to finish"
    And the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    Then the section change loader should have said, in order:
      | Adding a section |
      | Section added    |
    And the section change loader should be gone
    And the course "C1" should have "4" sections

  Scenario: Merging a section into its parent that waits names itself and confirms it
    # The new subsection of section 1 becomes section 2; empty and unnamed, it merges without asking.
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I open section "1" edit menu
    And I click on "Add subsection" "link" in the "li#section-1" "css_element"
    And the course "C1" should have "4" sections
    And I start watching the section change loader
    And the sections of "C1" are being changed elsewhere
    When I open section "2" edit menu
    And I click on "Merge with parent" "link" in the "li#section-2" "css_element"
    And I should see "Waiting for another change to finish"
    And the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    Then the section change loader should have said, in order:
      | Merging the section |
      | Section merged      |
    And the section change loader should be gone
    And the course "C1" should have "3" sections

  Scenario: A change that fails on the server closes the loader without confirming anything
    # Flexible sections' delete needs moodle/course:movesections, taken away once the page is open.
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I should see "Waiting for another change to finish"
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | moodle/course:movesections | Prohibit   | editingteacher | Course       | C1        |
    And the other change to the sections of "C1" finishes and the held-back change fails
    Then the held-back change should have failed with "you do not currently have permissions to do that"
    And the section change loader should be gone
    And the section change loader should not have said "Section deleted"
    And the course "C1" should have "3" sections

  Scenario: A change goes ahead when the check for other changes fails
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And the section change check fails
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait until the page is ready
    Then the section change loader should be gone
    And the course "C1" should have "2" sections

  Scenario: A change that waits too long for another is not made, and says so
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And the section change loader gives up waiting after "3" seconds
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    Then I should see "Your change wasn't made, because another change to this module is taking a long time."
    And the section change loader should be gone
    And the course "C1" should have "3" sections

  Scenario: Switching the placement off takes effect on a course page that is already open
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And the following config values are set as admin:
      | enabled | 0 | aiplacement_modgen |
    And the sections of "C1" are being changed elsewhere
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    And I wait until the page is ready
    Then I should not see "Waiting for another change to finish"
    And the course "C1" should have "4" sections

  Scenario: A course in another format gets no loader
    Given the following config values are set as admin:
      | enable_coreformats | 1 | aiplacement_modgen |
    And the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Course 2 | C2        | topics | 3           |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And I am on the "Course 2" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching the section change loader
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait until the page is ready
    Then the section change loader should not have appeared
    And the section change loader should be gone

  Scenario: A change asked for while a duplicate loads its page waits for the page to go
    Given the sections of "C1" are being changed elsewhere
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    And I should see "Waiting for another change to finish"
    # Asked for behind the cover: it must not run alongside the duplicate.
    And the course editor is asked to delete section "3"
    And the other change to the sections of "C1" finishes
    And I wait until the section change loader has gone
    And I wait until the page is ready
    Then the course "C1" should have "4" sections

  Scenario: A change goes ahead when the loader cannot be drawn
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And the section change loader cannot be drawn
    And the sections of "C1" are being changed elsewhere
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait "2" seconds
    Then the course "C1" should have "3" sections
    When the other change to the sections of "C1" finishes
    And I wait "3" seconds
    And I wait until the page is ready
    Then the section change loader should be gone
    And the course "C1" should have "2" sections

  Scenario: A duplicate whose page never loads uncovers the page again
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And a section change that loads a page is stopped, and given up on after "2" seconds
    When I open section "2" edit menu
    And I click on "Duplicate" "link" in the "li#section-2" "css_element"
    And I wait "4" seconds
    Then the section change loader should be gone
    And the course "C1" should have "3" sections
    # The page can be used again.
    And I press the escape key
    When I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait until the page is ready
    Then the course "C1" should have "2" sections

  Scenario: A refused change raises no uncaught error once the guard is switched off
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    And I start watching for uncaught errors
    And the following config values are set as admin:
      | enable_structureguard | 0 | aiplacement_modgen |
    # The first change after switching off finds out, and changes from then on are not guarded.
    And I open section "3" edit menu
    And I click on "Delete" "link" in the "li#section-3" "css_element"
    And I wait until the page is ready
    And the course "C1" should have "2" sections
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | moodle/course:movesections | Prohibit   | editingteacher | Course       | C1        |
    When the course editor is asked to delete section "2", which fails
    Then the held-back change should have failed with "you do not currently have permissions to do that"
    And there should have been no uncaught errors
    And the course "C1" should have "2" sections

  Scenario: Focus goes back where it was once a change is saved
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I use the "Add section" link in section "1" from the keyboard
    And I wait until the page is ready
    Then the course "C1" should have "4" sections
    And the section change loader should be gone
    And focus should be back where it was
