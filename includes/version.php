<?php
/**
 * NexaCMS Version & Runtime Information (includes/version.php)
 */

if (!defined('NEXACMS_VERSION')) {
    define('NEXACMS_VERSION', '1.33.5');
}
if (!defined('NEXACMS_NAME')) {
    define('NEXACMS_NAME', 'NexaCMS');
}
// Backward-compatibility constants for UPCMS / NexaCMS plugins
if (!defined('DICIMES_VERSION')) {
    define('DICIMES_VERSION', NEXACMS_VERSION);
}
if (!defined('UPCMS_VERSION')) {
    define('UPCMS_VERSION', NEXACMS_VERSION);
}

$nexa_version   = NEXACMS_VERSION;
$nexa_db_schema = 20261114;
