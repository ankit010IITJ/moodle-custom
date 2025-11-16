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

    /**
     * Render student submission page with status table, submitted files, generated files and feedback.
     *
     * @param stdClass $user
     * @return string HTML
     */
    public function render_student_submission_page($user) {
        global $DB, $OUTPUT;

        // Ensure full user record
        $user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);

        $context = $this->context;
        $fs = get_file_storage();

        // Find student's submission record (if any)
        $submission = $DB->get_record('digitaleval_submissions', [
            'digitalevalid' => $this->digitaleval->id,
            'userid' => $user->id
        ]);

        $html = '';

        // Header
        $html .= html_writer::tag('h3', 'Submission status');

        // If no submission record, show upload form (simple)
        if (!$submission) {
            $html .= html_writer::tag('p', 'You have not submitted an answersheet yet.');
            $html .= html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data']);
            $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $html .= html_writer::empty_tag('input', ['type' => 'file', 'name' => 'answers[]', 'multiple' => 'multiple']);
            $html .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
            $html .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit answersheet']);
            $html .= html_writer::end_tag('form');
            return $html;
        }

        // ---- Submission status table rows ----
        $rows = [];

        // Submission status
        $rows[] = html_writer::tag('th', 'Submission status') .
                html_writer::tag('td', 'Submitted for grading');

        // Grading status (use graded timestamp as truth)
        $isgraded = (!empty($submission->graded) && (int)$submission->graded > 0);
        $gradingstatus = $isgraded ? 'Graded' : 'Submitted for grading';
        $rows[] = html_writer::tag('th', 'Grading status') .
                html_writer::tag('td', $gradingstatus);

        // Time remaining / late
        $timemsg = '-';
        if (!empty($this->digitaleval->duedate) && (int)$this->digitaleval->duedate > 0) {
            $duedate = (int)$this->digitaleval->duedate;
            if ($submission->timecreated > $duedate) {
                $late = $submission->timecreated - $duedate;
                $days = floor($late / DAYSECS);
                $hours = floor(($late % DAYSECS) / HOURSECS);
                $timemsg = 'Assignment was submitted ' . ($days>0 ? $days . ' days ' : '') . $hours . ' hours late';
            } else {
                $remaining = $duedate - time();
                if ($remaining > 0) {
                    $timemsg = format_time($remaining) . ' remaining';
                } else {
                    $timemsg = 'Due date passed';
                }
            }
        } else {
            $timemsg = 'No due date';
        }
        $rows[] = html_writer::tag('th', 'Time remaining') . html_writer::tag('td', $timemsg);

        // Last modified
        $lastmod = userdate($submission->timemodified ?: $submission->timecreated);
        $rows[] = html_writer::tag('th', 'Last modified') . html_writer::tag('td', $lastmod);

        // File submissions: Submitted files and Generated files
        $filecell = '';

        // Submitted files (add class 'digitaleval-filelink' so JS can preview)
        $submittedfiles = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $submission->id, 'filename', false);
        if (!empty($submittedfiles)) {
            $filecell .= html_writer::tag('div', html_writer::tag('strong', 'Submitted files:'));
            foreach ($submittedfiles as $f) {
                $url = moodle_url::make_pluginfile_url(
                    $f->get_contextid(),
                    $f->get_component(),
                    $f->get_filearea(),
                    $f->get_itemid(),
                    $f->get_filepath(),
                    $f->get_filename()
                );
                $attrs = [
                    'target' => '_blank',
                    'data-fileurl' => $url->out(false),
                    'class' => 'digitaleval-filelink'
                ];
                $filecell .= html_writer::link($url, s($f->get_filename()), $attrs) . ' &nbsp; ' . userdate($f->get_timemodified()) . html_writer::empty_tag('br');
            }
        } else {
            $filecell .= html_writer::tag('div', 'No files submitted');
        }

        // Generated OCR files (mark links with 'digitaleval-generated-link', do NOT preview by default)
        $generatedfiles = $fs->get_area_files($context->id, 'mod_digitaleval', 'generated', $submission->id, 'id', false);
        if (!empty($generatedfiles)) {
            $filecell .= html_writer::tag('div', html_writer::tag('strong', 'Generated digital answer sheet:'));
            foreach ($generatedfiles as $gf) {
                $gurl = moodle_url::make_pluginfile_url(
                    $gf->get_contextid(),
                    $gf->get_component(),
                    $gf->get_filearea(),
                    $gf->get_itemid(),
                    $gf->get_filepath(),
                    $gf->get_filename()
                );
                $gattrs = [
                    'target' => '_blank',
                    'data-fileurl' => $gurl->out(false),
                    'class' => 'digitaleval-generated-link'
                ];
                $filecell .= html_writer::link($gurl, s($gf->get_filename()), $gattrs) . ' &nbsp; ' . userdate($gf->get_timemodified()) . html_writer::empty_tag('br');
            }
        } else {
            // show OCR status if available
            if (!empty($submission->ocrstatus)) {
                $filecell .= html_writer::tag('div', 'OCR status: ' . s($submission->ocrstatus));
            } else {
                $filecell .= html_writer::tag('div', 'No generated file yet');
            }
        }

        $rows[] = html_writer::tag('th', 'File submissions') . html_writer::tag('td', $filecell);

        // Submission comments placeholder
        $rows[] = html_writer::tag('th', 'Submission comments') . html_writer::tag('td', html_writer::link(new moodle_url('#'), 'Comments (0)'));

        // Build table HTML
        $tablehtml = html_writer::start_tag('table', ['class' => 'generaltable submissionstatus']);
        foreach ($rows as $r) {
            $tablehtml .= html_writer::start_tag('tr');
            $tablehtml .= $r;
            $tablehtml .= html_writer::end_tag('tr');
        }
        $tablehtml .= html_writer::end_tag('table');

        $html .= $tablehtml;

        // ---- Preview area: initially empty (user must click a file to preview) ----
        $html .= html_writer::start_div('digitaleval-preview-wrapper');
        $html .= html_writer::tag('div', 'Click any file above to preview it here.', ['class' => 'digitaleval-preview-placeholder']);
        $html .= html_writer::end_div(); // preview wrapper

        // ---- Feedback block ----
        $html .= html_writer::tag('h3', 'Feedback');

        $feedbackrows = [];

        // Grade
        $gradevalue = ($submission->grade !== null && $submission->grade !== '') ? s($submission->grade) . ' / 100.00' : 'Not graded yet';
        $feedbackrows[] = html_writer::tag('th', 'Grade') . html_writer::tag('td', $gradevalue);

        // Graded on
        $gradedon = $isgraded ? userdate($submission->graded) : '-';
        $feedbackrows[] = html_writer::tag('th', 'Graded on') . html_writer::tag('td', $gradedon);

        // Graded by
        $gradername = '-';
        if (!empty($submission->grader)) {
            $grader = $DB->get_record('user', ['id' => $submission->grader], '*', MUST_EXIST);
            if ($grader) {
                $initials = strtoupper(substr($grader->firstname,0,1) . substr($grader->lastname,0,1));
                $avatar = html_writer::tag('span', s($initials), ['class' => 'simple-avatar']) . ' ' . s(fullname($grader));
                $gradername = $avatar;
            }
        }
        $feedbackrows[] = html_writer::tag('th', 'Graded by') . html_writer::tag('td', $gradername);

        $fbhtml = html_writer::start_tag('table', ['class' => 'generaltable feedbackbox']);
        foreach ($feedbackrows as $fr) {
            $fbhtml .= html_writer::start_tag('tr');
            $fbhtml .= $fr;
            $fbhtml .= html_writer::end_tag('tr');
        }
        $fbhtml .= html_writer::end_tag('table');

        $html .= $fbhtml;

        // Inline CSS for small avatar and spacing and a subtle style for generated links
        $html .= html_writer::tag('style', '
            .submissionstatus th { width: 25%; text-align:left; padding:10px; background:#f8f8f8; }
            .submissionstatus td { padding:10px; }
            .feedbackbox th { width: 20%; text-align:left; padding:10px; background:#fafafa; }
            .feedbackbox td { padding:10px; }
            .simple-avatar { display:inline-block; width:32px; height:32px; border-radius:50%; background:#e9e9e9; text-align:center; line-height:32px; margin-right:8px; font-weight:600; color:#333; }
            .digitaleval-iframe-wrap { margin-top: 1rem; }
            .digitaleval-generated-link { color:#0b5fff; font-weight:600; } /* you can style generated file links separately */
            .digitaleval-preview-placeholder { padding: 1rem; border: 1px dashed #ddd; color: #666; background: #fafafa; text-align:center; }
        ');

        // JS to enable click-to-preview for both submitted and generated file links.
        $js = <<<JS
        require(['jquery'], function($) {
            function loadPreview(url) {
                var ext = (url.split('.').pop() || '').toLowerCase();
                var wrapper = $('.digitaleval-preview-wrapper');
                if (ext === 'pdf') {
                    wrapper.html('<div class="digitaleval-iframe-wrap"><iframe id="digitaleval-preview-iframe" src="' + url + '" style="width:100%;height:600px;border:0;"></iframe></div>');
                    return;
                }
                if (['png','jpg','jpeg','gif','bmp','webp'].indexOf(ext) !== -1) {
                    wrapper.html('<img id="digitaleval-preview-img" src="' + url + '" style="max-width:100%;max-height:600px;" />');
                    return;
                }
                // fallback: open in new tab
                window.open(url, '_blank');
            }

            // bind click handler to file links (both classes used earlier)
            $(document).on('click', 'a.digitaleval-filelink, a.digitaleval-generated-link', function(e) {
                e.preventDefault();
                var url = $(this).data('fileurl') || $(this).attr('href');
                loadPreview(url);
            });
        });
    JS;
        $html .= html_writer::tag('script', $js);

        return $html;
    }


    // ==============================
    // TEACHER: Submission overview
    // ==============================
    public function render_overview() {
        global $DB, $OUTPUT;

        $students = get_enrolled_users($this->context, 'mod/digitaleval:submit');
        $submitted = $DB->get_records('digitaleval_submissions', ['digitalevalid' => $this->digitaleval->id], 'id ASC');

        // Map submissions by userid for quick lookup.
        $subsbyuser = [];
        foreach ($submitted as $s) {
            $subsbyuser[$s->userid] = $s;
        }

        // Counts using graded timestamp as source of truth
        $totalstudents  = count($students);
        $submittedcount = 0;
        $gradedcount    = 0;
        foreach ($subsbyuser as $s) {
            $submittedcount++;
            // Consider "graded" only if graded timestamp exists (and > 0)
            if (!empty($s->graded) && (int)$s->graded > 0) {
                $gradedcount++;
            }
        }
        $requiregrading = $submittedcount - $gradedcount;

        // Header + stats
        $html  = html_writer::tag('h3', 'Submissions overview');
        $html .= html_writer::start_tag('div', ['class' => 'digitaleval-overview-stats']);
        $html .= html_writer::tag('div', 'Total enrolled: ' . $totalstudents);
        $html .= html_writer::tag('div', 'Submitted: ' . $submittedcount);
        $html .= html_writer::tag('div', 'Graded: ' . $gradedcount);
        $html .= html_writer::tag('div', 'Require grading: ' . $requiregrading);
        $html .= html_writer::end_tag('div');

        // Table header
        $html .= html_writer::start_tag('table', ['class' => 'generaltable']);
        $html .= html_writer::start_tag('thead');
        $html .= html_writer::start_tag('tr');
        $html .= html_writer::tag('th', 'Student');
        $html .= html_writer::tag('th', 'Status');
        $html .= html_writer::tag('th', 'Submitted at');
        $html .= html_writer::tag('th', 'Graded');
        $html .= html_writer::tag('th', 'Graded at');
        $html .= html_writer::tag('th', 'Action');
        $html .= html_writer::end_tag('tr');
        $html .= html_writer::end_tag('thead');

        $html .= html_writer::start_tag('tbody');

        foreach ($students as $student) {
            $s = isset($subsbyuser[$student->id]) ? $subsbyuser[$student->id] : null;

            $status = $s ? 'Submitted' : 'Not submitted';
            $submittedat = $s ? userdate($s->timecreated) : '-';

            // Use graded timestamp to decide graded state
            $isgraded = ($s && !empty($s->graded) && (int)$s->graded > 0);
            $graded = $isgraded ? 'Yes' : 'No';
            $gradedat = $isgraded ? userdate($s->graded) : '-';

            if ($s) {
                $actionlink = html_writer::link(
                    new moodle_url('/mod/digitaleval/grade.php', ['id' => $this->cm->id, 'submissionid' => $s->id]),
                    get_string('grade', 'mod_digitaleval')
                );
            } else {
                $actionlink = '-';
            }

            $html .= html_writer::start_tag('tr');
            $html .= html_writer::tag('td', fullname($student));
            $html .= html_writer::tag('td', s($status));
            $html .= html_writer::tag('td', s($submittedat));
            $html .= html_writer::tag('td', s($graded));
            $html .= html_writer::tag('td', s($gradedat));
            $html .= html_writer::tag('td', $actionlink);
            $html .= html_writer::end_tag('tr');
        }

        $html .= html_writer::end_tag('tbody');
        $html .= html_writer::end_tag('table');

        return $html;
    }




    // ==============================
    // TEACHER: Individual grading page
    // ==============================
    public function render_grade_page($userid) {
        global $DB, $OUTPUT, $CFG;

        $user = $DB->get_record('user', ['id' => $userid], MUST_EXIST);
        $submission = $DB->get_record('digitaleval_submissions', [
           'digitalevalid' => $this->digitaleval->id,
           'userid' => $userid
        ]);

        $html = html_writer::tag('h3', 'Grading: ' . fullname($user));

        if (!$submission) {
            return $html . html_writer::tag('p', 'No submission found.');
        }

        $fs = get_file_storage();

        // Submitted files
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
                $html .= html_writer::link($url, s($file->get_filename())) . '<br>';
            }
        } else {
            $html .= html_writer::tag('p', 'No uploaded files found.');
        }

        // Generated files (OCR output) — THIS IS THE KEY ADDITION
        $gfiles = $fs->get_area_files($this->context->id, 'mod_digitaleval', 'generated', $submission->id, 'id', false);
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
                $html .= html_writer::link($gurl, s($gfile->get_filename())) . '<br>';
            }
        } else {
            // If no generated file show OCR status (if available)
            if (!empty($submission->ocrstatus)) {
                $html .= html_writer::tag('p', 'OCR status: ' . s($submission->ocrstatus));
            } else {
                $html .= html_writer::tag('p', 'No generated file available yet.');
            }
        }

        // Grading form
        $html .= html_writer::start_tag('form', ['method' => 'post']);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submission->id]);

        $html .= html_writer::tag('label', 'Grade (0-100): ');
        $html .= html_writer::empty_tag('input', [
           'type' => 'number', 'name' => 'grade', 'min' => '0', 'max' => '100', 'step' => '0.01',
           'value' => isset($submission->grade) ? s($submission->grade) : ''
        ]);
        $html .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
        $html .= html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'savegrade', 'value' => 'Save']);
        $html .= ' ';
        $html .= html_writer::link(new moodle_url('/mod/digitaleval/view.php', ['id' => $this->cm->id]), 'Back to overview');
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