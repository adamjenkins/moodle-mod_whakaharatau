@mod @mod_whakaharatau
Feature: Practise inline on the course page
  In order to practise without leaving the course page
  As a student
  I need the activity to run inside the course page when the teacher chose that

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
      | activity     | name         | course | idnumber | section | displayinline | optionorder |
      | whakaharatau | Inline kurī  | C1     | L1       | 1       | 1             | 0           |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption |
      | L1       | A dog barking | 2       | 2             |

  @javascript @accessibility
  Scenario: Play the inline activity on the course page, then leave it with the exit X
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    Then "[data-region='mod-whakaharatau-player']" "css_element" should exist
    And the page should meet accessibility standards
    When I press the whakaharatau "start" button with the keyboard
    Then I should see "Slide 1 of 1"
    When I choose option 1 on the whakaharatau slide with the keyboard
    Then option 1 on the whakaharatau slide should be "wrong"
    And I should see "Option 1: not correct"
    # Practice: tries until correct.
    When I choose option 2 on the whakaharatau slide with the keyboard
    Then option 2 on the whakaharatau slide should be "correct"
    And I press the whakaharatau "next" button with the keyboard
    And I should see "Session summary"
    And the page should meet accessibility standards
    # On the course page, the exit X goes back to the start screen, not away from the page.
    When I press the whakaharatau "exit" button with the keyboard
    Then I should see "Inline kurī" in the "[data-region='startscreen']" "css_element"
    And I should see "Course 1"
