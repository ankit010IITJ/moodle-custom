<?php
function xmldb_digitaleval_upgrade($oldversion) {
global $DB;
$dbman = $DB->get_manager();


$result = true;


if ($oldversion < 2025102200) {
// initial release handled by install.xml
upgrade_plugin_savepoint(true, 2025102200, 'mod', 'digitaleval');
}


return $result;
}
?>