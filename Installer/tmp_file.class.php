<?php

/**
 * class used for temporary files
 * this files will be auto removed it self on the end of the execution.
 * should be used only for files that relevant for current PHP request.
*/
class TempFile {
	protected $path = null;
	protected $handle = null;
	protected $read_only = false;
	
	public function __construct($path) {
		$this->path = $path;
		
		if (file_exists($path)) {
			$this->handle = fopen($this->path, 'rb+');
		} else {
			$this->handle = fopen($this->path, 'wb+');
		}
	}
	
	public function __destruct() {
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
		
		if (file_exists($this->path)) {
			@unlink($this->path);
		}
	}
	
	public function set_read_only($flag) {
		$this->read_only = $flag;
	}
	
	public function is_read_only() {
		$this->read_only;
	}
	
	public function get_path() {
		return $this->path;
	}
	
	public function seek($position) {
		fseek($this->handle, $position, SEEK_SET);
	}
	
	public function write($data) {
		if ($this->read_only) {
			return false;
		}
		$bytes_written = fwrite($this->handle, $data);
		return $bytes_written === strlen($data);
	}
	
	public function read($length) {
		return fread($this->handle, $length);
	}
	
	public function override($data) {
		$this->truncate();
		$this->seek(0);
		$this->write($data);
	}
	
	public function get_size() {
		return filesize($this->path);
	}
	
	public function is_empty() {
		return 0 === $this->get_size();
	}
	
	public function close() {
		$this->__destruct();
	}
	
	protected function truncate() {
		return ftruncate($this->handle, 0);
	}
}

?>