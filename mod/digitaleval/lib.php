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

function digitaleval_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();

    // Ensure course is set correctly.
    if (empty($data->course)) {
        debugging('Missing course ID in digitaleval_add_instance');
        return false;
    }

    // Insert the main record.
    $data->id = $DB->insert_record('digitaleval', $data);

    // Get context.
    $cmid = $data->coursemodule;
    $context = context_module::instance($cmid);

    // Save uploaded question paper (prof upload).
    if (!empty($mform)) {
        $draftid = file_get_submitted_draft_itemid('questionfile');
        file_save_draft_area_files(
            $draftid,
            $context->id,
            'mod_digitaleval',
            'questionfile',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
    }

    // Create grade item for this instance.
    digitaleval_grade_item_update($data);

    return $data->id;
}

function digitaleval_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('digitaleval', $data);

    // Get context.
    $cmid = $data->coursemodule;
    $context = context_module::instance($cmid);

    // Update uploaded question paper.
    if (!empty($mform)) {
        $draftid = file_get_submitted_draft_itemid('questionfile');
        file_save_draft_area_files(
            $draftid,
            $context->id,
            'mod_digitaleval',
            'questionfile',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
    }

    // Update grade item.
    digitaleval_grade_item_update($data);

    return true;
}

function digitaleval_delete_instance($id) {
    global $DB;

    if (!$digitaleval = $DB->get_record('digitaleval', ['id' => $id])) {
        return false;
    }

    // Delete all submissions
    $DB->delete_records('digitaleval_submissions', ['digitalevalid' => $id]);
    $DB->delete_records('digitaleval', ['id' => $id]);

    // Delete grade item
    digitaleval_grade_item_delete($id);

    return true;
}

function digitaleval_user_outline($course, $user, $mod, $digitaleval) {
    return new stdClass();
}

function digitaleval_grade_item_update($digitaleval, $grades = null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if (empty($digitaleval->course)) {
        debugging('Missing course ID in digitaleval_grade_item_update');
        return false;
    }

    $params = [
        'itemname'  => $digitaleval->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => 100,
        'grademin'  => 0
    ];

    return grade_update(
        'mod/digitaleval',
        $digitaleval->course,
        'mod',
        'digitaleval',
        $digitaleval->id,
        0,
        $grades,
        $params
    );
}

function digitaleval_grade_item_delete($instanceid) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    grade_update('mod/digitaleval', $instanceid, 'mod', 'digitaleval', $instanceid, 0, null, ['deleted' => 1]);
}

/**
 * Return file areas for submissions
 */
function digitaleval_get_submission_file_areas($course, $cm, $context) {
    // three fileareas: question file, student uploads, and generated (OCR) outputs
    return [
        'questionfile' => get_string('questionfile', 'mod_digitaleval'),
        'submission'   => get_string('submission', 'mod_digitaleval'),
        'generated'    => get_string('generated', 'mod_digitaleval')
    ];
}

/**
 * Serve files from questionfile, submission, and generated areas
 */
function digitaleval_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    // Allow 'questionfile', 'submission', and 'generated' areas
    if (!in_array($filearea, ['questionfile', 'submission', 'generated'])) {
        send_file_not_found();
    }

    $itemid = array_shift($args);
    if ($filearea !== 'questionfile' && !$itemid) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = '/' . $context->id . '/mod_digitaleval/' . $filearea . '/' . ($itemid ?? 0) . '/' . $relativepath;

    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    // Access checks:
    if ($filearea === 'questionfile') {
        // Teachers and students can view the question file
        if (!has_capability('mod/digitaleval:view', $context) && !has_capability('mod/digitaleval:grade', $context)) {
            send_file_not_found();
        }
    } else {
        // For submission/generated areas
        $canview = false;
        if (has_capability('mod/digitaleval:grade', $context)) {
            $canview = true;
        } else {
            $submission = $DB->get_record('digitaleval_submissions', ['id' => $itemid]);
            if ($submission && $submission->userid == $USER->id) {
                $canview = true;
            }
        }

        if (!$canview) {
            send_file_not_found();
        }
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
?>