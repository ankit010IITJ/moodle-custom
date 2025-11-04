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
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $sub->id, 'id', false);
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

            // Generated files (OCR output)
            $gfiles = $fs->get_area_files($context->id, 'mod_digitaleval', 'generated', $sub->id, 'id', false);
            if ($gfiles) {
                $html .= html_writer::tag('h4', 'Generated Digital Answer Sheet:');
                foreach ($gfiles as $gfile) {
                    $gurl = moodle_url::make_pluginfile_url(
                        $gfile->get_contextid(),
                        $gfile->get_component(),
                        $gfile->get_filearea(),
                        $gfile->get_itemid(),
                        $gfile->get_filepath(),
                        $gfile->get_filename()
                    );
                    $html .= html_writer::link($gurl, $gfile->get_filename()) . '<br>';
                }
            } else {
                // show OCR status if available
                if (!empty($sub->ocrstatus)) {
                    $html .= html_writer::tag('p', 'OCR status: ' . htmlspecialchars($sub->ocrstatus));
                }
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

            if (in_array($student->id, $submittedusers)) {
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


        set_time_limit(500);
        ini_set('memory_limit', '512M');


        $submission = new stdClass();
        $submission->digitalevalid = $this->digitaleval->id;
        $submission->userid = $user->id;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submission->status = 'submitted';


        $subid = $DB->insert_record('digitaleval_submissions', $submission);


        $fs = get_file_storage();
        $uploaded_tmp_paths = [];


        // ===============================
        // 1. Store uploaded answer files
        // ===============================
        if (!empty($files['answers']['name'])) {
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

                    // Save uploaded answer file into Moodle file storage
                    $fs->create_file_from_pathname($fileinfo, $files['answers']['tmp_name'][$i]);
                    $uploaded_tmp_paths[] = $files['answers']['tmp_name'][$i];
                }
            }
        }


        // Proceed only if at least one file was uploaded
        if (empty($uploaded_tmp_paths)) {
            debugging('No uploaded answers found for submission ' . $subid, DEBUG_DEVELOPER);
            return;
        }


        // ===============================
        // 2. Locate question paper (prof upload)
        // ===============================
        $questionfile = null;


        // Try filearea 'questionfile' (saved in lib.php)
        $qfiles = $fs->get_area_files($this->context->id, 'mod_digitaleval', 'questionfile', 0, 'id', false);
        if (!empty($qfiles)) {
            $questionfile = reset($qfiles);
        } else {
            // Fallback: try intro filearea (standard Moodle intro attachments)
            $qfiles2 = $fs->get_area_files($this->context->id, 'mod_digitaleval', 'intro', 0, 'id', false);
            if (!empty($qfiles2)) {
            $questionfile = reset($qfiles2);
            }
        }


        if (!$questionfile) {
            // Record status and stop — no question found
            $sub = $DB->get_record('digitaleval_submissions', ['id' => $subid], '*', MUST_EXIST);
            $sub->ocrstatus = 'no_question';
            $DB->update_record('digitaleval_submissions', $sub);
            debugging('No question paper found in "questionfile" or "intro" fileareas for instance ' . $this->digitaleval->id, DEBUG_DEVELOPER);
            return;
        }


        // ===============================
        // 3. Prepare files for OCR API
        // ===============================
        $tmpqp = tempnam(sys_get_temp_dir(), 'digqp_');
        $tmpqp_pdf = $tmpqp . '.pdf';
        file_put_contents($tmpqp_pdf, $questionfile->get_content());


        $answertmp = $uploaded_tmp_paths[0];
        $outputtmp = tempnam(sys_get_temp_dir(), 'diggen_') . '.pdf';


        // ===============================
        // 4. Call local OCR API
        // ===============================
        $apiUrl = 'http://127.0.0.1:8080/process';
        $ch = curl_init();


        $postfields = [
        'question_paper' => new CURLFile($tmpqp_pdf, mime_content_type($tmpqp_pdf), basename($tmpqp_pdf)),
        'answer_sheet'   => new CURLFile($answertmp, mime_content_type($answertmp), basename($answertmp))
        ];


        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postfields,


            // Increased timeouts for long model generation
            CURLOPT_TIMEOUT => 600,             // allow up to 10 minutes
            CURLOPT_CONNECTTIMEOUT => 60,       // wait up to 1 minute to connect


            // Follow redirects just in case (harmless)
            CURLOPT_FOLLOWLOCATION => true,


            // Handle binary output safely
            CURLOPT_BINARYTRANSFER => true,


            // Don't let cURL buffer output in memory if large (streamed write)
            CURLOPT_BUFFERSIZE => 128 * 1024,
        ]);


        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerr = curl_error($ch);
        curl_close($ch);


        $saved = false;


        // ===============================
        // 5. Save OCR-generated file
        // ===============================
        if ($result !== false && $httpcode >= 200 && $httpcode < 300 && !empty($result)) {
            file_put_contents($outputtmp, $result);


            $generatedfileinfo = [
            'contextid' => $this->context->id,
            'component' => 'mod_digitaleval',
            'filearea'  => 'generated',
            'itemid'    => $subid,
            'filepath'  => '/',
            'filename'  => 'generated_answersheet_' . $subid . '.pdf'
            ];


            try {
            $fs->create_file_from_pathname($generatedfileinfo, $outputtmp);
            $saved = true;
            } catch (Exception $e) {
            debugging('Failed to save generated file: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        } else {
            debugging('OCR API call failed. HTTP code: ' . $httpcode . ' curl error: ' . $curlerr, DEBUG_DEVELOPER);
        }


        // ===============================
        // 6. Update submission status
        // ===============================
        $sub = $DB->get_record('digitaleval_submissions', ['id' => $subid], '*', MUST_EXIST);
        $sub->ocrstatus = $saved ? 'done' : 'failed';
        $DB->update_record('digitaleval_submissions', $sub);


        // Cleanup temp files
        @unlink($tmpqp);
        @unlink($tmpqp_pdf);
        @unlink($outputtmp);
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