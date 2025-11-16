namespace mod_digitaleval\external;

defined('MOODLE_INTERNAL') || die();

use external_function_parameters;
use external_single_structure;
use external_value;
use external_api;
use context_module;
use html_writer;
use moodle_url;

class get_next_submission extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID')
        ]);
    }

    public static function execute($cmid) {
        global $DB, $OUTPUT;

        $cm = get_coursemodule_from_id('digitaleval', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cmid);
        self::validate_context($context);
        require_capability('mod/digitaleval:grade', $context);

        $digitaleval = $DB->get_record('digitaleval', ['id' => $cm->instance], '*', MUST_EXIST);
        $next = $DB->get_record_select('digitaleval_submissions',
            'digitalevalid = ? AND grade IS NULL',
            [$digitaleval->id],
            '*',
            IGNORE_MULTIPLE
        );

        if (!$next) {
            $html = $OUTPUT->notification(get_string('allgraded', 'mod_digitaleval'), 'notifysuccess');
        } else {
            $user = $DB->get_record('user', ['id' => $next->userid]);
            $url = new moodle_url('/mod/digitaleval/view.php', ['id' => $cmid, 'action' => 'grade', 'userid' => $user->id]);
            $html = html_writer::tag('h4', fullname($user)) .
                    html_writer::link($url, get_string('grading', 'mod_digitaleval'));
        }

        return ['html' => $html];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered HTML for the grading panel')
        ]);
    }
}