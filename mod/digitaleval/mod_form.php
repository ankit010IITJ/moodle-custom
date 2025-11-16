<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_digitaleval_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        // ============================
        // GENERAL SETTINGS
        // ============================
        $mform->addElement('text', 'name', get_string('digitalevalname', 'mod_digitaleval'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Standard activity intro/description field
        $this->standard_intro_elements();

        // ============================
        // QUESTION PAPER UPLOAD (PROF)
        // ============================
        $mform->addElement(
            'filepicker',
            'questionfile',
            get_string('questionfile', 'mod_digitaleval'),
            null,
            [
                'accepted_types' => ['.pdf'],
                'maxbytes' => 100 * 1024 * 1024, // 10 MB limit
                'maxfiles' => 1
            ]
        );
        $mform->addRule('questionfile', null, 'required', null, 'client');
        $mform->addHelpButton('questionfile', 'questionfile', 'mod_digitaleval');

        // ============================
        // DUE DATE
        // ============================
        $mform->addElement('date_time_selector', 'timedue', get_string('duedate', 'mod_digitaleval'));
        $mform->setDefault('timedue', 0);

        // ============================
        // FILE UPLOAD LIMITS (for students)
        // ============================
        $mform->addElement('text', 'maxfiles', get_string('maxfiles', 'mod_digitaleval'));
        $mform->setType('maxfiles', PARAM_INT);
        $mform->setDefault('maxfiles', 1);

        $mform->addElement('text', 'maxbytes', get_string('maxbytes', 'mod_digitaleval'));
        $mform->setType('maxbytes', PARAM_INT);
        $mform->setDefault('maxbytes', 100 * 1024 * 1024);

        // ============================
        // STANDARD MODULE SETTINGS
        // ============================
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
?>