<?php
class LogFile {
	var $dir;
	var $filename = 'log';
	var $ext = 'txt';
	
	var $linePrefix = 'Log:';
	var $dateFormat = 'Ymdhis';
	
	var $maxSize = 512000;
	var $maxCount = 10;
	var $eol = "\n";
	var $hr = '***************************************************';
	
	var $handle;
	var $file;
	var $fullPath;
	
	var $flags = array();
	
	private $_logLines = array();
	private $Console;
	
	public function setConsole($Console) {
		$this->Console = $Console;
	}
	
	function __destruct() {
		$this->closeLogFile();
	}

	public function setLogFile($dir, $filename, $options) {
		$this->dir = $dir;
		$this->filename = $filename;

		$options += compact('dir', 'filename');
		$vars = array('dir', 'filename', 'linePrefix', 'dateFormat', 'maxSize', 'maxCount', 'eol', 'hr');
		foreach ($options as $key => $val) {
			if (in_array($key, $vars) && (!empty($val) || $val === false)) {
				$this->{$key} = $val;
			}
		}

		try {
			$this->openLogFile();		
		} catch (Exception $e) {
			exit ('LogFile Error: ' . $e->getMessage() . $this->eol);
		}
	}
	
	public function write($msg, $timeFlag = null) {
		$success = false;
		$line = '';
		if (!empty($this->dateFormat)) {
			$line = date($this->dateFormat) . ' ';
		}
		if (!empty($this->linePrefix)) {
			$line .= $this->linePrefix . ' ';
		}

		if ($this->handle) {
			$success = $this->writeFile($line . $msg);
		}
		if ($this->Console) {
			$this->Console->out($msg);
		}
		
		if ($success && !empty($timeFlag)) {
			$this->write($this->getTimeFlag($timeFlag), null);
		}
		
		$this->_logLines[] = $msg;		
		return $success;		
	}
	
	private function writeFile($msg) {
		if ($this->handle) {
			return fwrite($this->handle, $msg . $this->eol);
		} else {
			return null;
		}
	}
	
	public function writeHr($msg) {
		$len = strlen($msg);
		$hrLen = strlen($this->hr);
		if ($len < $hrLen) {
			$mark = ($hrLen - $len) / 2;
			$line = substr($this->hr, 0, floor($mark)) . $msg . substr($this->hr, - ceil($mark));
		} else {
			$line = $msg;
		}
		return $this->write($line);
	}
	
	public function setLogFileVals() {
		$this->file = $this->filename . '.' . $this->ext;
		$this->fullPath = $this->dir . $this->file;
		if (is_file($this->fullPath) && !empty($this->maxSize) && filesize($this->fullPath) > $this->maxSize) {
			$this->renameLogFile($this->fullPath, 2);
		}
	}

	public function output() {
		return file_get_contents($this->fullPath);
	}
	
	private function setDir($dir = null) {
		if (empty($dir)) {
			$dir = $this->dir;
		}
		if (!is_dir($dir) && !mkdir($dir)) {
			throw new Exception('Cannot create log file directory: ' . $dir);
		}
		return true;
	}
	
	private function openLogFile() {
		$this->setDir();
		$this->setLogFileVals();

		if (!($this->handle = fopen($this->fullPath, 'a'))) {
			throw new Exception(sprintf('Could not open log file "%s" for writing', $this->fullPath));
		}
		$this->writeHr('LOG OPENED');
		return true;
	}
	
	private function closeLogFile() {
		$this->writeHr('LOG CLOSED');
		if ($this->handle) {
			fclose($this->handle);
		}
	}
	
	private function renameLogFile($file, $count) {
		if (!empty($this->maxCount) && $count > $this->maxCount) {
			return unlink($file);
		}
		$newFile = $this->dir . $this->filename . '_' . $count . '.' . $this->ext;
		if (is_file($newFile)) {
			$this->renameLogFile($newFile, $count + 1);
		}
		return rename($file, $newFile);
	}
	
	private function getTimeFlag($timeFlag) {
		$stamp = time();
		$out = 'TIME FLAG "' . $timeFlag . '" marked at ' . date('Y-m-d H:i:s', $stamp);

		if (!empty($this->flags[$timeFlag])) {
			$out .= ': ' . $this->timeFormat($stamp - $this->flags[$timeFlag]) . ' since last';
		}
		
		$this->flags[$timeFlag] = $stamp;
		return $out;	
	}
	
	private function timeFormat($secs){
		if (empty($secs)) {
			return '0 seconds';
		}
		$bit = array(
			' year'        => $secs / 31556926 % 12,
			' week'        => $secs / 604800 % 52,
			' day'        => $secs / 86400 % 7,
			' hour'        => $secs / 3600 % 24,
			' minute'    => $secs / 60 % 60,
			' second'    => $secs % 60
		);
		$out = '';
		foreach($bit as $k => $v){
			if ($v >= 1) {
				if (!empty($out)) {
					$out .= ', ';
				}
				$out .= $v.$k;
				if ($v != 1) {
					$out .= 's';
				}
			}
		}
		return $out;
	}
}