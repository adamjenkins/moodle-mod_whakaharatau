@mod @mod_whakaharatau
Feature: Build a listening practice activity by importing pictures and audio files
  In order to make an activity quickly
  As a teacher
  I need to create the activity, upload a ZIP of pictures and clips, and check the slides before they are saved

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  @javascript @_file_upload
  Scenario: Create the activity, import a ZIP matched by file names and fix the missing correct answer
    Given I log in as "teacher1"
    And I add a "whakaharatau" activity to course "Course 1" section "1" and I fill the form with:
      | Name | Kurī listening |
    And I am on the "Kurī listening" "mod_whakaharatau > Import" page
    When I upload "mod/whakaharatau/tests/fixtures/import_filenames.zip" file to "Files" filemanager
    And I press "Upload and check"
    Then I should see "Check the slides before importing"
    And I should see "The slides were matched by file name."
    # Slide 1 is marked by "-correct"; slide 2 has no marker.
    And "#mod_whakaharatau-correct-0-1:checked" "css_element" should exist
    And "#mod_whakaharatau-correct-1-none:checked" "css_element" should exist
    And I should see "No clip is marked correct. Choose the correct clip."
    # A clip with no picture is listed, and the text file is refused.
    And I should see "Files not used"
    And I should see "03-a.mp3"
    And I should see "notes.txt"
    And I set the field "Alternative text for slide 1" to "A dog"
    And I set the field "Alternative text for slide 2" to "A cat"
    And I click on "02-b.mp3" "radio"
    And I press "Import 2 slides"
    Then I should see "2 slides were imported."
    And I should not see "need fixing"
    And I am on the "Kurī listening" "mod_whakaharatau > View" page
    And I should not see "This activity is not ready yet"

  @javascript @_file_upload
  Scenario: Importing without fixing the correct answer leaves the activity unplayable
    Given the following "activities" exist:
      | activity     | name     | course | idnumber |
      | whakaharatau | Kai time | C1     | L1       |
    And I am on the "L1" "mod_whakaharatau > Import" page logged in as "teacher1"
    When I upload "mod/whakaharatau/tests/fixtures/import_filenames.zip" file to "Files" filemanager
    And I press "Upload and check"
    And I set the field "Alternative text for slide 1" to "A dog"
    And I set the field "Alternative text for slide 2" to "A cat"
    And I press "Import 2 slides"
    Then I should see "2 slides were imported."
    And I should see "1 slide needs fixing"
