@mod @mod_whakaharatau
Feature: Practise with Repeat All until I leave
  In order to rehearse as long as I like
  As a student
  I need the deck to start again in a new round, and the exit X to show my session summary

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity     | name         | course | idnumber | mode | repeatmode | optionorder |
      | whakaharatau | Repeat kurī  | C1     | L1       | 0    | 1          | 0           |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption |
      | L1       | A dog barking | 2       | 1             |
      | L1       | A cat asleep  | 2       | 2             |

  @javascript
  Scenario: Finish round 1, see round 2 begin, then exit to the session summary
    Given I am on the "L1" "activity" page logged in as "student1"
    And I should see "Repeats until you leave"
    When I press the whakaharatau "start" button with the keyboard
    And I choose option 1 on the whakaharatau slide with the keyboard
    And I press the whakaharatau "next" button with the keyboard
    And I choose option 1 on the whakaharatau slide with the keyboard
    And I choose option 2 on the whakaharatau slide with the keyboard
    Then I should see "Next round"
    # The "Round 2" banner shows, then the round's first slide follows on its own.
    When I press the whakaharatau "next" button with the keyboard
    Then I should see "Round 2: slide 1 of 2"
    When I press the whakaharatau "exit" button with the keyboard
    Then I should see "Session summary"
    And I should see "Slides played"
    And I should see "Right first time"
    And "Back to course" "button" should exist
