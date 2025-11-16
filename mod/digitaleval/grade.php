<?php
// grade.php - grading interface for a single submission
require_once('../../config.php');
require_once('lib.php');

$id = optional_param('id', 0, PARAM_INT); // course_module ID
$n  = optional_param('n', 0, PARAM_INT);  // digitaleval instance ID
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA); // save

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

// capability check: only users allowed to grade should access this
require_capability('mod/digitaleval:grade', $context);

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

// Handle POST save actions early (before any output)
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

        // refresh submission
        $submission = $DB->get_record('digitaleval_submissions', ['id' => $curid], '*', MUST_EXIST);

        // Force Moodle to treat null meaningfully
        if ($submission->grade === null) {
            $submission->grade = '';
        }

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

        // update gradebook
        digitaleval_grade_item_update($gradeitem, $grades);

    } catch (dml_exception $e) {
        redirect(
            new moodle_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submission->id]),
            get_string('couldnotsave', 'mod_digitaleval') . ' - ' . s($e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Only 'save' action is supported here: redirect back to overview
    if ($action === 'save') {
        redirect(
            new moodle_url('/mod/digitaleval/view.php', ['id' => $cm->id]),
            get_string('gradesaved', 'mod_digitaleval'),
            1,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        // default fallback: go back to overview
        redirect(new moodle_url('/mod/digitaleval/view.php', ['id' => $cm->id]));
    }

    exit; // stop further page output after redirect
}

// Start page output (after redirects handled)
debugging('Current grade loaded: ' . var_export($submission->grade, true), DEBUG_DEVELOPER);

$PAGE->set_url('/mod/digitaleval/grade.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$PAGE->set_title(get_string('gradeforstudent', 'mod_digitaleval', ''));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('gradingstudent', 'mod_digitaleval') . ': ' . fullname($student));

// Show submitted files AND generated files (OCR output)
$fs = get_file_storage();

// Student-submitted files
$subfiles = $fs->get_area_files($context->id, 'mod_digitaleval', 'submission', $submission->id, 'sortorder, id', false);

// Generated OCR files
$genfiles = $fs->get_area_files($context->id, 'mod_digitaleval', 'generated', $submission->id, 'id', false);

// If no files at all, show warning
if (empty($subfiles) && empty($genfiles)) {
    echo $OUTPUT->notification(get_string('nofiles', 'mod_digitaleval'), 'notifywarning');
} else {
    // Grid layout with two columns: left = submitted, right = generated
    echo html_writer::start_div('digitaleval-grading-grid', ['style' => 'display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;']);

    // LEFT COLUMN: Submitted files list + preview pane
    echo html_writer::start_div('digitaleval-left-column', ['style' => 'display:flex;flex-direction:column;']);
    echo html_writer::tag('h4', get_string('submitted', 'mod_digitaleval'));
    if (empty($subfiles)) {
        echo $OUTPUT->notification(get_string('nofiles', 'mod_digitaleval'), 'notifywarning');
    } else {
        echo html_writer::start_tag('ul', ['class' => 'digitaleval-filelist']);
        foreach ($subfiles as $f) {
            if ($f->is_directory()) {
                continue;
            }
            $url = moodle_url::make_pluginfile_url(
                $f->get_contextid(),
                $f->get_component(),
                $f->get_filearea(),
                $f->get_itemid(),
                $f->get_filepath(),
                $f->get_filename()
            );
            $link = html_writer::link($url, s($f->get_filename()), [
                'href' => $url->out(false),
                'data-fileurl' => $url->out(false),
                'class' => 'digitaleval-sub-filelink',
                'target' => '_blank'
            ]);
            echo html_writer::tag('li', $link . ' ' . userdate($f->get_timemodified()));
        }
        echo html_writer::end_tag('ul');
    }

    // left preview pane: default to first submitted file if exists
    echo html_writer::start_div('digitaleval-sub-preview');
    if (!empty($subfiles)) {
        $firstsub = reset($subfiles);
        if (!$firstsub->is_directory()) {
            $firsturl = moodle_url::make_pluginfile_url(
                $firstsub->get_contextid(),
                $firstsub->get_component(),
                $firstsub->get_filearea(),
                $firstsub->get_itemid(),
                $firstsub->get_filepath(),
                $firstsub->get_filename()
            )->out(false);

            $fmime = $firstsub->get_mimetype();
            if (strpos($fmime, 'pdf') !== false) {
                // render iframe wrapped in the wrapper so CSS applies
                echo html_writer::tag('div', '<iframe src="' . s($firsturl) . '"></iframe>', ['class' => 'digitaleval-iframe-wrap']);
            } else if (strpos($fmime, 'image/') === 0) {
                echo html_writer::empty_tag('img', ['src' => s($firsturl), 'alt' => get_string('downloadfile', 'mod_digitaleval')]);
            } else {
                echo html_writer::link($firsturl, get_string('downloadfile', 'mod_digitaleval'));
            }
        } else {
            echo html_writer::tag('div', 'No preview available for first submission file.');
        }
    } else {
        echo html_writer::tag('div', 'Click a submitted file to preview here.', ['class' => 'digitaleval-preview-placeholder']);
    }
    echo html_writer::end_div(); // left preview
    echo html_writer::end_div(); // left column

    // RIGHT COLUMN: Generated files list + preview pane
    echo html_writer::start_div('digitaleval-right-column', ['style' => 'display:flex;flex-direction:column;']);
    echo html_writer::tag('h4', 'Generated Digital Answer Sheet');
    if (empty($genfiles)) {
        if (!empty($submission->ocrstatus)) {
            echo html_writer::tag('div', 'OCR status: ' . s($submission->ocrstatus));
        } else {
            echo html_writer::tag('div', 'No generated file yet');
        }
    } else {
        echo html_writer::start_tag('ul', ['class' => 'digitaleval-filelist']);
        foreach ($genfiles as $gf) {
            if ($gf->is_directory()) {
                continue;
            }
            $gurl = moodle_url::make_pluginfile_url(
                $gf->get_contextid(),
                $gf->get_component(),
                $gf->get_filearea(),
                $gf->get_itemid(),
                $gf->get_filepath(),
                $gf->get_filename()
            );
            $glink = html_writer::link($gurl, s($gf->get_filename()), [
                'href' => $gurl->out(false),
                'data-fileurl' => $gurl->out(false),
                'class' => 'digitaleval-gen-filelink',
                'target' => '_blank'
            ]);
            echo html_writer::tag('li', $glink . ' ' . userdate($gf->get_timemodified()));
        }
        echo html_writer::end_tag('ul');
    }

    // right preview pane: default to first generated file if exists
    echo html_writer::start_div('digitaleval-gen-preview');
    if (!empty($genfiles)) {
        $firstgen = reset($genfiles);
        if (!$firstgen->is_directory()) {
            $firstgurl = moodle_url::make_pluginfile_url(
                $firstgen->get_contextid(),
                $firstgen->get_component(),
                $firstgen->get_filearea(),
                $firstgen->get_itemid(),
                $firstgen->get_filepath(),
                $firstgen->get_filename()
            )->out(false);

            $gmime = $firstgen->get_mimetype();
            if (strpos($gmime, 'pdf') !== false) {
                echo html_writer::tag('div', '<iframe src="' . s($firstgurl) . '"></iframe>', ['class' => 'digitaleval-iframe-wrap']);
            } else if (strpos($gmime, 'image/') === 0) {
                echo html_writer::empty_tag('img', ['src' => s($firstgurl), 'alt' => get_string('downloadfile', 'mod_digitaleval')]);
            } else {
                echo html_writer::link($firstgurl, get_string('downloadfile', 'mod_digitaleval'));
            }
        } else {
            echo html_writer::tag('div', 'No preview available for first generated file.');
        }
    } else {
        echo html_writer::tag('div', 'Click a generated file to preview here.', ['class' => 'digitaleval-preview-placeholder']);
    }
    echo html_writer::end_div(); // right preview
    echo html_writer::end_div(); // right column

    echo html_writer::end_div(); // grid
}

// Grading form
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

// Buttons — single Save button that posts with action=save
$savebtn = html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'action', 'value' => 'save']);
echo html_writer::div($savebtn, 'digitaleval-grade-actions');

echo html_writer::end_div(); // gradeform
echo html_writer::end_tag('form');

// JS: click-to-preview for left/right panes + dynamic height adjustment
$js = <<<'JS'
require(['jquery'], function($) {
    function showInPane(selector, url) {
        var ext = (url.split('.').pop() || '').toLowerCase();
        var container = $(selector);
        if (!container.length) { window.open(url, '_blank'); return; }
        if (ext === 'pdf') {
            container.html('<div class="digitaleval-iframe-wrap"><iframe src="' + url + '"></iframe></div>');
            return;
        }
        if (['png','jpg','jpeg','gif','bmp','webp'].indexOf(ext) !== -1) {
            container.html('<img src="' + url + '" alt="preview" />');
            return;
        }
        // fallback: open in new tab
        window.open(url, '_blank');
    }

    // submitted files -> left pane
    $(document).on('click', 'a.digitaleval-sub-filelink', function(e) {
        e.preventDefault();
        var url = $(this).data('fileurl') || $(this).attr('href');
        showInPane('.digitaleval-sub-preview', url);
    });

    // generated files -> right pane
    $(document).on('click', 'a.digitaleval-gen-filelink', function(e) {
        e.preventDefault();
        var url = $(this).data('fileurl') || $(this).attr('href');
        showInPane('.digitaleval-gen-preview', url);
    });

    // Dynamically compute available height for previews and set CSS
    function adjustPreviewHeights() {
        var vh = window.innerHeight;
        var headerH = 0;

        // try to measure Moodle header/heading areas (multiple fallbacks)
        var $pageheader = $('.page-header, #page-header, .breadcrumb, .region-main .page-heading, .pagelayout, .navbar');
        if ($pageheader.length) {
            $pageheader.each(function() { headerH += $(this).outerHeight(true); });
        } else {
            headerH = 120;
        }

        // grading form area (controls below previews)
        var formH = 0;
        var $gradeform = $('.digitaleval-gradeform');
        if ($gradeform.length) {
            formH = $gradeform.outerHeight(true);
        } else {
            formH = 200;
        }

        var extra = 40;
        var available = vh - headerH - formH - extra;
        if (available < 300) { available = 300; }

        // set both preview panes to available height
        $('.digitaleval-sub-preview, .digitaleval-gen-preview').css({
            'height': available + 'px',
            'min-height': available + 'px'
        });

        // ensure grid has at least that height
        $('.digitaleval-grading-grid').css('min-height', available + 'px');
    }

    $(document).ready(function() {
        adjustPreviewHeights();
        // re-run shortly after load in case theme elements settle
        setTimeout(adjustPreviewHeights, 400);
    });

    var resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(adjustPreviewHeights, 120);
    });
});
JS;

$PAGE->requires->js_init_code($js);

// small inline CSS to keep preview panes visually similar and make content fill entire area
echo html_writer::tag('style', '
/* Grid: two columns, previews take the available height */
.digitaleval-grading-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-auto-rows: 1fr;
    gap: 1rem;
    align-items: start;
}

/* Make file lists sit above the preview pane inside each column */
.digitaleval-left-column,
.digitaleval-right-column {
    display: grid;
    grid-template-rows: auto 1fr; /* list (auto height) + preview (fills remaining) */
}

/* File list styling */
.digitaleval-filelist {
    list-style: none;
    padding-left: 0;
    margin-bottom: 0.5rem;
}
.digitaleval-filelist li {
    margin-bottom: 0.4rem;
}

/* Preview panes: take full available cell space; height set by JS */
.digitaleval-sub-preview,
.digitaleval-gen-preview {
    border: 1px solid #e0e0e0;
    padding: 0;
    min-height: 95vh;
    height: 100%;
    width: 100%;
    overflow: hidden;
    display: block;
    box-sizing: border-box;
}

/* iframe wrapper fills the preview pane */
.digitaleval-iframe-wrap {
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* iframe and images fill the wrapper entirely */
.digitaleval-iframe-wrap iframe,
.digitaleval-sub-preview > iframe,
.digitaleval-gen-preview > iframe {
    width: 100% !important;
    height: 100% !important;
    border: 0;
    display: block;
}

/* If JS injects an img directly into the preview pane */
.digitaleval-sub-preview img,
.digitaleval-gen-preview img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain;
    display: block;
}

/* Placeholder text when no file loaded */
.digitaleval-preview-placeholder {
    padding: 1rem;
    color: #666;
    text-align: center;
    width: 100%;
}

/* Small visual niceties */
.digitaleval-preview-placeholder,
.digitaleval-sub-preview,
.digitaleval-gen-preview {
    background: #fff;
}
');


// Footer
echo $OUTPUT->footer();