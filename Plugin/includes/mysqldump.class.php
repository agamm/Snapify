<?php

class MySQLDump {
	const SEPERATOR = "\n";

	private $db = null;
	private $zip_stream = null;
	private $dump_handler = null;

	function __construct($db_host, $db_user, $db_password, $db_name, $charset='utf8', $socket_timeout = 1500) {
		$this->db = new SnapifyDB($db_host, $db_user, $db_password, $db_name);
		$this->db->set_charset($charset);
		$this->db->query("SET @@global.net_read_timeout={$socket_timeout}");
	}

	function __destruct() {
		if ($this->dump_handler) {
			fclose($this->dump_handler);
			$this->dump_handler = null;
		}
	}

	public function set_dump_file($dump_file_path, $flags = 'ab') {
		$this->dump_handler = fopen($dump_file_path, $flags);

		if (false === $dump_file_path) {
			$err = "Path {$dump_file_path} not writeable";
			Debug::log($err);
			throw new exception($err);
		}
	}

	public function set_stream(&$zip_stream) {
		$this->zip_stream = $zip_stream;
	}
	
	public function count_table_rows($table_name) {
		$query = $this->db->query("SELECT COUNT(1) FROM {$table_name}");
		$rows = $this->db->fetch_row($query);
		return intval($rows[0]);
	}
	
	public function get_database_structure() {
		$sql = 'SHOW CREATE DATABASE ';
		$query = $this->db->query($sql . DB_NAME);
		$row = $this->db->fetch_row($query);
		if (!$row) {
			throw new Exception($this->get_mysql_error($sql));
		}
		return $row[1];
	}

	public function get_tables() {
		$tables = array();
		$sql = 'SHOW TABLES';
		$query = $this->db->query($sql);
		if (!$query) {
			throw new Exception($this->get_mysql_error($sql));
		}

		while($row = $this->db->fetch_row($query)) {
			$tables[] = $row[0];
		}

		return $tables;
	}

	public function write_table_structure($table_name) {
		$sql = "SHOW CREATE TABLE {$table_name}";
		$query = $this->db->query($sql);
		$row = $this->db->fetch_row($query);
		if (!$row) {
			throw new Exception($this->get_mysql_error($sql));
		}
		return $this->write($row[1] . ';' . self::SEPERATOR);
	}

	public function get_mysql_error($sql = null) {
		$error_msg = 'Query error: (' . $this->db->errno() . ') ' . $this->db->error() . '\nSQL: ' . $sql;
		Debug::log("MySQL Error - {$error_msg}");
		return $error_msg;
	}

	// I changed this to return a string
	protected function write($data) {
		if ($this->dump_handler) {
			fwrite($this->dump_handler, $data);
		}

		if ($this->zip_stream) {
			$this->zip_stream->addFileFromStreamChunk($data);
		}
	}
	
	public function write_table_data($table_name) {
		$sql = "SELECT * FROM `{$table_name}`";
		$query = $this->db->query($sql);
		if (!$query) {
			throw new Exception($this->get_mysql_error($sql));
		}
		$num_fields = $this->db->num_fields($query);
		$row_raw_data = '';
		$rows_written = 0;

		while($row = $this->db->fetch_row($query)) {
			$row_raw_data = "INSERT INTO `{$table_name}` VALUES (";
			for($i =0; $i < $num_fields; $i++)  {
				$row_raw_data .= '"' . str_replace("\n", "\\n", $this->db->real_escape_string($row[$i])). '", ';
			}
			$this->write(substr($row_raw_data, 0, -2) . ');' . "\n");
			$rows_written++;
			if (0 === $rows_written % SNAPIFY_DB_UPDATE_RATE) {
				snapify_update_progress(SNAPIFY_DB_UPDATE_RATE);
			}
		}

		$this->write(self::SEPERATOR);
		return $rows_written;
	}

	function full_dump() {
		$tables = $this->get_tables();
		// first write all tabel structure and only then the data
		foreach ($tables as $table) {
			$this->write_table_structure($table);
		}
		foreach ($tables as $table) {
			$this->write_table_data($table);
		}
	}
}

?>
