<?php
require_once 'dir_info.php';

class Ftp extends DirInfo {
	
	//Connection ID
	var $connId;
	
	//Login Options
	var $loginOptions = array(
		'server' => null,
		'userName' => null,
		'password' => null,
		'port' => 21,
		'timeout' => 90,
		'ascii' => false,
	);
	
	function __construct($loginOptions = array(), $Console = null) {
		if (!empty($loginOptions)) {
			$this->loginOptions = array_merge($this->loginOptions, $loginOptions);
			$this->login($this->loginOptions);
		}
		parent::__construct($Console);
	}
	
	function __destruct() {
		$this->close();
	}
	
	function connect($loginOptions = array()) {
		$loginOptions = array_merge($this->loginOptions, $loginOptions);
		
		extract($loginOptions);
		
		if (($this->connId = ftp_connect($server, $port, $timeout))) {
			$this->log('Successfully connected to server: ' . $server);
			$this->loginOptions = $loginOptions;
		} else {
			$msg = 'Could not connect to server: ' . $server;
			foreach ($this->loginOptions as $key => $val) {
				$msg .= sprintf('`%s`: `%s`; ', $key, $val);
			}
			$this->log($msg);
			throw new Exception($msg);
		}
		return $this->connId;
	}

	function login($loginOptions = array()) {
		$loginOptions = array_merge($this->loginOptions, $loginOptions);
		if (!$this->connId) {
			$this->connect($loginOptions);
		}
		
		extract($loginOptions);
		if (($loginResult = @ftp_login($this->connId, $userName, $password))) {
			$this->log('Successfully logged on to ' . $server . ' with user ' . $userName);
			$this->loginOptions = $loginOptions;
		} else {
			$this->log('Could not log on to ' . $server . ' with user ' . $userName);
		}
		return $loginResult;
	}
	
	function close() {
		if ($this->connId) {
			$this->log('Closing FTP Connection');
			return ftp_close($this->connId);
		} else {
			$this->log('Could not close FTP Connection. FTP Connection not found');
			return null;
		}
	}
	
	function reconnect($loginOptions = array()) {
		$this->log('Reconnecting FTP connection');
		$this->close();
		return $this->login($loginOptions);
	}
	
	function upload($source, $target, $options = array()) {
		$timeTag = 'FTP_UPLOAD';
		
		$options = array_merge(array(
			'mode' => null,
			'recursive' => false,
		), $options);
		extract($options);

		if (is_dir($source)) {
			return $this->uploadDir($source, $target, $options);
		}
		
		if ($mode === null) {
			$mode = $this->loginOptions['ascii'] ? FTP_ASCII : FTP_BINARY;
		}

		$size = filesize($source);
		
		$this->log('Uploading', $timeTag);
		$this->log(sprintf('Source: %s', $source));
		$this->log(sprintf('Size: %s', $this->filesizeOutput($size)));
		$this->log(sprintf('Target: %s', $target));
		
		//Makes sure target directory exists
		$targetDir = dirname($target);
		if (!$this->_isDir($targetDir)) {
			$this->log("Target directory, $target, does not exist");
			if (!$this->makeDir($targetDir, $recursive)) {
				$this->log("Could not create directory $target");
			}					
		}

		$cutoff = 0;
		if ($size > $cutoff) {	//Track percentage
			$success = $this->uploadPct($source, $target, $mode);
		} else {
			$success = ftp_put($this->connId, $target, $source, $mode);
		}
		
		if ($success) {
			$this->log("Successfully uploaded", $timeTag);
			return true;
		} else {
			$this->log("Error uploading");
			return false;
		}
	}
	
	private function _timeFormat($secs) {
		$hours = floor($secs / 3600);
		$secs -= $hours * 3600;
		$minutes = floor($secs / 60);
		$secs -= $minutes * 60;
		return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
	}

	// Uploads a file, tracking percentage
	function uploadPct($source, $target, $mode, $options = array()) {
		$total = filesize($source);
		$lastPct = 0;
		$multiple = 5;
		
		$bytes = 0;
		
		$Ftp2 = new Ftp($this->loginOptions);
		$ret = ftp_nb_put($this->connId, $target, $source, $mode);

		$startTime = microtime(true);
		sleep(1000)
		
		while($ret == FTP_MOREDATA) {
			clearstatcache();
			$bytes = $Ftp2->getFileSize($target);

			if (!empty($oBytes) && $oBytes != $bytes) {
				$time = microtime(true) - $startTime;
				$timeRemains = $time * $total / $bytes - $time;

				$this->log(sprintf(
					'%s %3d%%: %s of %s bytes. %s remains', 
					$this->_timeFormat($time),
					round($bytes / $total * 100),
					number_format($bytes), 
					number_format($total),
					$this->_timeFormat($timeRemains)
				));
			}
			
			if ($bytes > 0) {
				$pct = round($bytes / $total * 100);
				if (($pct != $lastPct) && !($pct % $multiple)) {
					$this->log("$pct% Complete");
					$lastPct = $pct;
				}
			}			
			$ret = ftp_nb_continue($this->connId);
			
			$oBytes = $bytes;
		}
		return ($ret == FTP_FINISHED);
	}
	
	function uploadDir($sourceDir, $targetDir, $options = array()) {
		$options = array_merge(array(
			'mode' => null,
			'recursive' => false,
		), $options);
		extract($options);
		if ($mode === null) {
			$mode = $this->loginOptions['ascii'] ? FTP_ASCII : FTP_BINARY;
		}
		
		if (!is_dir($sourceDir)) {
			$this->log("Could not upload directory. $sourceDir is not a valid directory");
			return false;
		}
		
		$files = glob($sourceDir . '/*');
		foreach ($files as $filename) {
			$base = basename($filename);
			$subTarget = $targetDir . '/' . $base;
			if (is_dir($filename)) {
				$this->makeDir($subTarget, true);
				if ($recursive) {
					$this->uploadDir($filename, $subTarget, $options);
				}			
			} else {
				$this->upload($filename, $subTarget, $options);
			}
		}
	}
	
	function delete($path) {
		if ($success = ftp_delete($this->connId, $path)) {
			$this->log("Deleted path: $path");
		} else {
			$this->log("Could not delete path: $path");
		}
		return $success;
	}
	
	function getFilesize($target) {
		return ftp_size($this->connId, $target);
	}
	
	function makeDir($dir = null, $recursive = false) {
		if ($this->_isDir($this->connId, $dir)) {
			return true;
		} else if(@ftp_mkdir($this->connId, $dir)) {
			$this->log("$dir successfully created");
			return true;
		} else if ($recursive) {
			$parentDir = dirname($dir);
			$this->log("Attempting to create parent directory, $parentDir");
			if (!$this->makeDir($parentDir, $recursive)) {
				$this->log("Could not create $dir");
				return false;
			}
		}
		return ftp_mkdir($this->connId, $dir);
	}
	
	function getDirList($dir = null) {
		$dir = $this->getDir($dir);
		$rawList = ftp_rawlist($this->connId, $dir);
		$dirs = $this->_parseRawList($rawList);
		return $dirs;
	}
	
	function getDir($dir = null) {
		if (!isset($dir)) {
			$dir = ftp_pwd($this->connId);
		}
		return parent::getDir($dir);
	}
	
	function setDir($dir = null, $createOnFalse = false) {
		if ($this->_setDir($dir)) {
			$this->log('Directory changed to: ' . $dir);
			return $dir;
		} else {
			if ($createOnFalse) {
				if ($this->makeDir($dir, true)) {
					return $this->setDir($dir, false);
				}
			}				
			$this->log('Could not change directory to: ' . $dir);
			return false;
		}
	}
	
	function _setDir($dir = null) {
		return @ftp_chdir($this->connId, $dir);
	}
	
	function setParentDir() {
		$oDir = $this->getDir();
		if ($this->_setParentDir()) {
			$this->log('Moved up one level from ' . $oDir . ' to ' . $this->getDir());
			return true;
		} else {
			$this->log('Could not move up to parent level from ' . $oDir);
			return false;
		}
	}
	
	function _setParentDir() {
		return @ftp_cdup($this->connId);
	}
		
	function _parseRawList($dirs) {
		$return = array();
		$unixMatch = '/([^\s]+)[\s]+([^\s]+)[\s]+([^\s]+)[\s]+([^\s]+)[\s]+([^\s]+)[\s]+([A-Za-z]+[\s]+[\d]+[\s]+[^\s]+)[\s]+([^$]+)/';
		$unixColumns = array('permission', 'number', 'owner', 'otherOwner', 'size', 'modified', 'name');
		foreach ($dirs as $dir) {
			$data = array(
				'name' => null,
				'modified' => null,
				'isDir' => 0,
			);
			if (preg_match($unixMatch, $dir, $matches)) {
				foreach ($unixColumns as $key => $col) {
					$data[$col] = $matches[$key + 1];
				}
				$data['string'] = $matches[0];
			}
			if (!empty($data['name'])) {
				$data['isDir'] = $this->_isDir($data['name']);
				$return[] = $data;
			}
		}
		return $return;
	}
		
	function _isDir($dir) {
		$oDir = $this->getDir();
		if ($this->_setDir($dir)) {
			$this->_setDir($oDir);
			return true;
		} else {
			return false;
		}
	}
	
	function setLogFile($dir = null, $file = null, $options = array()) {
		$options = array_merge(array(
			'linePrefix' => 'FTP',
		), $options);
		return parent::setLogFile($dir, $file, $options);
	}
}