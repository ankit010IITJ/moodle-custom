<?php
// grade.php - grading interface for a single submission
require_once('../../config.php');
require_once('lib.php');

$id = optional_param('id', 0, PARAM_INT); // course_module ID
$n  = optional_param('n', 0, PARAM_INT);  // digitaleval instance ID
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA); // save, savenext, prev, next

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

// Fetch all submissions for this activity
$subs = $DB->get_records('digitaleval_submissions', ['digitalevalid' => $digitaleval->id], 'id ASC');
if (empty($subs)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notsubmitted', 'mod_digitaleval'), 'notifywarning');
    echo $OUTPUT->footer();
    exit;
}

// Build ordered array of IDs
$ordered = array_keys($subs);

// Determine current index
if ($submissionid) {
    if (!array_key_exists($submissionid, $subs)) {
        print_error('invalidsubmission', 'mod_digitaleval');
    }
    $curindex = array_search($submissionid, $ordered);
} else {
    $curindex = 0;
    $submissionid = $ordered[0];
}

$curid = $ordered[$curindex];
$submission = $DB->get_record('digitaleval_submissions', ['id' => $curid], '*', MUST_EXIST);
$student = $DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST);

// ✅ Handle POST save actions early (before any output)
if (data_submitted() && confirm_sesskey()) {
    $posted_grade = optional_param('grade', null, PARAM_RAW);
    $posted_feedback = optional_param('feedback', null, PARAM_TEXT);
    $gradeval = ($posted_grade === null || $posted_grade === '') ? null : (float)$posted_grade;

    $submission->grade = $gradeval;
    $submission->grader = $USER->id;
    $submission->graded = time();
    if ($posted_feedback !== null) {
        $submission->feedback = $posted_feedback;
    }

    try {
        $DB->update_record('digitaleval_submissions', $submission);
        // Refresh submission to reflect latest grade and feedback
        // $submission = $DB->get_record('digitaleval_submissions', ['id' => $submission->id], '*', MUST_EXIST);

        $submission = $DB->get_record('digitaleval_submissions', ['id' => $curid], '*', MUST_EXIST);

        // Force Moodle to recognize old grades (don’t treat null as 0)
        if ($submission->grade === null) {
            $submission->grade = '';
        }


        // Prepare grade data and include course ID
        // $grades = [];
        // $grades[$submission->userid] = (object)[
        //     'userid' => $submission->userid,
        //     'rawgrade' => $gradeval
        // ];

        // $gradeitem = (object)[
        //     'id' => $digitaleval->id,
        //     'course' => $course->id
        // ];

        $grades = [];
        $grades[$submission->userid] = (object)[
            'userid' => $submission->userid,
            'rawgrade' => $gradeval
        ];

        $gradeitem = (object)[
            'id' => $digitaleval->id,
            'course' => $course->id,
            'name' => $digitaleval->name
        ];

        digitaleval_grade_item_update($gradeitem, $grades);

    } catch (dml_exception $e) {
        redirect(
            new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submission->id]),
            get_string('couldnotsave', 'mod_digitaleval') . ' - ' . s($e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // ✅ Redirect logic before any output
    if ($action === 'savenext' && $curindex + 1 < count($ordered)) {
        redirect(
            new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $ordered[$curindex + 1]]),
            get_string('gradesaved', 'mod_digitaleval'),
            1,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($action === 'next' && $curindex + 1 < count($ordered)) {
        redirect(new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $ordered[$curindex + 1]]));
    } else if ($action === 'prev' && $curindex - 1 >= 0) {
        redirect(new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $ordered[$curindex - 1]]));
    } else if ($action !== '') {
        redirect(new moodle_url('/mod/digitaleval/view.php', ['id' => $cm->id]));
    } else {
        redirect(new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $curid]));
    }

    exit; // stop further page output after redirect
}

// ✅ Now start page output (after redirects handled)

debugging('Current grade loaded: ' . var_export($submission->grade, true), DEBUG_DEVELOPER);

$PAGE->set_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$PAGE->set_title(get_string('gradeforstudent', 'mod_digitaleval', ''));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('gradingstudent', 'mod_digitaleval') . ': ' . fullname($student));

// Show submitted files
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $submission->id, 'sortorder, id', false);

if (empty($files)) {
    echo $OUTPUT->notification(get_string('nofiles', 'mod_digitaleval'), 'notifywarning');
} else {
    echo html_writer::start_div('digitaleval-grading-wrap');

    // File list sidebar
    echo html_writer::start_div('digitaleval-files-sidebar');
    echo html_writer::tag('h4', get_string('files', 'mod_digitaleval'));
    echo html_writer::start_tag('ul');
    foreach ($files as $f) {
        $url = moodle_url::make_pluginfile_url(
            $f->get_contextid(),
            $f->get_component(),
            $f->get_filearea(),
            $f->get_itemid(),
            $f->get_filepath(),
            $f->get_filename()
        );
        $link = html_writer::link($url, $f->get_filename(), [
            'target' => '_blank',
            'data-fileurl' => $url->out(false),
            'class' => 'digitaleval-filelink'
        ]);
        echo html_writer::tag('li', $link);
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_div(); // sidebar

    // Preview area
    echo html_writer::start_div('digitaleval-preview-area');
    $firstfile = reset($files);
    $mimetype = $firstfile->get_mimetype();
    $fileurl = moodle_url::make_pluginfile_url(
        $firstfile->get_contextid(),
        $firstfile->get_component(),
        $firstfile->get_filearea(),
        $firstfile->get_itemid(),
        $firstfile->get_filepath(),
        $firstfile->get_filename()
    )->out(false);

    if (strpos($mimetype, 'pdf') !== false) {
        echo html_writer::div('<iframe src="' . $fileurl . '" style="width:100%;height:700px;border:0;"></iframe>', 'digitaleval-iframe-wrap');
    } else if (strpos($mimetype, 'image/') === 0) {
        echo html_writer::div(html_writer::empty_tag('img', ['src' => $fileurl, 'style' => 'max-width:100%;max-height:700px;']), 'digitaleval-image-wrap');
    } else {
        echo html_writer::div(
            html_writer::link($fileurl, get_string('downloadfile', 'mod_digitaleval')) .
            html_writer::tag('p', get_string('filecannotpreview', 'mod_digitaleval')),
            'digitaleval-filelink-wrap'
        );
    }
    echo html_writer::end_div(); // preview
    echo html_writer::end_div(); // wrap
}

// Grading form
// $gradeval = isset($submission->grade) ? $submission->grade : '';
// $gradeval = ($submission->grade !== null && $submission->grade !== '') ? s($submission->grade) : '';

$gradeval = ($submission->grade !== null) ? s($submission->grade) : '';

$feedbackval = isset($submission->feedback) ? $submission->feedback : '';

// Optional message
if (!empty($submission->graded)) {
    echo $OUTPUT->notification('This submission was graded on ' . userdate($submission->graded) . '. You can modify the grade below.', 'info');
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submission->id]);

echo html_writer::start_div('digitaleval-gradeform');
echo html_writer::label(get_string('grade', 'mod_digitaleval') . ': ', 'id_grade');
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'grade', 'id' => 'id_grade',
    'min' => '0', 'max' => '100', 'step' => '0.01', 'value' => s($gradeval)
]);
echo html_writer::empty_tag('br');

echo html_writer::label(get_string('feedback', 'mod_digitaleval') . ': ', 'id_feedback');
echo html_writer::tag('br', '');
echo html_writer::tag('textarea', s($feedbackval), [
    'name' => 'feedback', 'id' => 'id_feedback', 'rows' => 6, 'cols' => 70
]);
echo html_writer::empty_tag('br');

// Buttons
$savebtn     = html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'action', 'value' => 'save']);
$savenextbtn = html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'action', 'value' => 'savenext']);
$prevbtn     = $OUTPUT->single_button(
    new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submission->id, 'action' => 'prev']),
    get_string('prev')
);
$nextbtn     = $OUTPUT->single_button(
    new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submission->id, 'action' => 'next']),
    get_string('next')
);

echo html_writer::div($savebtn . ' ' . $savenextbtn . ' ' . $prevbtn . ' ' . $nextbtn, 'digitaleval-grade-actions');
echo html_writer::end_div(); // gradeform
echo html_writer::end_tag('form');

// Basic JS to allow inline preview switching
$js = "
require(['jquery'], function($){
    $('.digitaleval-filelink').on('click', function(e){
        var href = $(this).data('fileurl') || $(this).attr('href');
        var ext = href.split('.').pop().toLowerCase();
        if (ext === 'pdf') {
            e.preventDefault();
            $('.digitaleval-preview-area').html('<iframe src=\"' + href + '\" style=\"width:100%;height:700px;border:0;\"></iframe>');
        } else if (['png','jpg','jpeg','gif','bmp','webp'].indexOf(ext) !== -1) {
            e.preventDefault();
            $('.digitaleval-preview-area').html('<img src=\"' + href + '\" style=\"max-width:100%;max-height:700px;\" />');
        }
    });
});
";
$PAGE->requires->js_init_code($js);

echo $OUTPUT->footer();
