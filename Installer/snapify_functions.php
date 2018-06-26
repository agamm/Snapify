<?php
// Load the Snapify.conf file to memory as json
function get_config() {
	return json_decode(file_get_contents('./snapify.conf'), true);
}

// Fix the cPanel directory conventions
function sys_get_temp_directory() {
	$is_cpanel = file_exists('/usr/local/cpanel/version');
	if ($is_cpanel) {
		$tmp_dir = '/home/'. get_current_user() . '/tmp';
		if(is_writable($tmp_dir)) {
			return $tmp_dir;
		}
	}
	
	$tmp_dir = sys_get_temp_dir();
	if (is_writable($tmp_dir)) {
		return $tmp_dir;
	}
	
	die('Please contact Simple360 for further support, you\'r temp directory is inaccessible');
}

// Return path to temp progress file
function get_ajax_progress_file_path() {
	return sys_get_temp_directory() . DIRECTORY_SEPARATOR . "snapify-progress.tmp";
}

// Put new progress into the ajax progress file
function update_progress($progress) {
	static $prev_progress = null;
	if ($prev_progress != $progress) {
		file_put_contents(get_ajax_progress_file_path(), $progress);
		Debug::log($progress);
		$prev_progress = $progress;
	}
}

// Fetch progress from the ajax progress file
function get_progress() {
	return '"' . file_get_contents(get_ajax_progress_file_path()) . '"';
}

// Get the total size of a zip file
function get_zip_total_size($zip_file) {
	$size = 0;
	$zip = zip_open($zip_file);
	if(!is_resource($zip)){
		return false;
	}

	// Read the file in chunks, to ease memory 
	// limits
	while ($zip_entry = zip_read($zip)) {
		zip_entry_open($zip, $zip_entry);
		$size += zip_entry_filesize($zip_entry);
		zip_entry_close($zip_entry);
	}

	zip_close($zip);
	return $size;
}

// Unzip a file and update the progress
function unzip_ajax($zip_file) {
	update_progress("Calculating size...");

	$zip = zip_open($zip_file);
	if(!is_resource($zip)){
		return false;
	}

	// Calculate the zip size so we could
	// show the progress percentage
	$total_size = get_zip_total_size($zip_file);
	if(!$total_size) {
		return false;
	}

	Debug::log("Zip File Size:" . $total_size);
	$processed_size = 0;
	update_progress('Started unzipping');
	while ($zip_entry = zip_read($zip)) {
		$progress_precent = intval($processed_size * 100 / $total_size);
		
		// Minimize size of progress file by writing only when the progress changed
		update_progress("Unzipping progress: {$progress_precent}%");

		zip_entry_open($zip, $zip_entry);

		$name = zip_entry_name($zip_entry);
		// if file exists, simply skip it
		if (file_exists($name)) {
			zip_entry_close($zip_entry);
			continue;
		}

		// is directory
		if (substr($name, -1) === '/') {
			mkdir($name);
		} else {
			$fopen = fopen($name, "w");

			// Read file in chunks for low end machines
			$chunk_data = zip_entry_read($zip_entry, CHUNK_SIZE);
			while($chunk_data) {
				fwrite($fopen, $chunk_data, zip_entry_filesize($zip_entry));
				$chunk_data = zip_entry_read($zip_entry, CHUNK_SIZE);
			}

			$processed_size += zip_entry_filesize($zip_entry);
		}
		zip_entry_close($zip_entry);
	}
	update_progress("Done unzipping!");
	zip_close($zip);
	return true;
}

// Check if the session started already before 
// trying to start it
function safe_session_start() {
	if (function_exists('session_status') && session_status() == PHP_SESSION_NONE) {
		session_start();
	} elseif (session_id() == '') {
		session_start();
	}
}

// Check if the errors session is set with a value
function system_has_errors() {
	safe_session_start();
	if(!isset($_SESSION['errors']) && empty($_SESSION['errors'])) {
		return false;
	}
	return true;
}

// Get debug log save path
function snapify_get_debug_path() {
	return sys_get_temp_directory() . DIRECTORY_SEPARATOR . "snapify_debuglog.tmp";
}

// Generate an html output for the errors session
function get_errors_html(){
	safe_session_start();
	if(!system_has_errors()) {
		return '';
	}
	$errors = implode("<br />", array_values($_SESSION['errors']));
	unset($_SESSION['errors']);

	Debug::log("Errors HTML:" . $errors);
	return '<div class="error-messages">' . $errors . '</div><div class="debug-log"><a href="?debug-log=true">Debug-log</a></div>';
}

// Initiate a download of the debug log
function download_debug_log() {
	$debug_log_path = snapify_get_debug_path();
	$save_name = 'snapify_debug_log.txt';

	$realpath = realpath($debug_log_path);
	if (!$realpath) {
		throw new Exception("Debug file not found! ({$path})");
	}

	header('Content-Type: text/plain');
	header("Content-Disposition: attachment; filename={$save_name}");
	header('Cache-Control: private');
	header('Pragma: public');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	header('Content-Length: ' . filesize($realpath));

	$file_handler = fopen($realpath, 'rb');
	while (!feof($file_handler) && !connection_aborted()) {
		echo fread($file_handler, 8192);
	}

	fclose($file_handler);
}

// Set new errors in to the errors session
function set_errors($error_message) {
	safe_session_start();
	$errors = $error_message;
	Debug::log('USER: ' . $error_message);
	if(!is_array($error_message)) {
		$errors = array($error_message);
	}
	$_SESSION['errors'] = $errors;
}

// Check if the DB form has any bad inputs given
function get_bad_inputs($config) {
	$required = array("mysql_db", "mysql_user");
	$bad_inputs = array();

	// Empty
	if(!isset($config['mysql_db']) || empty($config['mysql_db'])) {
		$bad_inputs['mysql_db'] = 'Please fill the MySQL database name.';
	}
	if(!isset($config['mysql_user']) || empty($config['mysql_user'])) {
		$bad_inputs['mysql_user'] = 'Please fill the MySQL username.';
	}

	// @TODO next version:
	// Filter db only A-z09
	// Filter host -> host/ipv4
	// Filter port -> 0-65536

	return $bad_inputs;
}


// Try to edit the wp-config.php file for any artifacts from the
// old server environment, like database credentials
function replace_wp_config_define($define_name, $new_value) {
	update_progress("Editing wp config...");
	$wp_config_data = file_get_contents('./wp-config.php');
	$wp_config_data_replaced = preg_replace("/define\('$define_name',\s(.*?)\);/i", "DEFINE('$define_name', '$new_value');", $wp_config_data);
	update_progress("Finished Editing wp config...");
	file_put_contents('./wp-config.php', $wp_config_data_replaced);
}

// Get a mysql connection object
function get_mysql($config) {
	$mysql_host = $config['mysql_host'];
	$mysql_port = (empty($config['mysql_port'])? "" : $config['mysql_port']);
	$mysql_username = $config['mysql_user'];
	$mysql_password = $config['mysql_pass'];
	$mysql_database = (isset($config['mysql_db'])) ? $config['mysql_db'] : null;
	
	// Connect to MySQL server
	try {
		$db = new SnapifyDB($mysql_host, $mysql_username, $mysql_password, $mysql_database, $mysql_port);
		
	} catch (Exception $e) {
		set_errors('Error connecting to MySQL server: ' . $e->getMessage());
		return;
	}
	if (!$db) {
		set_errors('Error connecting to MySQL server: ' . $db->error());
	}
	
	Debug::log('Clearing SQL mode of current session');
	
	// Remove any strict MySQL mode from current session
	if (!$db->query('SET SESSION sql_mode = ""')) {
		set_errors('Failed to change sql mode [' . $db->errno() . '] ' . $db->error());
	} else {
		Debug::log('SQL mode has been cleared');
	}
	
	// get encoding of DB connection - should be the same as the old WP
	$old_config = get_config();
	if (!$db->set_charset($old_config['db_connection_encoding'])) {
		$error_msg = "Bad character set detected (" . $old_config['db_connection_encoding'] ."), please backup again with 'Force UTF8'. ";
		set_errors($error_msg);
	}
	return $db;
}

// Display error on sql
function handle_db_errors(&$db, $query_line) {
	// Shorten the error message if necessary
	$partial_error_sql_query = $query_line;
	if(strlen($partial_error_sql_query) > 32) {
		$partial_error_sql_query = substr($partial_error_sql_query, 0, 32) . '... (too long)';
	}
	$partial_error_sql = $db->error();
	if(strlen($partial_error_sql) > 32) {
		$partial_error_sql = substr($partial_error_sql, 0, 32) . '... (too long)';
	}
	set_errors('Halting import (' . $db->errno() . '), error performing query \'' . $partial_error_sql_query . '\': ' . $partial_error_sql . '<br />');
	Debug::log("Error MYSQL import:" . $query_line .', result: '. $db->error());
}

// Rewrite the newly created htaccess file
// with the new host path
function rewrite_htaccess() {
	// Get the path structure
	$config = get_config();
	$old_path = $config['old_path'];

	$current_url = parse_url($config['old_proto'] . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
	$new_path = dirname($current_url['path']);

	$old_htaccess = file_get_contents("./.htaccess");
	$edited_htaccess = str_replace($old_path, $new_path, $old_htaccess);

	Debug::log('rewrite_htaccess - old:' . var_export($old_htaccess, true) . ', new:' . var_export($edited_htaccess,true));
	// Put the new edited htaccess in place
	file_put_contents("./.htaccess", $edited_htaccess);
}

?>