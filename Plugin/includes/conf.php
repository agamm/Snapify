<?php
// check if plugin page or standalnoe AJAX
if (!function_exists('snapify_sys_get_temp_directory')) {
	require_once 'includes' . DIRECTORY_SEPARATOR  . 'plugin_functions.php';
}

// snapify metadata
define('SNAPIFY_VERSION', '1.3.3');
define('SNAPIFY_NO_PREMISSIONS_MSG', 'You do not have sufficient permissions to access this page.');
define('SNAPIFY_MAIN_MENU_PAGE_NAME', 'snapify');
define('SNAPIFY_TEMP_FILE_NAME', 'snapify');

// snapify settings
define('SNAPIFY_MAX_EXECUTION_TIME_SECS', 36000);
define('SNAPIFY_DB_UPDATE_RATE', 10000);
define('SNAPIFY_FILES_UPDATE_RATE', 100);
define('SNAPIFY_FILES_PRE_REQUEST_LIMIT', 30000);
define('SNAPIFY_BACKUP_METHOD_COMPRESS_LIMIT', SNAPIFY_FILES_PRE_REQUEST_LIMIT * 2);
define('SNAPIFY_DISPLAY_ERRORS', false);

// snapify pathes
define('SNAPIFY_PATH_DEBUG_LOG', snapify_sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify-debug.log');
define('SNAPIFY_TEMP_PATH_PROGRESS_DETAILS', snapify_sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify-progress-details.tmp');
define('SNAPIFY_TEMP_PATH_PROGRESS', snapify_sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify-progress.tmp');
define('SNAPIFY_TEMP_PATH_TMP_SQL_DUMP', snapify_sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify.sql');
define('SNAPIFY_TEMP_PATH_TMP_PATH_SITE', snapify_sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify.zip');

?>