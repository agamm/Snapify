<?php
require_once 'includes' . DIRECTORY_SEPARATOR  . 'snapifydb.class.php';
require_once 'includes' . DIRECTORY_SEPARATOR  . 'mysqldump.class.php';
require_once 'includes' . DIRECTORY_SEPARATOR  . 'debug.class.php';
require_once 'includes' . DIRECTORY_SEPARATOR  . 'plugin_functions.php';
require_once 'includes' . DIRECTORY_SEPARATOR  . 'conf.php';
require_once 'includes' . DIRECTORY_SEPARATOR  . 'lib' . DIRECTORY_SEPARATOR . 'zipstream' . DIRECTORY_SEPARATOR . 'ZipStream.php';

function run_ajax($action, $value, $backup_details) {
	$db_conf = $backup_details['db']['conf'];
	switch ($action) {
		case 'get-progress':
			return snapify_get_progress();
		
		case 'dump-tables-structure':
			$mysqldump = new MySQLDump($db_conf['host'], $db_conf['username'], $db_conf['password'], $db_conf['name'], $db_conf['charset']);
			$mysqldump->set_dump_file(SNAPIFY_TEMP_PATH_TMP_SQL_DUMP);
			Debug::log('Dumping all tables structure');
			$tables = $mysqldump->get_tables();
			
			foreach ($tables as $table_name) {
				$mysqldump->write_table_structure($table_name);
				Debug::log("Dump structre of {$table_name}");
			}
			
			snapify_update_progress(count($tables));
			return snapify_get_progress();
	
		case 'dump-table-data':
			$table_name = $value;
			$mysqldump = new MySQLDump($db_conf['host'], $db_conf['username'], $db_conf['password'], $db_conf['name'], $db_conf['charset']);
			$mysqldump->set_dump_file(SNAPIFY_TEMP_PATH_TMP_SQL_DUMP);
			Debug::log("Dumping table: {$table_name}");
			
			// add unwritten rows to overall counter
			snapify_update_progress(($mysqldump->write_table_data($table_name) % SNAPIFY_DB_UPDATE_RATE));
			return snapify_get_progress();
			
		case 'compress-database':
			Debug::log('Compressing database into zip');
			$zip = snapify_open_archive(SNAPIFY_TEMP_PATH_TMP_PATH_SITE);
			$zip->addFile(SNAPIFY_TEMP_PATH_TMP_SQL_DUMP, basename(SNAPIFY_TEMP_PATH_TMP_SQL_DUMP));
			$zip->close();
			// remove sql file, no need for it any more
			@unlink(SNAPIFY_TEMP_PATH_TMP_SQL_DUMP);
			return true;
			
		case 'compress-files':
			$skip = intval($value);
			Debug::log("Compressing files start from: {$skip}");
			$zip = snapify_open_archive(SNAPIFY_TEMP_PATH_TMP_PATH_SITE);
			snapify_update_progress(snapify_compress_directory($zip, $backup_details['homePath'], $skip) % SNAPIFY_FILES_UPDATE_RATE);
			$zip->close();
			return snapify_get_progress();
			
		case 'add-configuration':
			Debug::log('Adding snapify configuration file');
			$zip = snapify_open_archive(SNAPIFY_TEMP_PATH_TMP_PATH_SITE);
			$zip->addFromString('snapify.conf', snapify_generate_configuration_file($db_conf, $backup_details['homePath'], $backup_details['isSSL']));
			$zip->close();
			return true;
		
		default:
		   return "Unknown action: {$_POST['action']}";
	}
}

function stream_backup($backup_details) {
	$db_conf = $backup_details['db']['conf'];
	
	$zip = new ZipStream\ZipStream('snapify.zip', array('large_file_method' => 'store'));
	if ($backup_details['bandwithLimit']) {
		$zip->set_bandwith_limit($backup_details['bandwithLimit']);
	}
	Debug::log('Zip steeam object created successfully');

	try {
		// add database dump
		Debug::log('Add db to stream..');
		$zip->addFileFromStreamStart('snapify.sql');
		$mysqldump = new MySQLDump($db_conf['host'], $db_conf['username'], $db_conf['password'], $db_conf['name'], $db_conf['charset']);
		$mysqldump->set_stream($zip);
		$mysqldump->full_dump();
		$zip->addFileFromStreamEnd();
	} catch (Exception $e) {
		Debug::log('Exception in MySQL! ' . var_export($e, true));
		die('Query error: ' . $e->getMessage());
	}

	Debug::log('Add configuration to stream..');
	$zip->addFile('snapify.conf', snapify_generate_configuration_file($db_conf, $backup_details['homePath'], $backup_details['isSSL']));

	// adding files
	Debug::log('Add files to stream..');
	snapify_zip_stream($backup_details['homePath'], $zip);

	Debug::log('Closing zip..');
	$zip->finish();
}

try {
	if (SNAPIFY_DISPLAY_ERRORS === false) {
		error_reporting(0);
		ini_set('display_errors', 'off');
	} else {
		error_reporting(E_ALL);
		ini_set('display_errors', 'on');
	}
	
	Debug::init(SNAPIFY_PATH_DEBUG_LOG);
	snapify_ini_set();

	$backup_details = json_decode(file_get_contents(SNAPIFY_TEMP_PATH_PROGRESS_DETAILS), true);
	
	if (!isset($_POST['token'])) {
		Debug::log('Token not found in POST');
		die('Missing token!');
	}
	
	if ($backup_details['token'] !== $_POST['token']) {
		Debug::log('Token mismatch! server expected ' . var_export($backup_details['token']) . ' but got: ' . var_export($_POST['token'], true));
		die('Bad token');
	}
	
	if (!isset($_POST['action'])) {
		Debug::log('No action sent');
		die('No action sent');
	}
	
	// check if request to download backup
	if ('download_backup' === $_POST['action']) {
		return snapify_download_file(SNAPIFY_TEMP_PATH_TMP_PATH_SITE, $backup_details['bandwithLimit']);
	} elseif ('stream_backup' === $_POST['action']) {
		return stream_backup($backup_details);
	}
	
	// check if normal zip
	if (!isset($_POST['value'])) {
		Debug::log('Missing value for action, POST values got: ' . var_export($_POST, true));
		die('Missing parameters');
	} else {
		echo json_encode(run_ajax($_POST['action'], $_POST['value'], $backup_details));
	}
} catch (Exception $e) {
	echo $e->getMessage();
}
?>
