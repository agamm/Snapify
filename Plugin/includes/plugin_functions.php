<?php
// return array of all directories inside $source
function snapify_get_all_directories($source) {
	$directories = array('.' => 0);
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), 
			RecursiveIteratorIterator::SELF_FIRST);

	foreach ($files as $file) {
		if (in_array(substr($file, strrpos($file, DIRECTORY_SEPARATOR) + 1), array('.', '..'))) {
			continue;
		}
		
		$file_name = str_replace($source . DIRECTORY_SEPARATOR, '', $file);
		if (is_dir($file)) {
			$directories[$file_name] = 0;
		} else {
			$directories[dirname($file_name)]++;
		}
	}
	return $directories;
}

// Compress selected directory relative to $home_path
function snapify_compress_directory(&$zip, $source, $skip = 0) {
	Debug::log('Starting zipping');
	if (!extension_loaded('zip')) {
		$error_msg = 'Missing "zip" extension, please make sure php has the zip extension - plugin can\'t work properly';
		Debug::log($error_msg);
		throw new Exception($error_msg);
	}

	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST); 
	$compressed_files_counter = 0;
	foreach ($files as $file) {
		// usleep(2000);
		if (in_array(substr($file, strrpos($file, DIRECTORY_SEPARATOR) + 1), array('.', '..'))) {
			continue;
		}

		$file_name = str_replace($source . DIRECTORY_SEPARATOR, '', $file);
		if (is_dir($file) === true) {
			$file_name .= DIRECTORY_SEPARATOR;
		}
		
		// replace back-slash in forward slash for windows os (zip stream libary requirement.. UNIX style)
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$file_name = str_replace("\\", '/', $file_name);
		}

		// if dir - add dir
		if ('/' === substr($file_name, -1)) {
			$zip->addEmptyDir($file_name);
			continue;
		}
		
		if ($skip > 0) {
			$skip--;
			continue;
		}
		
		$zip->addFile($file, $file_name);
		$compressed_files_counter++;
		
		if (0 === $compressed_files_counter % SNAPIFY_FILES_UPDATE_RATE) {
			snapify_update_progress(SNAPIFY_FILES_UPDATE_RATE);
		}
		
		if ($compressed_files_counter === SNAPIFY_FILES_PRE_REQUEST_LIMIT) {
			break;
		}
	}
	
	return $compressed_files_counter;
}

// Zip the entire website while streaming it 
function snapify_zip_stream($source, &$zip) {
	Debug::log('Starting zip stream');
	if (!extension_loaded('mbstring')) {
		$error_msg = 'Missing "mbstring" extension, please make sure php has the mbstring extension - plugin can\'t work properly';
		Debug::log($error_msg);
		throw new Exception($error_msg);
	}
	
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($files as $file) {
		if (in_array(substr($file, strrpos($file, DIRECTORY_SEPARATOR) + 1), array('.', '..'))) {
			continue;
		}

		$file_name = str_replace($source . DIRECTORY_SEPARATOR, '', $file);
		if (is_dir($file) === true) {
			$file_name .= DIRECTORY_SEPARATOR;
		}
		
		// replace back-slash in forward slash for windows os (zip stream libary requirement.. UNIX style)
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$file_name = str_replace("\\", '/', $file_name);
		}

		// if dir - add dir
		if ('/' === substr($file_name, -1)) {
			$zip->addDirectoryFromStream($file_name);
			continue;
		}

		$zip->addFileFromStreamStart($file_name);
		$handle = fopen($file, 'rb');
		$data = null;
		while (!feof($handle)) {
			$data = fread($handle, 4096);
			$zip->addFileFromStreamChunk($data);
			unset($data);
		}
		fclose($handle);
		$zip->addFileFromStreamEnd();
	}
}

// Put new progress into the ajax progress file
function snapify_update_progress($progress) {
	$old_progress = snapify_get_progress();
	file_put_contents(SNAPIFY_TEMP_PATH_PROGRESS, $old_progress + $progress);
}

// Set all ini configuration
function snapify_ini_set($log = false) {
	$ini_set = array(
		'max_execution_time' => SNAPIFY_MAX_EXECUTION_TIME_SECS,
		'mysql.connect_timeout' => SNAPIFY_MAX_EXECUTION_TIME_SECS,
		'default_socket_timeout' => SNAPIFY_MAX_EXECUTION_TIME_SECS
	);

	$is_set_time_limit = set_time_limit(0);

	// change ini settings and log them
	if ($log) {
		Debug::log('Set time limit ' . ($is_set_time_limit ? 'Succeed' : 'Failed'));
	}

	foreach ($ini_set as $key => $value) {
		$result = false !== ini_set($key, $value);
		if ($log) {
			Debug::log("Set ini option: {$key} " . ($result ? 'Succeed' : 'Failed'));
		}
	}
}

// Fetch progress from the ajax progress file
function snapify_get_progress() {
	if(!file_exists(SNAPIFY_TEMP_PATH_PROGRESS)) {
		return 0;
	}
	return intval(file_get_contents(SNAPIFY_TEMP_PATH_PROGRESS));
}

// Clean all snapify temp files
function snapify_clean_tmp_file() {
	$tmp_files = array(SNAPIFY_TEMP_PATH_PROGRESS_DETAILS, SNAPIFY_TEMP_PATH_PROGRESS, SNAPIFY_TEMP_PATH_TMP_SQL_DUMP, SNAPIFY_TEMP_PATH_TMP_PATH_SITE);
	foreach ($tmp_files as $file_path) {
		if (file_exists($file_path)) {
			@unlink($file_path);
		}
	}
}

// Fix the cPanel directory conventions
function snapify_sys_get_temp_directory() {
	$tmp_dir = sys_get_temp_dir();
	$is_cpanel = @file_exists('/usr/local/cpanel/version');
	if($is_cpanel) {
		$tmp_dir = '/home/'. get_current_user() . '/tmp';
		if(!is_writable($tmp_dir)) {
			die('Please contact Simple360 for further support, you\'r temp directory is inaccessible');
		}
	}

	return $tmp_dir;
}

// Open archive
function snapify_open_archive($path, $flags = ZIPARCHIVE::CREATE) {
	if (!extension_loaded('zip')) {
		$error_msg = 'Missing "zip" extension or a source file doesn\'t exist - plugin can\'t work properly';
		Debug::log($error_msg);
		throw new Exception($error_msg);
	}

	$zip = new ZipArchive();
	if (!$zip->open($path, $flags)) {
		$error_msg = "Can't create zip archive at destination path: {$path}";
		Debug::log($error_msg);
		throw new Exception($error_msg);
	}

	return $zip;
}


// Function to initiate an HTTP download for a file on the server
function snapify_download_file($file_path, $bandwith_limit=0, $save_name=null) {
	Debug::init(SNAPIFY_PATH_DEBUG_LOG);

	$real_path = realpath($file_path);
	if (!$real_path) {
		Debug::log("Download file {$file_path} failed, file inaccessible");
		throw new Exception("File not found at: {$real_path}");
	}

	if (!$save_name) {
		$save_name = basename($real_path);
	}

	$file_size = filesize($real_path);
	$sent_bytes = 0;
	$CHUNK_SIZE = 8192;

	// send correct content-type header
	if ('zip' === substr(basename($file_path), 0, -3)) {
		header('Content-Type: application/zip');
	} else {
		header('Content-Type: text/plain');
	}

	header("Content-Disposition: attachment; filename={$save_name}");
	header('Cache-Control: private');
	header('Pragma: public');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	header('Content-Transfer-Encoding: binary');
	header("Content-Length: {$file_size}");

	$file_handler = fopen($real_path, 'rb');
	Debug::log("Downloading file {$real_path} ({$file_size} bytes) with the name {$save_name}");
	while (!feof($file_handler) && !connection_aborted()) {
		echo fread($file_handler, $CHUNK_SIZE);
		flush();
		$sent_bytes += $CHUNK_SIZE;
		if ($bandwith_limit && $sent_bytes >= $bandwith_limit) {
			sleep(1);
			$sent_bytes = 0;
		}
	}

	Debug::log('Download complete!');
	fclose($file_handler);
}

function snapify_installer_download() {
	snapify_download_file(SNAPIFY_INSTALLER_PATH);
}

// return the configuration snapify need
function snapify_generate_configuration_file($db_conf, $home_path, $is_ssl) {
	global $wpdb;

	// create configuration file
	$db = new SnapifyDB($db_conf['host'], $db_conf['username'], $db_conf['password'], $db_conf['name']);
	$proto = $is_ssl ? 'https://' : 'http://';
	$conf = array(
		'old_proto' => $proto,
		'old_host' => $_SERVER['HTTP_HOST'],
		'old_path' => substr($home_path, strlen($proto . $_SERVER['HTTP_HOST'])),
		'db_connection_encoding' => $db_conf['charset'],
		'db_prefix' => $db_conf['prefix']
	);


	// if installed on the http root dir
	if (!$conf['old_path']) {
		$conf['old_path'] = '';
	}

	Debug::log('Configuration: ' . var_export($conf, true));
	return str_replace('\\/', '/', json_encode($conf));
}

?>
