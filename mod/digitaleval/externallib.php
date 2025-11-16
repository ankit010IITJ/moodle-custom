<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/locallib.php');

class mod_digitaleval_external extends external_api {

    public static function get_next_submission_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function get_next_submission($cmid) {
        global $DB, $PAGE;

        $params = self::validate_parameters(
            self::get_next_submission_parameters(),
            ['cmid' => $cmid]
        );

        $cm = get_coursemodule_from_id('digitaleval', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        require_capability('mod/digitaleval:grade', $context);

        // Required for renderer inside AJAX
        $PAGE->set_context($context);
        $PAGE->set_url('/mod/digitaleval/ajax.php');
        $PAGE->set_pagelayout('ajax');

        $digitaleval = $DB->get_record('digitaleval', ['id' => $cm->instance], '*', MUST_EXIST);
        $course      = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $grader = new digitaleval_grader($context, $cm, $course, $digitaleval);

        // Produce HTML for the modal
        $html = $grader->render_all_students_grading();

        return $html; // <---- IMPORTANT: return plain HTML string
    }

    public static function get_next_submission_returns() {
        return new external_value(PARAM_RAW, 'HTML content of grading UI');
    }
}