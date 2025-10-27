<?php
defined('MOODLE_INTERNAL') || die();

class digitaleval_grader {
    private $context;
    private $cm;
    private $course;
    private $digitaleval;

    public function __construct($context, $cm, $course, $digitaleval) {
        $this->context = $context;
        $this->cm = $cm;
        $this->course = $course;
        $this->digitaleval = $digitaleval;
    }

    // ==============================
    // STUDENT: Submission form/view
    // ==============================
    public function render_student_submission_page($user) {
        global $DB, $OUTPUT, $CFG;

        $context = $this->context;
        $fs = get_file_storage();

        $existing = $DB->get_records('digitaleval_submissions', [
            'digitalevalid' => $this->digitaleval->id,
            'userid' => $user->id
        ]);

        if (empty($existing)) {
            // Upload form
            $html = html_writer::tag('h3', get_string('submitanswersheet','mod_digitaleval'));
            $html .= html_writer::start_tag('form', ['method'=>'post', 'enctype'=>'multipart/form-data']);
            $html .= html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()]);
            $html .= html_writer::empty_tag('input', ['type'=>'file', 'name'=>'answers[]', 'multiple'=>'multiple']);
            $html .= html_writer::empty_tag('br').html_writer::empty_tag('br');
            $html .= html_writer::empty_tag('input', ['type'=>'submit', 'value'=>get_string('submitanswersheet','mod_digitaleval')]);
            $html .= html_writer::end_tag('form');
            return $html;
        }

        // Show submitted files + grade
        $html = html_writer::tag('p', get_string('submitted', 'mod_digitaleval'));
        foreach ($existing as $sub) {
            $files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $sub->id, 'id', false);
            if ($files) {
                $html .= html_writer::start_tag('ul');
                foreach ($files as $file) {
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    $html .= html_writer::tag('li', html_writer::link($url, $file->get_filename()));
                }
                $html .= html_writer::end_tag('ul');
            }
            if (isset($sub->grade)) {
                $html .= html_writer::tag('p', 'Grade: '.htmlspecialchars($sub->grade));
            } else {
                $html .= html_writer::tag('p', 'Not graded yet.');
            }
        }

        return $html;
    }

    // ==============================
    // TEACHER: Submission overview
    // ==============================
    public function render_overview() {
        global $DB, $OUTPUT, $CFG;

        $students = get_enrolled_users($this->context, 'mod/digitaleval:submit');
        $submitted = $DB->get_records('digitaleval_submissions', ['digitalevalid' => $this->digitaleval->id]);
        $submittedusers = array_column($submitted, 'userid');

        $html = html_writer::tag('h3', 'Submissions Overview');
        $html .= html_writer::start_tag('table', ['class' => 'generaltable']);
        $html .= html_writer::start_tag('tr');
        $html .= html_writer::tag('th', 'Student');
        $html .= html_writer::tag('th', 'Status');
        $html .= html_writer::tag('th', 'Action');
        $html .= html_writer::end_tag('tr');

        foreach ($students as $student) {
            $status = in_array($student->id, $submittedusers) ? 'Submitted' : 'Not submitted';
            // $gradelink = in_array($student->id, $submittedusers)
            //     ? html_writer::link(
            //         new moodle_url('/mod/digitaleval/view.php', ['id' => $this->cm->id, 'action' => 'grade', 'userid' => $student->id]),
            //         'Grade'
            //     ) : '-';

            if (in_array($student->id, $submittedusers)) {
                // Get the student’s submission id


                // $submission = reset(array_filter($submitted, fn($s) => $s->userid == $student->id));
                
                
                $filtered = array_filter($submitted, fn($s) => $s->userid == $student->id);
                $submission = reset($filtered);



                if ($submission) {
                    $gradelink = html_writer::link(
                        new moodle_url('/mod/digitaleval/grade.php', [
                            'id' => $this->cm->id,
                            'submissionid' => $submission->id
                        ]),
                        get_string('grade', 'mod_digitaleval')
                    );
                } else {
                    $gradelink = '-';
                }
            } else {
                $gradelink = '-';
            }

            $html .= html_writer::start_tag('tr');
            $html .= html_writer::tag('td', fullname($student));
            $html .= html_writer::tag('td', $status);
            $html .= html_writer::tag('td', $gradelink);
            $html .= html_writer::end_tag('tr');
        }

        $html .= html_writer::end_tag('table');
        return $html;
    }

    // ==============================
    // TEACHER: Individual grading page
    // ==============================
    public function render_grade_page($userid) {
        global $DB, $OUTPUT, $CFG;

        $user = $DB->get_record('user', ['id'=>$userid]);
        $submission = $DB->get_record('digitaleval_submissions', [
            'digitalevalid'=>$this->digitaleval->id,
            'userid'=>$userid
        ]);

        $html = html_writer::tag('h3', 'Grading: '.fullname($user));

        if (!$submission) {
            return $html.html_writer::tag('p', 'No submission found.');
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_digitaleval', 'submission', $submission->id, 'id', false);

        if ($files) {
            $html .= html_writer::tag('h4', 'Submitted Files:');
            foreach ($files as $file) {
                $url = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                );
                $html .= html_writer::link($url, $file->get_filename()) . '<br>';
            }
        }

        $html .= html_writer::start_tag('form', ['method'=>'post']);
        $html .= html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()]);
        $html .= html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'submissionid', 'value'=>$submission->id]);

        $html .= html_writer::tag('label', 'Grade (0-100): ');
        $html .= html_writer::empty_tag('input', [
            'type'=>'number', 'name'=>'grade', 'min'=>'0', 'max'=>'100', 'step'=>'0.01',
            'value'=>htmlspecialchars($submission->grade)
        ]);
        $html .= html_writer::empty_tag('br').html_writer::empty_tag('br');
        $html .= html_writer::empty_tag('input', ['type'=>'submit', 'name'=>'savegrade', 'value'=>'Save']);
        $html .= ' ';
        $html .= html_writer::link(new moodle_url('/mod/digitaleval/view.php', ['id'=>$this->cm->id]), 'Back to overview');
        $html .= html_writer::end_tag('form');

        return $html;
    }

    // ==============================
    // Handlers
    // ==============================
    public function handle_student_submission($user, $files) {
        global $DB;
        $submission = new stdClass();
        $submission->digitalevalid = $this->digitaleval->id;
        $submission->userid = $user->id;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submission->status = 'submitted';
        $subid = $DB->insert_record('digitaleval_submissions', $submission);

        $fs = get_file_storage();
        foreach ($files['answers']['name'] as $i => $filename) {
            if ($files['answers']['error'][$i] === UPLOAD_ERR_OK) {
                $fileinfo = [
                    'contextid' => $this->context->id,
                    'component' => 'mod_digitaleval',
                    'filearea'  => 'submission',
                    'itemid'    => $subid,
                    'filepath'  => '/',
                    'filename'  => $filename
                ];
                $fs->create_file_from_pathname($fileinfo, $files['answers']['tmp_name'][$i]);
            }
        }
    }

    public function handle_save_grade($submissionid, $grade, $grader) {
        global $DB;
        $sub = $DB->get_record('digitaleval_submissions', ['id'=>$submissionid], '*', MUST_EXIST);
        $sub->grade = $grade;
        $sub->grader = $grader->id;
        $sub->graded = time();
        $DB->update_record('digitaleval_submissions', $sub);

        // Gradebook sync
        $grades = [];
        $grades[$sub->userid] = (object)[
            'userid' => $sub->userid,
            'rawgrade' => $grade
        ];
        digitaleval_grade_item_update((object)['id'=>$this->digitaleval->id], $grades);
    }
}