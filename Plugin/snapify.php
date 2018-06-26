<?php
/**
 * Plugin Name: Snapify
 * Plugin URI:  https://codecanyon.net/user/simple360
 * Description: Simple WordPress Backup and Move Script - One Click Install
 * Author:      Simple360
 * Author URI:  https://codecanyon.net/user/simple360
 * Version:     1.3.3
 * Text Domain: snapify
 */
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'snapifydb.class.php';
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'mysqldump.class.php';
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'debug.class.php';
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'plugin_functions.php';
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'conf.php';
require_once plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR  . 'lib' . DIRECTORY_SEPARATOR . 'zipstream' . DIRECTORY_SEPARATOR . 'ZipStream.php';

define('SNAPIFY_INSTALLER_PATH', plugin_dir_path(__FILE__) . 'includes' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'snapify.php');

add_action('admin_menu', 'snapify_plugin_menu');
add_action('admin_action_snapify_backup', 'snapify_backup');
add_action('admin_action_snapify_installer_download', 'snapify_installer_download');
add_action('contextual_help', 'snapify_contextual_help', 10, 3);

add_action('wp_ajax_snapify_prepare_backup_ajax', 'snapify_prepare_backup_ajax');

if(SNAPIFY_DISPLAY_ERRORS) {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
}

function snapify_plugin_menu() {
	if (!is_admin()) {
		wp_die(SNAPIFY_NO_PREMISSIONS_MSG);
	}

	if (isset($_GET['debug']) && file_exists(SNAPIFY_PATH_DEBUG_LOG)) {
		snapify_download_file(SNAPIFY_PATH_DEBUG_LOG);
	}

	add_management_page('Snapify', 'Snapify', 'manage_options', SNAPIFY_MAIN_MENU_PAGE_NAME, 'snapify_plugin_page');
}

function snapify_prepare_backup_ajax() {
	global $wpdb;
	
	Debug::init(SNAPIFY_PATH_DEBUG_LOG, true);
	if (!is_admin()) {
		wp_die(SNAPIFY_NO_PREMISSIONS_MSG);
	}
	
	snapify_clean_tmp_file();
	snapify_ini_set(true);
	$required_extensions = array(
		'mbstring' => extension_loaded('mbstring'),
		'zip' => extension_loaded('zip')
	);
	
	// get all database information
	$db_rows = 0;
	$mysqldump = new MySQLDump(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
	$tables_list = $mysqldump->get_tables();
	$tables_details = array();

	foreach ($tables_list as $table_name) {
		$table_rows_count = $mysqldump->count_table_rows($table_name);
		$tables_details[$table_name] = $table_rows_count;
		$db_rows += $table_rows_count;
	}
	
	$directories = snapify_get_all_directories(realpath(get_home_path()));
	$files_count = 0;
	foreach ($directories as $directory_files_count) {
		$files_count += $directory_files_count;
	}
	
	$backup_details =  array(
		'files' => array(
			'directories' => $directories,
			'count' => $files_count
		),
		'db' => array(
			'tables_count' => count($tables_details),
			'rows' => $db_rows,
			'tables' => $tables_details,
			'conf' => array(
				'host' => DB_HOST,
				'username' => DB_USER,
				'password' => DB_PASSWORD,
				'name' => DB_NAME,
				'charset' => DB_CHARSET,
				'prefix' => $wpdb->prefix
			)
		),
		'overallProgress' => $files_count + $db_rows + count($tables_details),
		'token' => md5(get_home_path() . uniqid(SNAPIFY_TEMP_PATH_PROGRESS_DETAILS) . SNAPIFY_NO_PREMISSIONS_MSG),
		'homePath' => realpath(get_home_path()),
		'isSSL' => is_ssl(),
		'extensionsLoaded' => $required_extensions,
		// 'bandwithLimit' => intval($_POST['bandwithLimit']) * 1024 - this disable's the bandwith limit feature. to reactivate it, simple display the input and get the POST value
		'bandwithLimit' => 0,
		'compressLimit' => SNAPIFY_BACKUP_METHOD_COMPRESS_LIMIT
	);
	
	file_put_contents(SNAPIFY_TEMP_PATH_PROGRESS_DETAILS, json_encode($backup_details));
	Debug::log('Preparing result: ' . var_export($backup_details, true));
	
	// remove credentials of database, client don't need that kind of information
	unset($backup_details['db']['credentials']);
	echo json_encode($backup_details);
	die;
}

function snapify_plugin_page() {
	wp_enqueue_style('snapify_css', plugins_url('admin/css/plugin.css', __FILE__), array(), SNAPIFY_VERSION);
	wp_enqueue_script('snapify.js', plugins_url('admin/js/snapify.js', __FILE__), array(), SNAPIFY_VERSION);
	wp_localize_script( 'snapify.js', 'ajax_object', array( 'ajaxurl' => admin_url( 'admin_ajax.php' ) ) );
	include plugin_dir_path(__FILE__) . '/includes/snapify-page.php';
}


// display help for client
function snapify_contextual_help($contextual_help, $screen_id, $screen) {
	$screen->add_help_tab( array(
		'id' => 'help_tab_backup',
		'title' => __('How do I backup?'),
		'content' => '<p>' . __( '<p>Click on the Green Backup button and wait for it to generate a backup.</p><p> Then you will get a download prompt, This is your backup! After that make sure to download the installer so you could restore the backup you\'ve just downloaded.</p>' ) . '</p>',
	));
	$screen->add_help_tab( array(
		'id' => 'help_tab_backup',
		'title' => __('Backup Method Explained?'),
		'content' => '<p>' . __( '<p><u>Compressed Download</u> - will compress your files on the server and then initiate a backup process</p><p><u>Stream Download</u> - will compress your files on the fly and start the download imminently (good for large sites, that don\'t have space)</p><p><u>Database Backup Only</u> - will not include all of your files, but will include your database contents. Then you can add your files yourself (via ftp/scp download) and add them to the snapify.zip, used mainly in debug situations.</p>' ) . '</p>',
	));
	$screen->add_help_tab( array(
		'id' => 'my_help_restore',
		'title' => __('How Do I Move and Restore My Site?'),
		'content' => '<p>' . __( '<p>Upload your backup files to your new server.</p><p> Browse to the snapify.php file and start installing.</p>' ) . '</p>',
	));
	$screen->add_help_tab( array(
		'id' => 'my_help_tab3',
		'title' => __('My Problem is Not Mentioned Here'),
		'content' => '<p>' . __( '<p>Please contact us at: <a href="https://codecanyon.net/user/simple360">codecanyon</a>' ) . '</p>',
	));
}
?>
