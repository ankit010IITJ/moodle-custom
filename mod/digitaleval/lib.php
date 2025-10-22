<?php
defined('MOODLE_INTERNAL') || die();

////////////////////////////////////////////////////////////////////////////////
// REQUIRED MODULE FUNCTIONS
////////////////////////////////////////////////////////////////////////////////

/**
 * Returns the features supported by this module
 */
function digitaleval_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_GRADE_HAS_GRADE: return true;
        default: return null;
    }
}

/**
 * Add a new instance of the module
 */
function digitaleval_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $id = $DB->insert_record('digitaleval', $data);

    // create grade item
    $instance = new stdClass();
    $instance->id = $id;
    digitaleval_grade_item_update($instance);

    return $id;
}

/**
 * Update an existing instance
 */
function digitaleval_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('digitaleval', $data);

    $instance = new stdClass();
    $instance->id = $data->id;
    digitaleval_grade_item_update($instance);

    return true;
}

/**
 * Delete an instance of the module
 */
function digitaleval_delete_instance($id) {
    global $DB;

    if (!$digitaleval = $DB->get_record('digitaleval', array('id'=>$id))) {
        return false;
    }

    // delete all submissions
    $DB->delete_records('digitaleval_submissions', array('digitalevalid'=>$id));
    $DB->delete_records('digitaleval', array('id'=>$id));

    // delete grade item
    digitaleval_grade_item_delete($id);

    return true;
}

/**
 * User activity report placeholder
 */
function digitaleval_user_outline($course, $user, $mod, $digitaleval) {
    return new stdClass();
}

////////////////////////////////////////////////////////////////////////////////
// GRADE ITEM FUNCTIONS
////////////////////////////////////////////////////////////////////////////////

/**
 * Update grade item for this instance
 */
function digitaleval_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $params = array(
        'itemname'  => 'digitaleval ' . $instance->id,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => 100,
        'grademin'  => 0
    );

    grade_update('mod/digitaleval', $instance->id, 'mod', 'digitaleval', $instance->id, 0, $grades, $params);
}

/**
 * Delete grade item for this instance
 */
function digitaleval_grade_item_delete($instanceid) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    grade_update('mod/digitaleval', $instanceid, 'mod', 'digitaleval', $instanceid, 0, null, array('deleted'=>1));
}

////////////////////////////////////////////////////////////////////////////////
// FILE API
////////////////////////////////////////////////////////////////////////////////

/**
 * Return file areas for submissions
 */
function digitaleval_get_submission_file_areas($course, $cm, $context) {
    return array('submission' => get_string('submission', 'mod_digitaleval'));
}

/**
 * Serve files from submission area
 */
function digitaleval_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    if ($filearea !== 'submission') {
        send_file_not_found();
    }

    $submissionid = array_shift($args);
    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = '/'.$context->id.'/mod_digitaleval/'.$filearea.'/'.$submissionid.'/'.$relativepath;

    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file) {
        send_file_not_found();
    }

    // Access check: students can view own files, graders can view all
    $canview = false;
    if (has_capability('mod/digitaleval:grade', $context)) {
        $canview = true;
    } else {
        $submission = $DB->get_record('digitaleval_submissions', array('id'=>$submissionid), '*', MUST_EXIST);
        if ($submission->userid == $USER->id) {
            $canview = true;
        }
    }

    if (!$canview) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
