<?php
// grading.php - full-page assignment-like grader interface
require_once('../../config.php');
require_once('lib.php');
require_once('locallib.php');

$id           = optional_param('id', 0, PARAM_INT); // cm id
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$action       = optional_param('action', '', PARAM_ALPHA); // save, saveandnext, next, prev
$confirm      = optional_param('confirm', 0, PARAM_INT);

global $DB, $USER;

if ($id) {
    $cm = get_coursemodule_from_id('digitaleval', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $digitaleval = $DB->get_record('digitaleval', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    print_error('missingparameter');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/digitaleval:grade', $context);

// loader object
$grader = new digitaleval_grader($context, $cm, $course, $digitaleval);

// fetch ordered submissions
$subs = $DB->get_records('digitaleval_submissions', ['digitalevalid' => $digitaleval->id], 'id ASC');
if (empty($subs)) {
    redirect(new moodle_url('/mod/digitaleval/view.php', ['id' => $cm->id]), get_string('notsubmitted', 'mod_digitaleval'), 2);
}

// build ordered id list
$ordered = array_keys($subs);

// determine current submission to show
if ($submissionid) {
    if (!array_key_exists($submissionid, $subs)) {
        print_error('invalidsubmission', 'mod_digitaleval');
    }
    $curindex = array_search($submissionid, $ordered);
} else {
    // if no submission specified, try to find next ungraded
    $curindex = null;
    foreach ($ordered as $idx => $sid) {
        $s = $subs[$sid];
        if ($s->grade === null || $s->grade === '') {
            $curindex = $idx;
            break;
        }
    }
    if ($curindex === null) {
        // fallback to first
        $curindex = 0;
    }
    $submissionid = $ordered[$curindex];
}

$curid = $ordered[$curindex];
$submission = $DB->get_record('digitaleval_submissions', ['id' => $curid], '*', MUST_EXIST);
$student = $DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST);

// Handle POST save BEFORE any output
if (data_submitted() && confirm_sesskey()) {
    $posted_grade = optional_param('grade', null, PARAM_RAW);
    $posted_feedback = optional_param('feedback', null, PARAM_TEXT);

    $gradeval = ($posted_grade === null || $posted_grade === '') ? null : (float)$posted_grade;

    // Save via existing handler to ensure gradebook sync
    $grader->handle_save_grade($submission->id, $gradeval, $USER);

    // Now redirect according to action
    if ($action === 'saveandnext') {
        if ($curindex + 1 < count($ordered)) {
            $nextid = $ordered[$curindex + 1];
            redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $nextid, 'action' => 'grade']), get_string('gradesaved', 'mod_digitaleval'), 0);
        } else {
            // All done
            redirect(new moodle_url('/mod/digitaleval/view.php', ['id' => $cm->id]), get_string('allgraded', 'mod_digitaleval'), 1);
        }
    } else if ($action === 'next') {
        if ($curindex + 1 < count($ordered)) {
            $nextid = $ordered[$curindex + 1];
            redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $nextid]));
        } else {
            redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $curid]));
        }
    } else if ($action === 'prev') {
        if ($curindex - 1 >= 0) {
            $prev = $ordered[$curindex - 1];
            redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $prev]));
        } else {
            redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $curid]));
        }
    } else {
        // default save reload
        redirect(new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $curid]));
    }
    exit;
}

// Page output
$PAGE->set_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $curid]);
$PAGE->set_title(get_string('grading', 'mod_digitaleval') . ': ' . fullname($student));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/digitaleval/styles.css'); // optional, see styles below

echo $OUTPUT->header();

// Header
echo $OUTPUT->heading(get_string('gradingstudent', 'mod_digitaleval') . ': ' . fullname($student));

// two column layout
echo html_writer::start_div('digitaleval-grading-container');

// LEFT: submission viewer
echo html_writer::start_div('digitaleval-submission-panel');
echo html_writer::tag('h4', get_string('submittedfiles', 'mod_digitaleval'));
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $submission->id, 'sortorder, id', false);

if (empty($files)) {
    echo $OUTPUT->notification(get_string('nofiles', 'mod_digitaleval'), 'notifywarning');
} else {
    // show first file preview (pdf or image)
    $first = reset($files);
    $mimetype = $first->get_mimetype();
    $fileurl = moodle_url::make_pluginfile_url(
        $first->get_contextid(),
        $first->get_component(),
        $first->get_filearea(),
        $first->get_itemid(),
        $first->get_filepath(),
        $first->get_filename()
    )->out(false);

    if (strpos($mimetype, 'pdf') !== false) {
        echo html_writer::div('<iframe src="' . $fileurl . '" style="width:100%;height:800px;border:0;"></iframe>', 'digitaleval-iframe-wrap');
    } else if (strpos($mimetype, 'image/') === 0) {
        echo html_writer::div(html_writer::empty_tag('img', ['src' => $fileurl, 'style' => 'max-width:100%;max-height:800px;']), 'digitaleval-image-wrap');
    } else {
        // list files
        foreach ($files as $f) {
            $url = moodle_url::make_pluginfile_url(
                $f->get_contextid(),
                $f->get_component(),
                $f->get_filearea(),
                $f->get_itemid(),
                $f->get_filepath(),
                $f->get_filename()
            );
            echo html_writer::link($url, $f->get_filename(), ['target' => '_blank']) . '<br>';
        }
    }
}
echo html_writer::end_div(); // left

// RIGHT: grading form and navigation
echo html_writer::start_div('digitaleval-grading-panel');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/mod/digitaleval/grading.php', ['id' => $cm->id, 'submissionid' => $curid])]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submission->id]);

// current grade
$gradeval = ($submission->grade !== null) ? s($submission->grade) : '';
echo html_writer::tag('label', get_string('grade', 'mod_digitaleval') . ': ', ['for'=>'id_grade']);
echo html_writer::empty_tag('input', [
    'type'=>'number', 'name'=>'grade', 'id'=>'id_grade',
    'min'=>'0', 'max'=>'100', 'step'=>'0.01', 'value'=>$gradeval
]);
echo html_writer::empty_tag('br');

// feedback
$feedbackval = isset($submission->feedback) ? $submission->feedback : '';
echo html_writer::tag('label', get_string('feedback', 'mod_digitaleval') . ': ', ['for'=>'id_feedback']);
echo html_writer::tag('br', '');
echo html_writer::tag('textarea', s($feedbackval), [
    'name' => 'feedback', 'id' => 'id_feedback', 'rows' => 10, 'cols' => 50
]);
echo html_writer::empty_tag('br');

// action buttons
echo html_writer::empty_tag('input', ['type'=>'submit', 'name'=>'action', 'value'=>'save']);
echo html_writer::empty_tag('input', ['type'=>'submit', 'name'=>'action', 'value'=>'saveandnext']);
echo ' ';

// navigation
$prevurl = ($curindex - 1 >= 0) ? new moodle_url('/mod/digitaleval/grading.php', ['id'=>$cm->id,'submissionid'=>$ordered[$curindex-1]]) : null;
$nexturl = ($curindex + 1 < count($ordered)) ? new moodle_url('/mod/digitaleval/grading.php', ['id'=>$cm->id,'submissionid'=>$ordered[$curindex+1]]) : null;

if ($prevurl) {
    echo html_writer::link($prevurl, get_string('prev'), ['class' => 'btn']);
} else {
    echo html_writer::tag('span', get_string('prev'), ['class'=>'btn disabled']);
}
echo ' ';
if ($nexturl) {
    echo html_writer::link($nexturl, get_string('next'), ['class' => 'btn']);
} else {
    echo html_writer::tag('span', get_string('next'), ['class'=>'btn disabled']);
}

echo html_writer::end_tag('form');
echo html_writer::end_div(); // right

echo html_writer::end_div(); // container

// Small inline JS: allow clicking file links to show in left preview
$js = <<<JS
require(['jquery'], function($){
    $('a[data-file]').on('click', function(e){
        e.preventDefault();
        var href = $(this).attr('href');
        var ext = href.split('.').pop().toLowerCase();
        if (ext === 'pdf') {
            $('.digitaleval-submission-panel .digitaleval-iframe-wrap').remove();
            $('.digitaleval-submission-panel').append('<div class="digitaleval-iframe-wrap"><iframe src="'+href+'" style="width:100%;height:800px;border:0;"></iframe></div>');
        } else if (['png','jpg','jpeg','gif','bmp','webp'].indexOf(ext) !== -1) {
            $('.digitaleval-submission-panel .digitaleval-image-wrap').remove();
            $('.digitaleval-submission-panel').append('<div class="digitaleval-image-wrap"><img src="'+href+'" style="max-width:100%;max-height:800px;" /></div>');
        } else {
            window.open(href, '_blank');
        }
    });
});
JS;
$PAGE->requires->js_init_code($js);

echo $OUTPUT->footer();