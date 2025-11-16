<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_digitaleval_get_next_submission' => [
        'classname'   => 'mod_digitaleval_external',
        'methodname'  => 'get_next_submission',
        'classpath'   => 'mod/digitaleval/externallib.php',
        'description' => 'Fetch the next submission for grading and return its rendered HTML.',
        'type'        => 'read',
        'ajax'        => true,
    ],
];

$services = [
    'Digitaleval Service' => [
        'functions' => ['mod_digitaleval_get_next_submission'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];