@mod @mod_whakaharatau
Feature: Each role sees only the teacher pages it may use
  In order to keep slides and learners' results safe
  As a teacher
  I need the authoring and report links shown only to the roles that may use them

  # The refusals themselves (opening a page by its URL) are tested in tests/local/page_access_test.php:
  # Moodle's Behat counts any error page as a failed step.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
    And the following "activities" exist:
      | activity     | name           | course | idnumber | mode |
      | whakaharatau | Kurī and ngeru | C1     | L1       | 1    |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption | transcripts       |
      | L1       | A dog barking | 3       | 1             | kurī, ngeru, manu |
    And the following "mod_whakaharatau > attempts" exist:
      | activity | user     | answers | finish |
      | L1       | student1 | right   | 1      |

  Scenario: A student sees none of the teacher pages
    When I am on the "L1" "activity" page logged in as "student1"
    Then "Edit slides" "link" should not exist in the "#page" "css_element"
    And "Import slides" "link" should not exist in the "#page" "css_element"
    And "Export slides" "link" should not exist in the "#page" "css_element"
    And "Report" "link" should not exist in the "#page" "css_element"

  Scenario: A non-editing teacher sees the report but not the authoring pages, and cannot delete attempts
    When I am on the "L1" "activity" page logged in as "teacher2"
    Then "Report" "link" should exist in the "#page" "css_element"
    And "Edit slides" "link" should not exist in the "#page" "css_element"
    And "Import slides" "link" should not exist in the "#page" "css_element"
    And "Export slides" "link" should not exist in the "#page" "css_element"
    When I am on the "L1" "mod_whakaharatau > Report" page
    Then I should see "Student One"
    And "Delete selected" "button" should not exist in the "region-main" "region"

  Scenario: An editing teacher sees every teacher page and can delete attempts
    When I am on the "L1" "activity" page logged in as "teacher1"
    Then "Report" "link" should exist in the "#page" "css_element"
    And "Edit slides" "link" should exist in the "#page" "css_element"
    And "Import slides" "link" should exist in the "#page" "css_element"
    And "Export slides" "link" should exist in the "#page" "css_element"
    When I am on the "L1" "mod_whakaharatau > Report" page
    Then I should see "Student One"
    And "Delete selected" "button" should exist in the "region-main" "region"
