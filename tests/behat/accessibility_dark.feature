@mod @mod_whakaharatau @accessibility
Feature: The listening practice player meets accessibility standards in both colour modes
  In order to use the activity whichever colour mode I choose
  As a student
  I need the start, slide and end screens to pass the accessibility checks in light and dark mode

  Colour modes exist from Moodle 5.3 (theme_boost enablecolourmodes); on 5.2 the scenarios are skipped.

  Background:
    Given the site is running Moodle version 5.3 or higher
    And the following config values are set as admin:
      | enablecolourmodes | 1 | theme_boost |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity     | name       | course | idnumber | mode | optionorder |
      | whakaharatau | Kurī test  | C1     | L1       | 1    | 0           |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption |
      | L1       | A dog barking | 3       | 1             |

  @javascript
  Scenario Outline: Start, slide and end screens in <mode> mode
    Given the following "user preferences" exist:
      | user     | preference             | value  |
      | student1 | theme_boost_colourmode | <mode> |
    And I am on the "L1" "activity" page logged in as "student1"
    Then the page should meet accessibility standards
    When I press the whakaharatau "start" button with the keyboard
    Then the page should meet accessibility standards
    When I choose option 2 on the whakaharatau slide with the keyboard
    Then the page should meet accessibility standards
    When I press the whakaharatau "next" button with the keyboard
    Then I should see "Your result"
    And the page should meet accessibility standards

    Examples:
      | mode  |
      | light |
      | dark  |
