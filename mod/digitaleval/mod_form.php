<?php
defined('MOODLE_INTERNAL') || die();


require_once($CFG->dirroot.'/course/moodleform_mod.php');


class mod_digitaleval_mod_form extends moodleform_mod {
public function definition() {
$mform = $this->_form;


// general
$mform->addElement('text', 'name', get_string('digitalevalname', 'mod_digitaleval'));
$mform->setType('name', PARAM_TEXT);
$mform->addRule('name', null, 'required', null, 'client');


$this->standard_intro_elements();


$mform->addElement('date_time_selector', 'timedue', get_string('duedate', 'mod_digitaleval'));
$mform->setDefault('timedue', 0);


$mform->addElement('text', 'maxfiles', get_string('maxfiles', 'mod_digitaleval'));
$mform->setType('maxfiles', PARAM_INT);
$mform->setDefault('maxfiles', 1);


$mform->addElement('text', 'maxbytes', get_string('maxbytes', 'mod_digitaleval'));
$mform->setType('maxbytes', PARAM_INT);
$mform->setDefault('maxbytes', 1048576);


$this->standard_coursemodule_elements();
$this->add_action_buttons();
}
}


?>