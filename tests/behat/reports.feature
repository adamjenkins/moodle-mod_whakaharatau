@mod @mod_whakaharatau
Feature: Read and sort the listening practice reports
  In order to see how learners and questions are doing
  As a teacher
  I need reports that I can filter and sort by any column

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Aroha     | Ngata    | student1@example.com |
      | student2 | Zoe       | Chen     | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity     | name | course | idnumber | mode |
      | whakaharatau | Kurī | C1     | L1       | 1    |
    And the following "mod_whakaharatau > slides" exist:
      | activity | alttext       | options | correctoption | transcripts       |
      | L1       | A dog barking | 3       | 1             | kurī, ngeru, manu |
      | L1       | A cat asleep  | 3       | 2             | kurī, ngeru, manu |
    And the following "mod_whakaharatau > attempts" exist:
      | activity | user     | answers      | finish |
      | L1       | student1 | right, wrong | 1      |
      | L1       | student2 | right, right | 1      |

  Scenario: Sort the learners by clicking the headings
    Given I am on the "L1" "mod_whakaharatau > Report" page logged in as "teacher1"
    When I click on "Learners" "link" in the "region-main" "region"
    Then "Aroha Ngata" "text" should appear before "Zoe Chen" "text" in the "region-main" "region"
    When I click on "Overall score (%)" "link"
    Then "Aroha Ngata" "text" should appear before "Zoe Chen" "text" in the "region-main" "region"
    When I click on "Overall score (%)" "link"
    Then "Zoe Chen" "text" should appear before "Aroha Ngata" "text" in the "region-main" "region"

  Scenario: Filter the answers by learner
    Given I am on the "L1" "mod_whakaharatau > Report" page logged in as "teacher1"
    And I click on "Answers" "link" in the "region-main" "region"
    And I should see "Zoe Chen" in the "region-main" "region"
    When I set the field "Learner" to "aroha"
    And I press "Apply filters"
    Then I should see "Aroha Ngata" in the "region-main" "region"
    And I should not see "Zoe Chen" in the "region-main" "region"
    When I press "Clear filters"
    Then I should see "Zoe Chen" in the "region-main" "region"

  Scenario: The anonymised report hides small results, and the cross-activity report shows them to teachers
    Given I am on the "L1" "mod_whakaharatau > Anonymised report" page logged in as "teacher1"
    Then I should see "Fewer than 5" in the "A dog barking" "table_row"
    And I should not see "Aroha Ngata" in the "region-main" "region"
    When I am on the "L1" "mod_whakaharatau > Results across activities" page
    Then I should see "75%" in the "Kurī" "table_row"
    When I click on "Progress" "link" in the "region-main" "region"
    Then I should see "50%" in the "Aroha Ngata" "table_row"
