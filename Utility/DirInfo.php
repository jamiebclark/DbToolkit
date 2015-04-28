<?php
//require_once "log_file.php";
App::uses('LogFile', 'DbToolkit.Utility');
class DirInfo {
	var $ds = '/';
	
	var $log = array();
	var $logFile;
	var $logDir;
	var $LogFile;
	
	private $Console;

	function __construct($Console = null) {
		$this->Console = $Console;
	}
	
	function __destruct() {
	}
		
	function getDirInfo($dir = null, $options = array()) {
		$return = array();
		if (!empty($options['root'])) {
			if (substr($options['root'], -1) == $this->ds) {
				$options['root'] = substr($options['root'], 0, -1);
			}
			$return['root'] = $options['root'];
		}
		
		$this->setLogFile();
		
		$dir = $this->getDir($dir);
		$return += array(
			'name' => $this->getDirName($dir),
			'dir' => $dir,
			'parent' => $this->getParentDir($dir),
			'crumbs' => $this->getDirCrumbs($dir, $options),
			'list' => $this->getDirList($dir),
		);
		
		return $return;			
	}
		
	function getDir($dir = null) {
		return $dir;
	}
	
	function getParentDir($dir = null) {
		$crumbs = $this->getDirCrumbs($dir);
		array_pop($crumbs);
		return $this->ds . implode($this->ds, $crumbs);
	}
	
	function getDirName($dir = null) {
		$crumbs = $this->getDirCrumbs($dir);
		return array_pop($crumbs);
	}
	
	function getDirCrumbs($dir = null, $options = array()) {
		$dir = $this->getDir($dir);
		if (!empty($options['root'])) {
			if (strpos($dir, $options['root']) === 0) {
				$dir = substr($dir, strlen($options['root']));
			}
		}
		$crumbs = explode($this->ds, substr($dir,1));
		return $crumbs;
	}
	
	function getDirList($dir = null) {
		$dirs = array();
		$files = array();
		if (1 || !$this->_isDir($dir)) {
			$folderList = scandir($dir);
			foreach ($folderList as $file) {
				if ($file == '.' || $file == '..') {
					continue;
				}
				$filename = $dir . $this->ds . $file;
				$isDir = is_dir($filename);
				
				$entry = array(
					'name' => $file, 
					'dir' => $dir,
					'full' => $dir . $this->ds . $file,
					'isDir' => $isDir,
					'modified' => filemtime($filename),
				);
				if ($isDir) {
					$dirs[] = $entry;
				} else {
					$files[] = $entry;
				}
			}
		}
		return array_merge_recursive($dirs, $files);
	}
	

	function isDir($dir) {
		if ($this->_isDir($dir)) {
			$this->log($dir . ' is a directory');
			return true;
		} else {
			$this->log($dir . ' is not a directory');
			return false;
		}
	}	
	
	function filesizeOutput($bytes) {
		$pows = array('b', 'K', 'M', 'G', 'T');
		$len = count($pows);
		for ($k = $len; $k > 0; $k--) {
			$pow = pow(1024, $k);
			if ($bytes > $pow) {
				break;
			}
		}
		return round($bytes / $pow) . $pows[$k];
	}
	
	function cleanDir($dir = null, $conditions = array()) {
		if (!empty($conditions['modified']) && !is_numeric($conditions['modified'])) {
			$conditions['modified'] = strtotime($conditions['modified']);
		}
		
		$deletedFiles = 0;
		$dirList = $this->getDirList($dir);
		foreach ($dirList as $file) {
			$delete = true;

			if (!empty($conditions['modified'])) {
				$delete = $file['modified'] <= $conditions['modified'];
			}
			
			if ($delete) {
				unlink($dir . $file['name']);
				$deletedFiles++;
			}
		}
		
		return $deletedFiles;
	}

	function _isDir($dir) {
		return is_dir($dir);
	}
	
	function log($msg) {
		$stamp = date('Y-m-d H:i:s');
		$key = $stamp;
		$count = 2;
		while (!empty($this->log[$key])) {
			$key = $stamp . '_' . ($count++);
		}
		$this->log[$key] = $stamp . ' ' . $msg;
		
		if (!empty($this->Console)) {
			$this->Console->out($msg);
		}
		
		if (!empty($this->LogFile)) {
			$this->LogFile->write($msg);
		}
	}
	
	function setLogFile($logDir = null, $logFile = null, $options = array()) {
		$options = array_merge(array(
			'linePrefix' => 'DirInfo',
		), $options);
		if (!empty($logFile)) {
			$this->logFile = $logFile;
		}
		if (!empty($logDir)) {
			$this->logDir = $logDir;
		}
		$this->LogFile = new LogFile($logDir, $logFile, $options);
	}
}
