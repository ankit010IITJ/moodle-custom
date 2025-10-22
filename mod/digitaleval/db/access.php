<?php
defined('MOODLE_INTERNAL') || die();


$capabilities = array(
'mod/digitaleval:addinstance' => array(
'riskbitmask' => RISK_SPAM | RISK_XSS,
'captype' => 'write',
'contextlevel' => CONTEXT_COURSE,
'archetypes' => array(
'editingteacher' => CAP_ALLOW,
'manager' => CAP_ALLOW
)
),
'mod/digitaleval:submit' => array(
'captype' => 'write',
'contextlevel' => CONTEXT_MODULE,
'archetypes' => array(
'student' => CAP_ALLOW
)
),
'mod/digitaleval:grade' => array(
'captype' => 'write',
'contextlevel' => CONTEXT_MODULE,
'archetypes' => array(
'teacher' => CAP_ALLOW,
'editingteacher' => CAP_ALLOW,
'manager' => CAP_ALLOW
)
),
);


?>