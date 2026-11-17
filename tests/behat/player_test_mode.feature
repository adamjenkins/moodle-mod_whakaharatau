@mod @mod_whakaharatau
Feature: Do a listening practice test with the keyboard only
  In order to show what I can hear
  As a student
  I need to answer every slide with the keyboard and see my score at the end

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
      | activity     | name          | course | idnumber | mode | optionorder | shuffle |
      | whakaharatau | Kurī and ngeru | C1     | L1       | 1    | 0           | 0       |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption | transcripts        |
      | L1       | A dog barking | 3       | 1             | kurī, ngeru, manu  |
      | L1       | A cat asleep  | 3       | 2             | kurī, ngeru, manu  |

  @javascript @accessibility
  Scenario: Answer one slide right and one wrong, then see the score on the end screen
    Given I am on the "L1" "activity" page logged in as "student1"
    And I should see "2 slides"
    And the page should meet accessibility standards
    When I press the whakaharatau "start" button with the keyboard
    Then I should see "Slide 1 of 2"
    And the page should meet accessibility standards
    # Slide 1: option 1 is correct.
    When I choose option 1 on the whakaharatau slide with the keyboard
    Then option 1 on the whakaharatau slide should be "correct"
    And I should see "Option 1: correct"
    And I press the whakaharatau "next" button with the keyboard
    And I should see "Slide 2 of 2"
    # Slide 2: option 2 is correct; one try in a test, so a wrong choice reveals the answer.
    When I choose option 1 on the whakaharatau slide with the keyboard
    Then option 1 on the whakaharatau slide should be "wrong"
    And option 2 on the whakaharatau slide should be "revealed"
    And I should see "The correct answer was option 2."
    And I press the whakaharatau "next" button with the keyboard
    Then I should see "Your result"
    And I should see "1 / 2"
    And I should see "50%"
    And the page should meet accessibility standards

  @javascript
  Scenario: Leaving and coming back continues where the student stopped
    Given I am on the "L1" "activity" page logged in as "student1"
    And I press the whakaharatau "start" button with the keyboard
    And I choose option 1 on the whakaharatau slide with the keyboard
    When I am on the "L1" "activity" page
    Then I should see "Continue"
    And I press the whakaharatau "continue" button with the keyboard
    And I should see "Slide 2 of 2"
