<?php

class SnapifyDB
{
	const MYSQL_DEFAULT_PORT = 3306;
	protected $result_functions = array('fetch', 'num_fields');

	protected $conn = null;
	protected $mysql_lib = null;

	protected $host = null;
	protected $username = null;
	protected $password = null;
	protected $database = null;
	protected $port = null;
	
	protected $auto_retries = 1;

	public function __construct($host, $username, $password, $database=null, $port=self::MYSQL_DEFAULT_PORT) {
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;
		$this->port = $port;

		if (stripos($this->host, ':') !== false) {
			$mysql_host = explode(':', $host);
			$this->host = $mysql_host[0];
			$this->port = $mysql_host[1];
		}

		if (extension_loaded('mysqli')) {
			$this->mysql_lib = 'mysqli';
		}
		elseif (extension_loaded('mysql')) {
			$this->mysql_lib = 'mysql';
		} else {
			throw new Exception('None of MySQL extension exists on this system, please install them.');
		}
		$this->conn = $this->connect();
	}

	// set the amount of times SnapifyDB will retry to do a query
	public function set_auto_retry($auto_retries = 1) {
		$this->auto_retries = $auto_retries;
		Debug::log("Auto retry set to: {$auto_retries}");
	}
	
	
	// instead of implementing each MySQL engine, use this for "magic" call, simply wrapping between mysql and mysqli libs
	public function __call($function_name, $arguments) {
		return $this->call($function_name, $arguments);
	}
	
	// return encoding of current mySQL session
	public function get_encoding() {
		if ($this->is_mysqli()) {
			$charset = mysqli_get_charset($this->conn);
			return $charset->charset;
		}
		return mysql_client_encoding($this->conn);
	}
	
	// just wrap to __call method to have 3rd parameter for auto retries to work
	protected function call(&$function_name, &$arguments, $auto_retries_counter = 0) {
		try {
			if ($this->is_result_function($function_name)) {
				return $this->call_mysql_lib_function($function_name, $arguments);
			}
			
			if ($this->is_mysqli()) {
				array_unshift($arguments, $this->conn);
			} else {
				$arguments[] = $this->conn;
			}
			
			return $this->call_mysql_lib_function($function_name, $arguments);
		} catch (Exception $e) {
			// if first exception, retry with new connection
			if ($this->auto_retries > $auto_retries_counter) {
				Debug::log('SnapifyDB error! function: ' . $function_name . ' with arguments: ' . var_export($arguments, true));
				$this->conn = $this->connect($this->host, $this->username, $this->password, $this->database, $this->port);
				$this->call($function_name, $arguments, $auto_retries_counter + 1);
			}
		}
	}
	
	// call requested function with parameters
	protected function call_mysql_lib_function(&$function_name, &$arguments) {
		return call_user_func_array($this->mysql_lib . '_' . $function_name, $arguments);
	}
	
	// handle result function for example:
	// handle fetch functions before adding database link identifier
	// this used since query return Resourse/Object that can be standalone and not related to database link identifier
	protected function is_result_function($function_name) {
		foreach ($this->result_functions as $result_function) {
			if (false !== strpos($function_name, $result_function)) {
				return true;
			}
		}
		return false;
	}
	
	// create connection with database using givien credentials
	protected function connect() {
		$error_msg = null;
		if ($this->is_mysqli()) {
			$this->conn = mysqli_connect($this->host, $this->username, $this->password, $this->database, $this->port);
			$error_msg = mysqli_connect_error();
		} else {
			$this->conn = mysql_connect($this->host . ':' . $this->port, $this->username, $this->password);
			$error_msg = mysql_error();
		}

		if (!$this->conn) {
			throw new Exception($error_msg);
		}
		
		// in case need to select database on mysql
		if (!$this->is_mysqli() && null !== $this->database) {
			if (!mysql_select_db($this->database)) {
				throw new Exception('Selecting database failed - ' . mysql_error($this->conn));
			}
		}
		return $this->conn;
	}
	
	// return true if requested to use mysqli lib
	protected function is_mysqli() {
		return $this->mysql_lib === 'mysqli';
	}
}
?>
