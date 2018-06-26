<?php

class Debug {

	const MICROTIME_AFTER_DECIMAL_LENGTH = 2;
	const SECTION_SEPERATOR = '-----';

	private static $handle = null;
	private static $start_time = 0;

	// initialize new debug log session
	public static function init($log_path, $reset_file = false) {
		$flag = ($reset_file) ? 'wb' : 'ab';
		self::$handle = fopen($log_path, $flag);
		self::$start_time = microtime(true);
		if($reset_file) {
			self::add_server_extensions();
			self::add_server_php_settings();
			self::add_current_environment();
			
			// add WordPress version (if in plugin)
			global $wp_version;
			if ($wp_version) {
				self::add_wordpress_version();
			}
		}
	}

	// close debug file handler
	public static function close() {
		if (is_resource(self::$handle)) {
			fclose(self::$handle);
		}		
	}

	// log curernt action
	public static function log($data) {
		if (!self::$handle) {
			throw new Exception('No handler set for debug log');
		}
		$log_time_from_start = round(microtime(true) - self::$start_time, self::MICROTIME_AFTER_DECIMAL_LENGTH);
		fwrite(self::$handle, "{$data}    [time from start: {$log_time_from_start}]" . PHP_EOL);
	}

	// log an entire section at once
	public static function log_section($section_name, $data) {
		self::log(self::SECTION_SEPERATOR . " {$section_name} " . self::SECTION_SEPERATOR . PHP_EOL . $data . PHP_EOL . str_repeat(self::SECTION_SEPERATOR, 5) . PHP_EOL);
	}

	// add all loaded extensions into debug log file
	private static function add_server_extensions() {
		$extensions = implode(',', get_loaded_extensions());
		self::log("Extensions Loaded: {$extensions}");
	}

	// add php.ini file into debug log file
	private static function add_server_php_settings() {
		self::log_section('PHP INI', var_export(ini_get_all(), true));
	}

	private static function add_current_environment() {
		$pwd = getcwd();
		self::log("Current Dir: {$pwd}");
	}
	
	private static function add_wordpress_version() {
		global $wp_version;
		self::log("WordPress version: {$wp_version}");
	}
}

?>
