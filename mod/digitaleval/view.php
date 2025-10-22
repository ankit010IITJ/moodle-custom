<?php
require_once('../../config.php');
require_once('lib.php');

$id = optional_param('id', 0, PARAM_INT); // course_module ID
$n  = optional_param('n', 0, PARAM_INT);  // digitaleval instance ID

// Get course, cm, and instance
if ($id) {
    $cm = get_coursemodule_from_id('digitaleval', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $digitaleval = $DB->get_record('digitaleval', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($n) {
    $digitaleval = $DB->get_record('digitaleval', ['id' => $n], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $digitaleval->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digitaleval', $digitaleval->id, $course->id, false, MUST_EXIST);
} else {
    print_error('missingparameter');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$fs = get_file_storage(); // Initialize file storage once

$PAGE->set_url('/mod/digitaleval/view.php', ['id'=>$cm->id]);
$PAGE->set_title(format_string($digitaleval->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

// Display intro
if (!empty($digitaleval->intro)) {
    echo $OUTPUT->box(format_module_intro('digitaleval', $digitaleval, $cm->id), 'generalbox mod_introbox', 'intro');
}

// Capabilities
$can_submit = has_capability('mod/digitaleval:submit', $context);
$can_grade  = has_capability('mod/digitaleval:grade', $context);

// -------------------
// Handle student submission
// -------------------
if ($can_submit && data_submitted() && !empty($_FILES['answers']['name'][0])) {
    if (!confirm_sesskey()) {
        print_error('sesskeyinvalid', 'error');
    }

    $submission = new stdClass();
    $submission->digitalevalid = $digitaleval->id;
    $submission->userid = $USER->id;
    $submission->timecreated = time();
    $submission->timemodified = time();
    $submission->status = 'submitted';
    $subid = $DB->insert_record('digitaleval_submissions', $submission);

    // store files
    $fs = get_file_storage();
    $context = context_module::instance($cm->id);
    for ($i=0; $i < count($_FILES['answers']['name']); $i++) {
        if ($_FILES['answers']['error'][$i] === UPLOAD_ERR_OK) {
            $fileinfo = [
                'contextid' => $context->id,
                'component' => 'mod_digitaleval',
                'filearea'  => 'submission',
                'itemid'    => $subid,
                'filepath'  => '/',
                'filename'  => $_FILES['answers']['name'][$i]
            ];
            $fs->create_file_from_pathname($fileinfo, $_FILES['answers']['tmp_name'][$i]);
        }
    }

    echo $OUTPUT->notification(get_string('submitted', 'mod_digitaleval'), 'notifysuccess');
}

// -------------------
// Display student submission form
// -------------------
if ($can_submit) {
    $existing = $DB->get_records('digitaleval_submissions', ['digitalevalid'=>$digitaleval->id, 'userid'=>$USER->id]);
    if (empty($existing)) {
        echo html_writer::tag('h3', get_string('submitanswersheet','mod_digitaleval'));
        echo html_writer::start_tag('form', ['method'=>'post', 'enctype'=>'multipart/form-data']);
        echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()]);
        echo html_writer::empty_tag('input', ['type'=>'file', 'name'=>'answers[]', 'multiple'=>'multiple']);
        echo html_writer::empty_tag('br');
        echo html_writer::empty_tag('br');
        echo html_writer::empty_tag('input', ['type'=>'submit', 'value'=>get_string('submitanswersheet','mod_digitaleval')]);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::tag('p', get_string('submitted', 'mod_digitaleval'));
        foreach ($existing as $sub) {
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $sub->id, 'id', false);
            if ($files) {
                echo html_writer::start_tag('ul');
                foreach ($files as $file) {
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    echo html_writer::tag('li', html_writer::link($url, $file->get_filename()));
                }
                echo html_writer::end_tag('ul');
            }

            // Show grade if available
            if (isset($sub->grade)) {
                echo html_writer::tag('p', get_string('grade','mod_digitaleval').': '.htmlspecialchars($sub->grade));
            } else {
                echo html_writer::tag('p', get_string('notgraded','mod_digitaleval'));
            }
            
        }
    }
}

// -------------------
// Display grading interface for teachers
// -------------------
if ($can_grade) {
    echo html_writer::tag('h3', get_string('gradeforstudent','mod_digitaleval'));
    $subs = $DB->get_records('digitaleval_submissions', ['digitalevalid'=>$digitaleval->id]);
    if (empty($subs)) {
        echo html_writer::tag('p', get_string('notsubmitted','mod_digitaleval'));
    } else {
        echo html_writer::start_tag('table', ['class'=>'generaltable']);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Submission ID');
        echo html_writer::tag('th', 'Student');
        echo html_writer::tag('th', 'Files');
        echo html_writer::tag('th', 'Grade');
        echo html_writer::end_tag('tr');

        foreach ($subs as $s) {
            $user = $DB->get_record('user', ['id'=>$s->userid]);
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', $s->id);
            echo html_writer::tag('td', fullname($user));

            // Files
            $fileshtml = '';
            $files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $s->id, 'id', false);
            if ($files) {
                foreach ($files as $file) {
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    $fileshtml .= html_writer::link($url, $file->get_filename()) . ' ';
                }
            }
            echo html_writer::tag('td', $fileshtml);

            // Inline grading form
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method'=>'post']);
            echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()]);
            echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'submissionid', 'value'=>$s->id]);
            echo html_writer::empty_tag('input', [
                'type'=>'number',
                'name'=>'grade',
                'min'=>'0',
                'max'=>'100',
                'step'=>'0.01',
                'value'=>htmlspecialchars($s->grade)
            ]);
            echo html_writer::empty_tag('input', ['type'=>'submit', 'name'=>'savegrade', 'value'=>'Save']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');

            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }
}

// -------------------
// Handle grading POST
// -------------------
if ($can_grade && data_submitted() && !empty($_POST['savegrade']) && !empty($_POST['submissionid'])) {
    if (!confirm_sesskey()) {
        print_error('sesskeyinvalid', 'error');
    }

    $submissionid = intval($_POST['submissionid']);
    $grade = floatval($_POST['grade']);
    $sub = $DB->get_record('digitaleval_submissions', ['id'=>$submissionid], '*', MUST_EXIST);
    $sub->grade = $grade;
    $sub->grader = $USER->id;
    $sub->graded = time();
    $DB->update_record('digitaleval_submissions', $sub);

    // push to gradebook
    // $grades = new stdClass();
    // $grades->userid = $sub->userid;
    // $grades->rawgrade = $grade;
    // digitaleval_grade_item_update((object)['id'=>$digitaleval->id], [$grades]);

    // push to gradebook
    $grades = [];
    $grades[$sub->userid] = new stdClass();
    $grades[$sub->userid]->userid = $sub->userid;
    $grades[$sub->userid]->rawgrade = $grade;

    digitaleval_grade_item_update((object)['id'=>$digitaleval->id], $grades);


    echo $OUTPUT->notification('Grade saved', 'notifysuccess');
}

echo $OUTPUT->footer();