<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for mod_whakaharatau.
 *
 * @package    mod_whakaharatau
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addoption'] = 'Add another option';
$string['addslide'] = 'Add a slide';
$string['allowreview'] = 'Allow review';
$string['allowreview_help'] = 'If enabled, learners can look back over a finished Test attempt and see which clip was correct on each slide.';
$string['alttext'] = 'Alternative text';
$string['alttext_help'] = 'A short description of the picture for learners who cannot see it, read out by screen readers. Describe what the learner needs to match the audio, for example "A dog running on the beach". Not needed if the picture is marked as decorative.';
$string['alttextforslide'] = 'Alternative text for slide {$a}';
$string['alttextrequired'] = 'Enter alternative text, or mark the picture as decorative.';
$string['area_cover'] = 'Cover image';
$string['area_optionaudio'] = 'Option audio';
$string['area_slideimage'] = 'Slide images';
$string['area_slideprompt'] = 'Slide prompt audio';
$string['attemptfinished'] = 'This attempt is already finished.';
$string['attemptnotfinished'] = 'This attempt is not finished yet.';
$string['attemptsallowed'] = 'Attempts allowed';
$string['attemptsallowed_help'] = 'How many Test attempts each learner may make. Practice sessions are never limited.';
$string['attemptsdeleted'] = 'Attempts and practice sessions deleted: {$a}.';
$string['audioerror'] = 'This clip could not be played.';
$string['autoplay'] = 'Autoplay prompt audio';
$string['autoplay_help'] = 'If enabled, a slide\'s prompt audio (if it has one) plays when the slide appears. Audio never plays before the learner has pressed Start.';
$string['backtocourse'] = 'Back to course';
$string['backtoresults'] = 'Back to the results';
$string['backtostart'] = 'Back to the start';
$string['cannotattempt'] = 'You can look at this activity, but you cannot do it. Log in with an account that is enrolled in this course to take part.';
$string['chooseinstruction'] = 'Choose the audio that matches the picture';
$string['chooseoption'] = 'Choose option {$a->number} of {$a->count}';
$string['completionallslides'] = 'Answer every slide at least once';
$string['completionallslides_desc'] = 'Answer every slide at least once';
$string['completionallslides_help'] = 'The activity is complete when the learner has answered every slide at least once, in any session or attempt.';
$string['completiondetail:allslides'] = 'Answer every slide at least once';
$string['continue'] = 'Continue';
$string['correctisoption'] = 'Correct: option {$a}';
$string['correctoption'] = 'Correct option';
$string['correctoption_help'] = 'The option whose audio matches the picture. Exactly one option is correct.';
$string['correctrequired'] = 'Choose the correct option. It must have an audio file.';
$string['correctwas'] = 'The correct answer was option {$a}.';
$string['cover'] = 'Cover image';
$string['cover_help'] = 'An optional image for the start screen. Without one, the first slide\'s image is used. JPEG, PNG, GIF or WebP.';
$string['decorative'] = 'Decorative';
$string['decorative_help'] = 'Tick this only if the picture adds nothing a screen-reader user needs, for example when the prompt already says what to listen for. A decorative picture is hidden from screen readers.';
$string['decorative_label'] = 'The picture is decorative';
$string['defaultsheading'] = 'Default settings for new activities';
$string['defaultsheading_desc'] = 'These values are used for new Listening practice activities. Tick "Advanced" to show a setting only under "Show more", or "Locked" to stop teachers from changing it.';
$string['deleteattemptsconfirm'] = 'Delete the {$a} selected attempts and practice sessions? Their answers are deleted too, and grades and completion are recalculated. This cannot be undone.';
$string['deleteselected'] = 'Delete selected';
$string['deleteslide'] = 'Delete slide {$a}';
$string['deleteslideconfirm'] = 'Delete slide {$a}, its picture and its audio? Learners\' earlier answers are kept in the report, but this cannot be undone.';
$string['displayheading'] = 'Display';
$string['displayinline'] = 'Show on course page';
$string['displayinline_help'] = 'Choose whether the course page shows only a link to the activity, or the whole activity, so learners can practise without leaving the course page.';
$string['displayinlineyes'] = 'Yes, inline on the course page';
$string['displaylink'] = 'No, link only';
$string['editslide'] = 'Edit slide {$a}';
$string['editslides'] = 'Edit slides';
$string['errornotyourattempt'] = 'This attempt belongs to someone else.';
$string['errorpracticegradecompletion'] = 'Practice mode gives no grade, so this condition can never be met. Choose Test mode or clear this condition.';
$string['errorscalenotsupported'] = 'This activity is scored in points. Choose the grade type "Point".';
$string['errorslidenotinactivity'] = 'That slide does not belong to this activity.';
$string['errorslideorder'] = 'The new slide order does not match the slides of this activity.';
$string['errorwrongslide'] = 'That option does not belong to this slide.';
$string['eventattemptdeleted'] = 'Attempt deleted';
$string['eventattemptstarted'] = 'Attempt started';
$string['eventattemptsubmitted'] = 'Attempt finished';
$string['exit'] = 'Exit';
$string['exportfailed'] = 'The export file could not be created.';
$string['exportnothing'] = 'There are no slides to export.';
$string['exportslides'] = 'Export slides';
$string['feedbackeach'] = 'After each answer';
$string['feedbackend'] = 'Only at the end';
$string['feedbackmode'] = 'Feedback';
$string['feedbackmode_help'] = 'When learners find out whether an answer was right.

* **After each answer**: the chosen row shows a tick or a cross at once.
* **Only at the end**: choosing a clip locks it in with no tick or cross, and each slide has only one try. The score is shown at the end.';
$string['goagain'] = 'Go again';
$string['gradeaverage'] = 'Average grade';
$string['gradefirst'] = 'First attempt';
$string['gradehighest'] = 'Highest grade';
$string['gradelast'] = 'Last attempt';
$string['grademethod'] = 'Grading method';
$string['grademethod_help'] = 'When several Test attempts are allowed, which grade goes to the gradebook: the highest, the average, the first or the last attempt.';
$string['importaltmissing'] = 'Alternative text missing: add it here, or tick "The picture is decorative".';
$string['importappend'] = 'Add the new slides after the existing ones';
$string['importappendnote'] = 'The new slides will be added after the {$a} existing slides.';
$string['importbadzip'] = 'The ZIP file could not be opened.';
$string['importcolclips'] = 'Clips and correct answer';
$string['importcolimage'] = 'Picture';
$string['importcolnotes'] = 'Notes';
$string['importcolslide'] = 'Slide';
$string['importconfirm'] = 'Import {$a} slides';
$string['importcorrectforslide'] = 'Correct clip for slide {$a}';
$string['importcorrectnotset'] = 'Not set yet (fix it later on the Edit slides page)';
$string['importdone'] = '{$a} slides were imported.';
$string['importdoneone'] = '1 slide was imported.';
$string['importduplicate'] = 'Another file has the same name.';
$string['importerror_toolarge'] = 'The upload is too large once unpacked: an import may hold at most {$a->size}. Some files were left out.';
$string['importerror_toomanyfiles'] = 'The upload has too many files: an import may hold at most {$a->files} files. Some files were left out.';
$string['importexpired'] = 'The uploaded files are no longer available or have changed. Upload them again.';
$string['importfiles'] = 'Files';
$string['importfiles_help'] = 'Drop in one ZIP file, or many pictures (JPEG, PNG, GIF, WebP) and audio files (MP3, M4A, OGG, WAV) at once. A ZIP may also contain a slides.csv file describing the slides.';
$string['importfiletoolarge'] = 'The file is larger than the upload limit.';
$string['importinclude'] = 'import this slide';
$string['importintro'] = 'Build slides by uploading pictures and audio files. Nothing is saved until you have checked the slides on the next page.

**Matching by file name.** Give each picture a name, and start each of its audio files with the same name, a hyphen and a letter or number:

* <code>01.jpg</code> with <code>01-a.mp3</code>, <code>01-b.mp3</code>, <code>01-c.mp3</code>, <code>01-d.mp3</code>
* mark the correct clip by ending its name with <code>-correct</code> or <code>_x</code>: <code>01-b-correct.mp3</code> or <code>01-b_x.mp3</code>
* an optional spoken prompt for the slide: <code>01-prompt.mp3</code>

Slides are made in the order of the picture names. If no clip is marked correct, you can choose it on the next page.

**Using a manifest.** For exact control, put a <code>slides.csv</code> file in the ZIP. Its first row names the columns: <code>image</code>, <code>alt</code>, <code>decorative</code>, <code>prompt</code>, <code>promptlang</code>, <code>promptaudio</code>, <code>correct</code>, <code>audio1</code> to <code>audio6</code> and <code>transcript1</code> to <code>transcript6</code>. <code>correct</code> is the number of the correct audio column. File paths are relative to <code>slides.csv</code>. The Export slides page produces this format.

Limits: at most {$a->maxfiles} files and {$a->maxslides} slides per import, {$a->maxoptions} clips per slide. Files are checked by their content, not their name.';
$string['importmode'] = 'Existing slides';
$string['importmode_help'] = 'Choose whether the imported slides are added after the slides the activity already has, or replace them. Replacing deletes the existing slides, their pictures and their audio.';
$string['importnoslides'] = 'No slides could be made from these files. Check the file names against the instructions, or add a slides.csv file.';
$string['importnothingchosen'] = 'No slides were chosen, so nothing was imported.';
$string['importnotmedia'] = 'Not an accepted picture or audio file.';
$string['importplayclip'] = 'Slide {$a->slide}: {$a->name}';
$string['importrejected'] = 'Files that were refused';
$string['importreplace'] = 'Replace the existing slides ({$a})';
$string['importreplacewarning'] = 'Importing deletes the {$a} existing slides, with their pictures and audio, and puts these slides in their place.';
$string['importreview'] = 'Upload and check';
$string['importreviewcaption'] = 'Slides to import: {$a}';
$string['importreviewheading'] = 'Check the slides before importing';
$string['importslides'] = 'Import slides';
$string['importsourcefilenames'] = 'The slides were matched by file name.';
$string['importsourcemanifest'] = 'The slides were read from slides.csv.';
$string['importtoolarge'] = 'Left out: the upload limit for one import was reached.';
$string['importtoomanyfiles'] = 'Left out: the file limit for one import was reached.';
$string['importunmatched'] = 'Files not used';
$string['importwarn_badcorrect'] = 'The "correct" column does not name an audio column with a file. Choose the correct clip.';
$string['importwarn_emptymanifest'] = 'slides.csv is empty.';
$string['importwarn_manycorrect'] = 'More than one clip is marked correct. Choose the correct clip.';
$string['importwarn_missingfile'] = 'File not found: {$a}';
$string['importwarn_nocorrect'] = 'No clip is marked correct. Choose the correct clip.';
$string['importwarn_noimage'] = 'This row has no picture.';
$string['importwarn_noimagecolumn'] = 'slides.csv has no "image" column, so no slides could be read from it.';
$string['importwarn_toofewoptions'] = 'A slide needs at least {$a} clips.';
$string['importwarn_toomanyoptions'] = 'Only the first {$a} clips are used.';
$string['importwarn_toomanyslides'] = 'Only the first {$a} slides are imported.';
$string['indexslides'] = 'Slides';
$string['invalidmediafile'] = 'This file is not an accepted image (JPEG, PNG, GIF, WebP) or audio file (MP3, M4A, OGG, WAV).';
$string['lastresult'] = 'Last result';
$string['mode'] = 'Mode';
$string['mode_help'] = 'In **Practice** mode learners rehearse: they keep trying until they find the right clip, and there is no grade. In **Test** mode the activity ends after the last slide with a score that goes to the gradebook.';
$string['modepractice'] = 'Practice';
$string['modeswitchwarning'] = 'Switching to Practice deletes this activity\'s grade item and every grade in it. Test attempts are kept, and switching back to Test recalculates the grades from them.';
$string['modetest'] = 'Test';
$string['modulename'] = 'Listening practice';
$string['modulename_help'] = 'Listening practice (whakaharatau) shows a picture and a set of audio clips. Learners listen to the clips and choose the one that matches the picture.

Teachers build the activity by uploading images and audio files. It can be used for practice, which repeats as often as the teacher chooses, or as a graded test.

The activity depends on listening, unless the teacher shows the transcripts before answering.';
$string['modulenameplural'] = 'Listening practice activities';
$string['moveslide'] = 'Move slide {$a}';
$string['needtwooptions'] = 'A slide needs at least {$a} options with audio.';
$string['nextavailable'] = '"{$a}" button available.';
$string['nextround'] = 'Next round';
$string['nextslide'] = 'Next slide';
$string['noimage'] = 'No picture';
$string['nomissedslides'] = 'There are no missed slides to practise.';
$string['nomoreattempts'] = 'You have no attempts left.';
$string['noslidesyet'] = 'This activity has no slides yet. Add slides one by one, or import a set of pictures and audio files.';
$string['notcurrentslide'] = 'This slide cannot be answered now. Reload the page to continue.';
$string['notplayable'] = 'This activity is not ready yet: it has no slides, or a slide still needs fixing.';
$string['optionalreadychosen'] = 'You have already chosen this option.';
$string['optionaudio'] = 'Audio';
$string['optionaudio_help'] = 'The audio clip for this option: MP3, M4A, OGG or WAV. To remove an option, delete its audio file and save.';
$string['optionaudion'] = 'Audio for option {$a}';
$string['optionchosen'] = 'Option {$a->number}: chosen';
$string['optioncorrect'] = 'Option {$a->number}: correct';
$string['optioncount'] = '{$a} options';
$string['optionn'] = 'Option {$a}';
$string['optionorder'] = 'Order of options';
$string['optionorder_help'] = 'Whether the audio options of each slide appear in the order the teacher set, or in a new random order each time the slide is shown.';
$string['optionorderfixed'] = 'Fixed';
$string['optionorderrandom'] = 'Random each time';
$string['optionrevealed'] = 'Option {$a->number}: the correct answer';
$string['optionwrong'] = 'Option {$a->number}: not correct';
$string['pauseoption'] = 'Pause option {$a->number} of {$a->count}';
$string['pauseprompt'] = 'Pause the spoken prompt';
$string['playheading'] = 'Mode and play order';
$string['playmodeinorder'] = 'In order';
$string['playmodeonepass'] = 'One pass';
$string['playmoderepeatall'] = 'Repeats until you leave';
$string['playmoderepeatmissed'] = 'Repeats missed slides until all are right';
$string['playmodeshuffled'] = 'Shuffled';
$string['playoption'] = 'Play option {$a->number} of {$a->count}';
$string['playprompt'] = 'Play the spoken prompt';
$string['pluginadministration'] = 'Listening practice administration';
$string['pluginname'] = 'Listening practice';
$string['practicetries'] = 'Tries per slide (Practice)';
$string['practicetries_help'] = 'How many clips a learner may choose on a slide before it is finished in Practice mode. When the tries run out, the correct clip is shown.';
$string['practisemissed'] = 'Practise missed slides';
$string['previewactivity'] = 'Preview';
$string['privacy:attempts'] = 'Attempts and practice sessions';
$string['privacy:metadata:attempt'] = 'One Test attempt or one Practice session of a learner.';
$string['privacy:metadata:attempt:attempt'] = 'The number of the attempt or session for this learner.';
$string['privacy:metadata:attempt:beststreak'] = 'The longest run of slides answered right first time.';
$string['privacy:metadata:attempt:currentslide'] = 'The position of the slide the learner is on.';
$string['privacy:metadata:attempt:currentstreak'] = 'The current run of slides answered right first time.';
$string['privacy:metadata:attempt:maxscore'] = 'The maximum score of the attempt.';
$string['privacy:metadata:attempt:mode'] = 'Whether the attempt was made in Practice or Test mode.';
$string['privacy:metadata:attempt:optionorder'] = 'The order in which the audio options of each slide were shown.';
$string['privacy:metadata:attempt:round'] = 'The round the learner is on.';
$string['privacy:metadata:attempt:score'] = 'The score of the attempt.';
$string['privacy:metadata:attempt:slideorder'] = 'The order in which the slides were shown.';
$string['privacy:metadata:attempt:state'] = 'Whether the attempt is in progress or finished.';
$string['privacy:metadata:attempt:timefinish'] = 'The time the attempt was finished.';
$string['privacy:metadata:attempt:timemodified'] = 'The time the attempt was last changed.';
$string['privacy:metadata:attempt:timestart'] = 'The time the attempt was started.';
$string['privacy:metadata:attempt:userid'] = 'The learner who made the attempt.';
$string['privacy:metadata:core_grades'] = 'Test grades are stored in the gradebook.';
$string['privacy:metadata:response'] = 'One audio option a learner chose on a slide.';
$string['privacy:metadata:response:attemptid'] = 'The attempt the choice belongs to.';
$string['privacy:metadata:response:correct'] = 'Whether the chosen option was correct.';
$string['privacy:metadata:response:optionid'] = 'The option that was chosen.';
$string['privacy:metadata:response:round'] = 'The round in which the choice was made.';
$string['privacy:metadata:response:slideid'] = 'The slide on which the choice was made.';
$string['privacy:metadata:response:timeanswered'] = 'The time the choice was made.';
$string['privacy:metadata:response:tries'] = 'Which try on the slide this choice was.';
$string['problem_manycorrect'] = 'More than one option is marked correct.';
$string['problem_noalttext'] = 'The image has no alternative text and is not marked as decorative.';
$string['problem_noaudio'] = 'An option has no audio file.';
$string['problem_nocorrect'] = 'No option is marked correct.';
$string['problem_noimage'] = 'The slide has no image.';
$string['problem_toofewoptions'] = 'The slide needs at least two options.';
$string['problem_toomanyoptions'] = 'The slide has more than six options.';
$string['prompt'] = 'Prompt';
$string['prompt_help'] = 'Optional short text shown with the picture, for example a question. Leave it empty to show only the picture.';
$string['promptaudio'] = 'Prompt audio';
$string['promptaudio_help'] = 'An optional spoken instruction or question for this slide (MP3, M4A, OGG or WAV).';
$string['promptlang'] = 'Prompt language';
$string['promptlang_en'] = 'English (en)';
$string['promptlang_help'] = 'The language of the prompt text, so screen readers pronounce it correctly, for example te reo Māori.';
$string['promptlang_mi'] = 'Te reo Māori (mi)';
$string['promptlang_none'] = 'Same as the page';
$string['reorderhelp'] = 'Drag a slide by its move handle to change the order, or activate the handle with the keyboard to choose a new place.';
$string['repeatall'] = 'All';
$string['repeatmissed'] = 'Missed only';
$string['repeatmode'] = 'Repeat';
$string['repeatmode_help'] = 'What happens after the last slide in Practice mode (a Test always ends after one pass).

* **Off**: one pass, then the end screen.
* **All**: the slides start again (reshuffled if Shuffle is on) until the learner leaves.
* **Missed only**: the next round has only the slides not answered right first time, until every slide has been answered right first time.';
$string['repeatoff'] = 'Off';
$string['report'] = 'Report';
$string['report_alttext'] = 'Image description';
$string['report_appearances'] = 'Times answered';
$string['report_attempt'] = 'Attempt';
$string['report_beststreak'] = 'Best streak';
$string['report_choices'] = 'Clips chosen';
$string['report_decorative'] = 'Decorative image';
$string['report_download'] = 'Download table data as';
$string['report_firsttry'] = 'Right first time';
$string['report_firsttryrate'] = 'Right first time (%)';
$string['report_grade'] = 'Grade';
$string['report_image'] = 'Image';
$string['report_lastactivity'] = 'Last activity';
$string['report_learner'] = 'Learner';
$string['report_modelabel'] = 'Attempts to show';
$string['report_modepractice'] = 'Practice sessions';
$string['report_modetest'] = 'Test attempts';
$string['report_noattempts'] = 'There are no attempts yet.';
$string['report_noslides'] = 'This activity has no slides yet.';
$string['report_notingroup'] = 'You are not a member of any group, so there are no learners to show.';
$string['report_percent'] = 'Percentage';
$string['report_percentfirsttry'] = 'Correct on the first try';
$string['report_rounds'] = 'Rounds';
$string['report_score'] = 'Score';
$string['report_scoreof'] = '{$a->score} / {$a->max}';
$string['report_select'] = 'Select';
$string['report_selectattempt'] = 'Select attempt {$a->attempt} of {$a->name}';
$string['report_session'] = 'Session';
$string['report_slide'] = 'Slide';
$string['report_slidesanswered'] = 'Slides answered';
$string['report_slidescaption'] = 'How often each slide was answered correctly on the first try';
$string['report_started'] = 'Started';
$string['report_state'] = 'State';
$string['report_timefinish'] = 'Finished';
$string['report_viewattempts'] = 'Learners';
$string['report_viewlabel'] = 'Report view';
$string['report_viewslides'] = 'Slides';
$string['resetattempts'] = 'Delete all attempts and practice sessions';
$string['resultheading'] = 'Your result';
$string['reviewanswers'] = 'Review answers';
$string['reviewcorrect'] = 'Option {$a}: your choice, correct';
$string['reviewheading'] = 'Review answers';
$string['reviewrevealed'] = 'Option {$a}: the correct answer';
$string['reviewslide'] = 'Slide {$a}';
$string['reviewslideround'] = 'Round {$a->round}, slide {$a->number}';
$string['reviewwrong'] = 'Option {$a}: your choice, not correct';
$string['roundagain'] = 'Here we go again.';
$string['roundlabel'] = 'Round {$a}';
$string['roundmissed'] = 'The {$a} slides you missed.';
$string['roundmissedone'] = 'The slide you missed.';
$string['saveslide'] = 'Save slide';
$string['scorelabel'] = 'You scored {$a->score} out of {$a->max} ({$a->percent}).';
$string['scoring'] = 'Scoring';
$string['scoring_help'] = 'How a Test attempt is scored. **By correct answers** gives a point for each slide answered correctly. **By slides completed** gives a point for each slide finished, right or wrong, as the original SCORM package did.';
$string['scoringcompleted'] = 'By slides completed';
$string['scoringcorrect'] = 'By correct answers';
$string['scoringheading'] = 'Test scoring';
$string['seeresults'] = 'See your results';
$string['showprogress'] = 'Show progress bar';
$string['showprogress_help'] = 'If enabled, learners see how far through the slides they are, for example "Slide 7 of 20".';
$string['shuffle'] = 'Shuffle';
$string['shuffle_help'] = 'If enabled, the slides are played in a new random order on each pass. Otherwise they are played in the order set on the Edit slides page. Learners cannot change this.';
$string['slidedeleted'] = 'The slide was deleted.';
$string['slideimage'] = 'Picture';
$string['slideimage_help'] = 'The picture learners match the audio to: JPEG, PNG, GIF or WebP.';
$string['slidelabel'] = 'Slide {$a->number} of {$a->count}';
$string['slidelabelround'] = 'Round {$a->round}: slide {$a->number} of {$a->count}';
$string['slidelist'] = 'Slides';
$string['slidemoved'] = 'The slide was moved.';
$string['slidenumber'] = 'Slide {$a}';
$string['slidepicture'] = 'Picture';
$string['slideprompt'] = 'Prompt';
$string['slidesaved'] = 'The slide was saved.';
$string['slidescore'] = 'Slide score';
$string['slidescore_help'] = 'With **First try only**, a slide scores a point only when the first clip chosen is correct. With **Partial credit**, a slide answered correctly after wrong tries still scores part of a point: less for each wrong try.';
$string['slidescorefirsttry'] = 'First try only';
$string['slidescorepartial'] = 'Partial credit for each wrong try';
$string['slidesneedfixing'] = 'Learners cannot start this activity yet: {$a} slides need fixing (see below).';
$string['slidesneedfixingone'] = 'Learners cannot start this activity yet: 1 slide needs fixing (see below).';
$string['start'] = 'Start';
$string['startagain'] = 'Start again';
$string['startbestscore'] = 'Best score so far: {$a}%';
$string['startfacts'] = '{$a->slides} · {$a->mode}';
$string['startlastscore'] = 'Last score: {$a}%';
$string['startlastsession'] = 'Last session: {$a->right} of {$a->played} right first time';
$string['startround'] = 'Start the round';
$string['startslidecount'] = '{$a} slides';
$string['startslidecountone'] = '1 slide';
$string['starttestattemptleft'] = 'Test, 1 attempt left';
$string['starttestattemptsleft'] = 'Test, {$a} attempts left';
$string['statbeststreak'] = 'Best streak';
$string['statefinished'] = 'Finished';
$string['stateinprogress'] = 'In progress';
$string['statfirsttry'] = 'Right first time';
$string['statrounds'] = 'Rounds';
$string['statslidesplayed'] = 'Slides played';
$string['summaryannouncement'] = 'Session summary: {$a->right} of {$a->played} slides right first time.';
$string['summaryheading'] = 'Session summary';
$string['testonlynote'] = 'These settings apply only in Test mode.';
$string['testtries'] = 'Tries per slide (Test)';
$string['testtries_help'] = 'How many clips a learner may choose on a slide in a Test before it is finished. With "Feedback: Only at the end", each slide always has one try.';
$string['thumbcorrect'] = 'Slide {$a}: correct';
$string['thumbwrong'] = 'Slide {$a}: not correct';
$string['transcript'] = 'Transcript';
$string['transcript_help'] = 'Optional text of what the clip says. It is shown after the slide is answered, and before answering if the activity shows transcripts before answering, for Deaf and hard-of-hearing learners.';
$string['transcriptn'] = 'Transcript of option {$a}';
$string['transcriptsbefore'] = 'Show transcripts before answering';
$string['transcriptsbefore_help'] = 'If enabled, each clip\'s transcript is shown before the learner answers, so the activity can be done by reading (for example by Deaf or hard-of-hearing learners). Otherwise transcripts are shown once the slide is finished.';
$string['triesleft'] = '{$a} tries left.';
$string['triesleftone'] = '1 try left.';
$string['triesperslide'] = 'Tries per slide';
$string['triesperslide_help'] = 'How many clips a learner may choose on a slide before the slide is finished. When the tries run out, the correct clip is shown.';
$string['triesuntilcorrect'] = 'Until correct';
$string['tryagain'] = 'Try again';
$string['tryanother'] = 'Try another option.';
$string['whakaharatau:addinstance'] = 'Add a new Listening practice activity';
$string['whakaharatau:attempt'] = 'Attempt Listening practice activities';
$string['whakaharatau:deleteattempts'] = 'Delete attempts';
$string['whakaharatau:manage'] = 'Edit and import slides';
$string['whakaharatau:view'] = 'View Listening practice activities';
$string['whakaharatau:viewreports'] = 'View reports';
