<?php
require_once('../../config.php');
require_once('lib.php');
require_once('locallib.php'); // <-- new file for logic


$id = optional_param('id', 0, PARAM_INT); // course_module ID
$n  = optional_param('n', 0, PARAM_INT);  // digitaleval instance ID
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);


global $DB, $USER;


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
$fs = get_file_storage();


$PAGE->set_url('/mod/digitaleval/view.php', ['id'=>$cm->id]);
$PAGE->set_title(format_string($digitaleval->name));
$PAGE->set_heading(format_string($course->fullname));


echo $OUTPUT->header();


if (!empty($digitaleval->intro)) {
   echo $OUTPUT->box(format_module_intro('digitaleval', $digitaleval, $cm->id), 'generalbox mod_introbox', 'intro');
}


$can_submit = has_capability('mod/digitaleval:submit', $context);
$can_grade  = has_capability('mod/digitaleval:grade', $context);


$grader = new digitaleval_grader($context, $cm, $course, $digitaleval);


// --------------------
// Handle student submission (same as before)
// --------------------
if ($can_submit && data_submitted() && !empty($_FILES['answers']['name'][0])) {
   require_sesskey();
   $grader->handle_student_submission($USER, $_FILES);
   echo $OUTPUT->notification(get_string('submitted', 'mod_digitaleval'), 'notifysuccess');
}


// --------------------
// Handle teacher grading submission
// --------------------
if ($can_grade && optional_param('savegrade', '', PARAM_RAW)) {
   require_sesskey();
   $grader->handle_save_grade(optional_param('submissionid', 0, PARAM_INT), optional_param('grade', 0, PARAM_FLOAT), $USER);
   echo $OUTPUT->notification('Grade saved', 'notifysuccess');
}


// --------------------
// Render view
// --------------------
if ($can_grade) {
   // teacher view
   if ($action === 'grade' && $userid) {
       echo $grader->render_grade_page($userid);
   } else {
       echo $grader->render_overview();
   }
} else if ($can_submit) {
   // student view
   echo $grader->render_student_submission_page($USER);
} else {
   echo $OUTPUT->notification('You do not have permission to view this activity.', 'notifyproblem');
}


echo $OUTPUT->footer();