<?php
/**
* class used to import sql file into MySQL
*/
class MySQLImporter {

	protected $host = '';
	protected $username = '';
	protected $password = '';
	protected $db_name = '';
	protected $encoding = '';

	protected $progress_status = array('current' => 0, 'overall' => 0);

	protected $should_force_utf8 = false;
	protected $should_alter_innodb = false;

	protected $db_conn = null;

	// list of dependency tables, will be recall before insert data phase
	protected $dep_tables_queries = array();
	protected $redo_queries_temp_file = null;


	// bind error code to functions - error handlers
	protected $known_error_handlers = array(
		// handle forgien keys problems (dep tables) - if foreign key constraint was not correctly formed (more documafunction in foreign_key_error_handler)
		1005 => 'foreign_key',
		1215 => 'foreign_key',

		// handle encoding error - unknown collation
		1273 => 'encoding',

		// handle error in insert new lines as a child row: a foreign key constraint fails
		1452 => 'insert_child',

		// handle row too big caused by innodb_log_file_size variable
		1118 => 'innodb_log_size',

		// handle packet too big
		1153 => 'big_packet'
	);

	const DEPENDENCY_TABLES_RUN_MARK_MAGIC = 'INSERT INTO';
	const PROGRESS_PRECENTAGE_PRECISION = 2;

	function __construct($host, $username, $password, $db_name = null, $port = SnapifyDB::MYSQL_DEFAULT_PORT) {
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
		$this->db_name = $db_name;
		$this->port = $port;

		$this->redo_queries_temp_file = new TempFile(sys_get_temp_directory() . DIRECTORY_SEPARATOR . 'snapify.resql');

		try {
			update_progress("Connecting to database...");
			$this->db_conn = new SnapifyDB($this->host, $this->username, $this->password, null, $this->port);
		} catch (Exception $e) {
			Debug::log('Error connecting to MySQL server: ' . $e->getMessage());
			return set_errors('Error connecting to MySQL server: ' . $e->getMessage());
		}

		Debug::log('Clearing SQL mode of current session');

		// remove any strict MySQL mode from current session
		if (!$this->db_conn->query('SET SESSION sql_mode = ""')) {
			set_errors('Failed to change sql mode [' . $this->db_conn->errno() . '] ' . $this->db_conn->error());
		} else {
			Debug::log('SQL mode has been cleared');
		}

		Debug::log('Database existence: ' . ($this->is_database_exists() ? 'Yes' : 'No'));
		// if database already exists select it, if not, create it
		if (!$this->is_database_exists()) {
			Debug::log('Database not exists, trying to create one: ' . $this->db_name);
			$this->create_database();
		}
		if (!$this->db_conn->select_db($this->db_name)) {
			handle_db_errors($this->db_conn, '** SELECTING DATABASE **');
		} else {
			Debug::log('Database contains tables: ' . ($this->is_database_contains_tables() ? 'Yes' : 'No'));
		}
	}

	// set the encoding of current mysql connection session
	public function set_connection_encoding($encoding) {
		$this->encoding = $encoding;
		if (!$this->db_conn->set_charset($this->encoding)) {
			$error_msg = "Bad character set detected (" . $this->encoding ."), please try to reinstall snapify with Force UTF-8 flag on";
			set_errors($error_msg);
			return false;
		}
		return true;
	}

	// set wheather mysql importer
	public function set_force_utf8($flag) {
		$this->should_force_utf8 = $flag;
	}

	public function set_innodb_alter($flag) {
		$this->should_alter_innodb = $flag;
	}

	// remove all tables in database
	public function truncate_db() {
		Debug::log('Truncating database - override..');
		update_progress("Clearing database...");
		$query = $this->db_conn->query('SHOW TABLES');
		while ($row = $this->db_conn->fetch_row($query)) {
			if (!$this->db_conn->query("DROP TABLE `{$row[0]}`;")) {
				set_errors('Could not clear database, failed dropping table: ' . $row[0] . ' ' . $this->db_conn->error());
			}
		}
	}

	// reutrn MySQL max_allowed_packet global variable
	public function get_mysql_max_packet_size() {
		$sql = 'SELECT @@global.max_allowed_packet';
		$query = $this->db_conn->query($sql);
		if (!$query) {
			handle_db_errors($this->db_conn, $sql);
			return null;
		}

		$result = $this->db_conn->fetch_row($query);
		return intval($result[0]);
	}

	// perform import of all database
	public function import($path, $is_redo = false) {
		if (!$is_redo) {
			$this->progress_status['overall'] = $this->count_sql_lines($path);
		}

		$sql_file_handle = fopen($path, 'r');
		while ($sql_query = $this->get_query($sql_file_handle)) {
			// replace unserialized data (in case it serialized)
			$this->replace_unserialized_data($sql_query);

			// force utf-8 on query
			if ($this->should_force_utf8) {
				$sql_query = $this->force_encoding($sql_query);
			}

			// check if there is any dep tables
			if ($this->should_run_dep_tables($sql_query)) {
				if (!$this->run_dep_tables()) {
					break;
				}
			}

			// do the actual query, in case of failure, check if it is because of misordered table creation
			if (!$this->import_query($sql_query)) {
				fclose($sql_file_handle);
				return false;
			}
		}

		Debug::log('is redo: ' . ($is_redo ? 'yes' : 'no') . '  -  redo file size: ' . $this->redo_queries_temp_file->get_size());
		if (!$is_redo && !$this->redo_queries_temp_file->is_empty()) {
			Debug::log('Calling to redo some queries, redo queries file size: ' . $this->redo_queries_temp_file->get_size());
			return $this->import($this->redo_queries_temp_file->get_path(), true);
		}
		return true;
	}

	/**
	* execute query passed in parameter. in case of known error function will call the error handler set in class configuration.
	* @param string $sql_query - sql text to execute
	* @return boolean
	*/
	protected function import_query($sql_query) {
		if(!$this->db_conn->query($sql_query)) {
			// is there any erorr handles for this error code
			if ($this->is_known_error()) {
				return $this->run_error_code_handler($sql_query);
			} else {
				handle_db_errors($this->db_conn, $sql_query);
			}
			return false;
		} else {
			$this->update_progress_status($sql_query);
		}
		return true;
	}

	/**
	* function check if database error code is known one and has none default handler
	* @return boolean
	*/
	protected function is_known_error() {
		return in_array($this->db_conn->errno(), array_keys($this->known_error_handlers), true);
	}

	/**
	* function run function that handle current database error code, return false in case handler function can't handle this kind of error and require import process to stop
	* @param string $sql_query - sql query that run
	* @return boolean
	*/
	protected function run_error_code_handler($sql_query) {
		$db_error_code = $this->db_conn->errno();
		$funciton_handler_name = $this->known_error_handlers[$db_error_code] . '_error_handler';
		return $this->$funciton_handler_name($sql_query);
	}

	// update the WordPress Database entries
	// with the new host
	public function update_wp_db($config) {
		update_progress("Replacing DB entries...");

		$db_prefix = $config['db_prefix'];
		$hosts = $this->get_hosts($config);
		$old_host = $hosts[0];
		$new_host = $hosts[1];


		$queries = array(
			"UPDATE {$db_prefix}options SET option_value = '$new_host' WHERE option_name = 'home' OR option_name = 'siteurl';",
			"UPDATE {$db_prefix}posts SET guid = replace(guid, '{$old_host}','{$new_host}');",
			"UPDATE {$db_prefix}posts SET post_content = replace(post_content, '{$old_host}', '{$new_host}');",
			"UPDATE {$db_prefix}postmeta SET meta_value = replace(meta_value,'{$old_host}','{$new_host}');"
		);

		// update wp's database in bulk
		foreach ($queries as $query) {
			if(!$this->db_conn->query($query)) {
				Debug::log('Error in update_wp_db: \'' . $query . '\', result: ' . $this->db_conn->error());
				handle_db_errors($this->db_conn, $query);
			}
		}
	}

	public function is_database_exists() {
		$sql = "SHOW DATABASES like '{$this->db_name}'";
		$query = $this->db_conn->query($sql);
		if (!$query) {
			handle_db_errors($this->db_conn, $sql);
		}
		return false != $this->db_conn->fetch_row($query);
	}

	public function is_database_contains_tables() {
		$sql = 'SHOW TABLES';
		$query = $this->db_conn->query($sql);
		if (!$query) {
			handle_db_errors($this->db_conn, $sql);
		}
		return false != $this->db_conn->fetch_row($query);
	}

	// return sql query, do this by searching for ; at the end of the line
	protected function get_query(&$sql_file_handle) {
		$query_line = '';
		$line = fgets($sql_file_handle);
		while (!feof($sql_file_handle) && substr(trim($line), -1, 1) !== ';') {
			$query_line .= $line;
			$line = fgets($sql_file_handle);
		}

		// spaces and new lines at the end of the file without any real query to run (basically, end of file)
		if (!substr(trim($line), -1, 1)) {
			return null;
		}
		return trim($query_line . $line);
	}

	// check if this has the possibility to be unserialized
	protected function replace_unserialized_data($data) {
		if(strpos($data, "{s:") !== false) {
			return preg_replace_callback(
				'/s:[0-9]+:\".*?\";/',
				array($this, 'replace_callback_handler'),
				$data);
		}
	}

	// try to open and replace
	// a php serialized string
	protected function replace_callback_handler($matches) {
		$current_match = $matches[0];

		// Fix the serialize wrapper at the en)d
		$count_serializes = preg_match_all('/s:[0-9]+:\"/', $current_match);
		if($count_serializes > 1) {
			$current_match .= str_repeat('";', $count_serializes - 1);
		}

		// get the URI structure
		$hosts = get_hosts(get_config());
		$old_host = $hosts[0];
		$new_host = $hosts[1];

		$replaced = $this->try_unserialize_replace($old_host, $new_host, $current_match);
		Debug::log("unserialized_handler - old: ". $current_match . ", new: ". $replaced);
		return $replaced;
	}

	// try to recursively unserialize a string
	// this is used for cases where you have
	//  deeply nested serialized strings.
	protected function try_unserialize_replace($search, $replace, $string) {
		if(@unserialize($string)) {
			return serialize(try_unserialize_replace($search, $replace, unserialize($string)));
		}

		return str_replace($search, $replace, $string);
	}

	// replace query charset encoding
	protected function force_encoding($data, $encoding = 'utf8', $collate = 'utf8_general_ci') {
		$data = preg_replace('/CHARSET\=.*?(;|\s)/', 'CHARSET=' . $encoding . '$1', $data);
		$data = preg_replace('/COLLATE\=.*?(;|\s)/', 'COLLATE=' . $collate . '$1', $data);
		$data = preg_replace('/CHARACTER SET .*?(,|\s)/', 'CHARACTER SET ' . $encoding . '$1', $data);
		$data = preg_replace('/COLLATE .*?(,|\s)/', 'COLLATE ' . $collate . '$1', $data);
		return $data;
	}

	// efficiently count the new lines character in sql file (for progress)
	protected function count_sql_lines($path) {
		update_progress("Counting new db size...");
		$count = 0;
		$sql_file_handle = fopen($path, 'r');
		while (!feof($sql_file_handle)) {
			$line = fgets($sql_file_handle);
			$count++;
		}
		fclose($sql_file_handle);
		return $count;
	}

	// get the hosts(URIs) from the Snapify configuration
	protected function get_hosts($config) {
		// old URI
		if(!$config['old_proto']) {
			$config['old_proto'] = 'http://';
		}
		$old_uri = $config['old_proto'] . $config['old_host'] . $config['old_path'];

		// new URI
		$proto_now = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
		$current_url = parse_url($proto_now . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		$path = dirname($current_url['path']);
		$new_uri = $proto_now . $current_url['host'];

		if (substr($path, -1) === '\\' || substr($path, -1) === '/') {
			$new_uri .= substr($path, 0, -1);
		} else {
			$new_uri .= $path;
		}

		Debug::log("get_hosts - old: ". $old_uri . ', new:' . $new_uri);
		return array(
			$old_uri,
			$new_uri
		);
	}

	// create database $this->db_name if not already exists - this will not override the database
	protected function create_database() {
		update_progress("Creating new db...");
		$sql = "CREATE DATABASE IF NOT EXISTS `{$this->db_name}`;";
		$query = $this->db_conn->query($sql);
		if (!$query) {
			handle_db_errors($this->db_conn, $sql);
		}
		return $query;
	}

	protected function update_progress_status($sql_query) {
		$this->progress_status['current'] += substr_count($sql_query, PHP_EOL) + 1;
		update_progress("Importing db " . round($this->progress_status['current'] * 100 / $this->progress_status['overall'], self::PROGRESS_PRECENTAGE_PRECISION) . "%");
	}

	// check if there is any dependency table waiting to be called and if current import process pass the structure phase
	protected function should_run_dep_tables($sql_query) {
		return !empty($this->dep_tables_queries) && self::DEPENDENCY_TABLES_RUN_MARK_MAGIC === substr($sql_query, 0, strlen(self::DEPENDENCY_TABLES_RUN_MARK_MAGIC));
	}

	// check if error is foreign key constraint was not correctly formed
	// run all tables that required other tables to be created because of foregin key - foregin key not exists since table is missing..
	protected function run_dep_tables() {
		foreach ($this->dep_tables_queries as $sql_query) {
			if(!$this->db_conn->query($sql_query)) {
				handle_db_errors($this->db_conn, $sql_query);
				return false;
			}
			$this->update_progress_status($sql_query);
		}

		unset($this->dep_tables_queries);
		$this->dep_tables_queries = array();
		return true;
	}


	/* Error handlers */

	/**
	* check if current database error caused by forgein key error. foreign key constraint was not correctly formed
	* this error happens because table trying to create forgein key to none existing table
	* return true if this error is related with forgein key.
	* @link https://stackoverflow.com/questions/4061293/mysql-cant-create-table-errno-150
	* @link https://stackoverflow.com/questions/29248057/cannot-add-foreign-key-constraint-mysql-error-1215-hy000
	* @return boolean
	*/
	protected function foreign_key_error_handler($sql_query) {
		$this->dep_tables_queries[] = $sql_query;
		return true;
	}

	// send informative message to user
	protected function encoding_error_handler() {
		set_errors($this->db_conn->error() . '.<br />You may try to install it or simply check the Force UTF-8 checkbox');
	}

	// can't insert new line before other line will be inserted
	protected function insert_child_error_handler($sql_query) {
		if ($this->redo_queries_temp_file->is_read_only()) {
			Debug::log('Recursive query detected, tried to redo sql query: ' . $sql_query);
			set_errors('Recursive query detected!');
			return false;
		}
		return $this->redo_queries_temp_file->write($sql_query . PHP_EOL);
	}

	/**
	* function will try to increase max packet size of MySQL server and retry current sql query
	* @param ref string $sql_query - sql text to execute, since it should be quite big, passed by reference
	* @return boolean
	*/
	function big_packet_error_handler(&$sql_query) {
		$max_packet_size = $this->get_mysql_max_packet_size();
		$sql_query_size = strlen($sql_query);

		Debug::log("Error packet size too big! max_allowed_packet={$max_packet_size} - packet sent {$sql_query_size}");

		// try to increase max packet size to current query
		$sqls = array(
			'SET GLOBAL max_allowed_packet = ' . PHP_INT_MAX,
			'SET GLOBAL net_buffer_length = ' . PHP_INT_MAX
		);

		// override mysqld configuration for current session
		foreach ($sqls as $sql) {
			if (!$this->db_conn->query($sql)) {
				Debug::log("Couldn't override max_allowed_packet");
				set_errors("Packet size too big! - MySQL server limit packet size is: {$max_packet_size} bytes, MySQL got: {$sql_query_size} bytes<br />
					Snapify doesn't have enought permissions to change settings. You can try one of the following: <br />
					1. Login as root<br />
					2. Give super permissions to current user ({$this->username})<br />
					3. Change this settings manualy.");
				return false;
			}
		}

		// create a new database connection
		Debug::log('Restarting MySQL connection');
		$this->db_conn->close();
		$this->db_conn = new SnapifyDB($this->host, $this->username, $this->password, $this->db_name, $this->port);

		Debug::log("Successfully changed MySQL max_allowed_packet to: " . $this->get_mysql_max_packet_size());
		return $this->import_query($sql_query);
	}

	/**
	 * in case of row too big error raised because of innodb_log_file_size, simply override table engine to MyISAM
	 * @param string $sql_query - sql text to execute
	 * @return boolean
	 */
	protected function innodb_log_size_error_handler($sql_query) {
		if (!$this->should_alter_innodb) {
			set_errors('InnoDB row too big!<br />
				Please check the "Alter innoDB row size" option to auto fix it.<br />
				This option will change the table engine into MyISAM.<br />
				You may also try to edit yourself, more info can be found <a target="_blank" href="https://dev.mysql.com/doc/relnotes/mysql/5.6/en/news-5-6-20.html">here</a>');
			return false;
		}

		// get table name
		$matches = array();
		preg_match('/^INSERT INTO `(.*?)`/', $sql_query, $matches);
		$table_name = $matches[1];

		// alter table engine
		$sql = "ALTER TABLE `{$table_name}` ENGINE = MYISAM";
		if (!$this->db_conn->query($sql)) {
			handle_db_errors($this->db_conn, $sql);
			return false;
		}
		return true;
	}
	
	// ignore errors
	protected function ignore_error_handler() {
		return true;
	}
}

?>
