<?php
//require_once dirname(__FILE__) . DS . "ftp.php";
//require_once dirname(__FILE__) . DS . "log_file.php";
App::uses('Ftp', 'DbToolkit.Utility');
App::uses('LogFile', 'DbToolkit.Utility');

class MysqlDump {
	//Settings
	public $gzip = true;
	public $schema = false;
	
	var $dumpDir;
	
	var $dumpFile;
	var $dumpFilename;
	
	var $useLogFile = true;
	var $logDir = null;		//Will default to dumpDir
	var $logFile = 'mysql_dump_log';
	
	var $hasFtp = false;

	const FTP_MAX_BACKUPS = 7;
	
	private $Ftp;
	private $LogFile;
	private $Console;
	
	private $_mysqlDumpCmd;
	
	private $_mysql;
	private $_ftp = array(
		'server' => null,
		'userName' => null,
		'password' => null,
		'port' => 21,
		'dir' => '/',
	);
	
	private $_dumpFile = array(
		'full' => null,
		'path' => null,
		'name' => null,
	);
	
	private $isWindows = false;
	private $hasLogin = false;
	public $getSchema = false;

	public function __construct(
		$mysqlHost = null, 
		$mysqlUser = null, 
		$mysqlPass = null, 
		$mysqlDb = null, 
		$dumpDir = null, 
		$Console = null
	) {
		$this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

		set_time_limit(7200);
		$this->setDumpDir($dumpDir);
		$this->setLog();
		$this->setMysqlLogin($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
		
		$this->Console = $Console;
	}

	public function ftpUpload($ftpDir, $ftpServer, $ftpUser, $ftpPass, $ftpOptions = array()) {
		$flag = 'FTP';
		if (!$this->connectFtp($ftpServer, $ftpUser, $ftpPass, $ftpDir, $ftpOptions)) {
			$this->log('No FTP info. Skipping');
			return null;
		}
		
		$this->log('Beginning FTP backup', $flag);
		
		$dumpFile = $this->getDumpFile();
		
		//Checks to make sure MySQL Dump command has been run
		if (!is_file($dumpFile['full']) && !$this->run()) {
			return $this->error('Could not upload Dump file since no dump file exists');
		}
		
		if ($this->Ftp->upload($dumpFile['full'], $this->_ftp['dir'] . $dumpFile['name'])) {
			$this->log('FTP backup completed successfully', $flag);
			
			unlink($dumpFile['full']);
			
			$this->removeOldFtpBackups();
			return true;
		} else {
			return $this->error('There was an error uploading file to FTP', $flag);
		}
	}
	
	//Runs the MySQL dump command
	public function run($mysqlHost = null, $mysqlUser = null, $mysqlPass = null, $mysqlDb = null) {
		$this->log('Beginning MySQL Dump', 'COMPLETE');
		// Makes sure we're connected to MySQL
		$this->setMysqlLogin($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
		
		$cmd = $this->getMysqlDumpCmd();
		$this->log($cmd);
		
		// Executes dump command
		$this->log('Executing MySQL Dump');
		$cmds = explode(';', $cmd);
		foreach ($cmds as $cmd) {
			if (empty($cmd)) {
				continue;
			}
			$this->log('--------------------------------------------');
			$this->log($cmd);
			$success = exec($cmd);
		}

		$this->log('MysqlDump Completed', 'COMPLETE');
		return $success;
	}
	
	public function restore($dumpFile, $mysqlHost = null, $mysqlUser = null, $mysqlPass = null, $mysqlDb = null) {
		$isZipped = substr($dumpFile, -2) == 'gz';
		$this->setMysqlLogin($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);

		$this->log('Beginning MySQL Dump Restore', 'COMPLETE');

		$mysqlCmd = sprintf('%s -u %s %s %s',
			$this->getProgram('mysql'),
			$this->_mysql['user'],
			!empty($this->_mysql['pass']) ? '-p' . $this->_mysql['pass'] : '',
			$this->_mysql['db']
		);

		if ($isZipped) {
			$cmd = sprintf('%s -dc "%s" |%s', 
				$this->getProgram('gzip'), 
				$dumpFile,
				$mysqlCmd
			);
		} else {
			$cmd = "$mysqlCmd < \"$dumpFile\"";
		}
		$success = exec($cmd);
		$this->log('MySQL Restore Completed', 'COMPLETE');
		return $success;
	}

	public function getLastFile($glob) {
		//$glob = $this->dumpDir . '*' . $sourceName . '*';
		$files = glob($glob);
		$files = array_combine($files, array_map("filemtime", $files));
		arsort($files);
		return key($files);
	}

	function getMysqlDumpCmd() {
		if (!empty($this->_mysqlDumpCmd)) {
			$cmd = $this->_mysqlDumpCmd;
		} else {
			$cmd = $this->setMysqlDumpCmd();
		}
		return $cmd;
	}
	
	function getProgram($program) {
		$mysqlWindowsDir = 'J:\wamp\bin\mysql\mysql5.6.17\bin\\';
		$gzipWindowsDir = 'J:\Program Files (x86)\GnuWin32\bin\\';
		$windowsSuffix = '.exe';

		switch ($program) {
			case 'mysqldump':
			case 'mysql';
				$windowsPrefix = $mysqlWindowsDir;
				break;
			case 'tar':
			case 'gzip':
				$windowsPrefix = $gzipWindowsDir;
				break;
		}
		if ($this->isWindows) {
			$program = $windowsPrefix . $program . $windowsSuffix;
			if (strpos($program, ' ') !== false) {
				$program = '"' . $program . '"';
			}
		}
		return $program;
	}

	function setMysqlDumpCmd() {
		$dumpFile = $this->getDumpFile();
		$split = false;
		
		$format = $dumpFile['format'];
		
		//Generates the dump command
		$cmd = $this->getProgram('mysqldump');
		
		$vars = array(
			'user' => '-u ',
			'pass' => '-p',
			'host' => '-h ',
		);
		if ($split) {
			$newDir = $dumpFile['path'] . $dumpFile['format'];
			mkdir($newDir);
			
			$this->_mysql['dir'] = $newDir;
			$vars['dir'] = '--tab=';
		}		
		foreach ($vars as $key => $param) {
			if (!empty($this->_mysql[$key])) {
				$cmd .= " $param{$this->_mysql[$key]}";
			}
		}
		
		if ($this->getSchema) {
			$cmd .= ' -d';
		}
		
		$cmd .= ' ' . $this->_mysql['db'];
		
		if ($split) {
			if ($this->gzip) {
				//$cmd .= ";$gzip {$dumpFile['path']} {$dumpFile['name']};";
				//$cmd .= " | $gzip ";
				$cmd .= ';' . $this->getProgram('tar');
				$cmd .= " -zcvf {$dumpFile['full']}.tar.gz $newDir";
			}
		} else {		
			if ($this->gzip) {
				$cmd .= " | " . $this->getProgram('gzip');
			}
			$cmd .= ' > ' . $dumpFile['full'];
		}
		
		$this->_mysqlDumpCmd = $cmd;
		return $cmd;
	}
	
	function setDumpDir($dir = null) {
		if (!empty($dir)) {
			$this->dumpDir = $dir;
		}
		//Default Dump Dir
		if (empty($this->dumpDir)) {
			$this->dumpDir = TMP . 'mysql_backup' . DS;
		}
		if (!is_dir($this->dumpDir) && !mkdir($this->dumpDir)) {
			return $this->error("{$this->dumpDir} is not a directory. Cannot continue");
		}
		$this->setLog();	//refreshes Log Directory
		return true;
	}
	
	public function getDumpFile() {
		if (empty($this->_dumpFile['full'])) {
			$this->setDumpFile();
		}
		return $this->_dumpFile;
	}
	
	private function setDumpFile() {
		$format = $this->_mysql['db'] . date('YmdHis');
		$name = $format . '.sql';
		if ($this->gzip) {
			$name .= '.gz';
		}

		$path = $this->dumpDir;
		$full = $path . $name;
		$this->_dumpFile = compact('full', 'path', 'name', 'format');

		$this->log('Creating file: ' . $full);
	}

	//FTP
	protected function connectFtp($server = null, $userName = null, $password = null, $dir = null, $options = array()) {
		$ftp = array_merge($options, compact('server', 'userName', 'password', 'dir'));
		$this->setFtp($ftp);	
		if (!empty($this->_ftp)) {
			if (empty($this->Ftp)) {
				$this->Ftp = new Ftp($this->_ftp, $this->Console);
				if (!($this->Ftp->setDir($this->_ftp['dir'], true))) {
					return $this->error('Could not set FTP directory to "' . $this->_ftp['dir'] . '"');
				}
				$this->Ftp->setLogFile($this->logDir, $this->logFile);
				$this->hasFtp = true;
				return true;
			} else {
				return $this->Ftp->reconnect($this->_ftp);
			}
		}
		return null;
	}

	private function setFtp($ftp = null) {
		if (!empty($ftp)) {
			$this->_ftp = array_merge($this->_ftp, $ftp);
		}
		if (substr($this->_ftp['dir'],-1) != '/') {
			$this->_ftp['dir'] .= '/';
		}
	}
	
	protected function removeOldFtpBackups($dir = null, $maxBackups = null) {
		if (empty($dir)) {
			$dir = $this->_ftp['dir'];
		}
		$this->log('');
		$this->log('Cleaning up FTP directory: ' . $dir, 'FTP_CLEANUP');
		if (empty($maxBackups) && self::FTP_MAX_BACKUPS) {
			$maxBackups = self::FTP_MAX_BACKUPS;
		}
		if (empty($maxBackups)) {
			return null;
		}
		
		if (self::FTP_MAX_BACKUPS) {
			list($backups, $keep, $delete) = array(array(), array(), array());
			
			$files = $this->Ftp->getDirList($dir);
			if (!empty($files)) {
				foreach ($files as $file) {
					if (preg_match('/([A-Za-z_\-]+)([\d]{14}).sql/', $file['name'], $match)) {
						$backups[$match[1]][$match[2]] = $dir . $file['name'];
					}
				}
				if (!empty($backups)) {
					foreach ($backups as $db => $files) {
						krsort($files);
						$files = array_values($files);
						foreach ($files as $k => $file) {
							if ($k >= $maxBackups) {
								$delete[$db][] = $file;
							} else {
								$keep[$db][] = $file;
							}
						}
					}
					if (!empty($delete)) {
						foreach ($delete as $db => $paths) {
							$this->log('Removing ' . count($paths) . ' backups for DB: ' . $db . ' (Keeping ' . count($keep[$db]) . ')');
							foreach ($paths as $path) {
								$this->Ftp->delete($path);
							}
						}
					}
				}
			}
		}
		$this->log('Finished cleaning up FTP directory: ' . $dir, 'FTP_CLEANUP');
		$this->log('');
	}

	private function setMysqlLogin($host = null, $user = null, $pass = null, $db = null) {
		if ($host == 'localhost') {
			$host = '127.0.0.1';
		}
		$vars = array('host', 'user', 'pass', 'db');
		if (empty($host)) {
			$hasLogin = $this->hasLogin;
		} else {
			$hasLogin = true;
			foreach ($vars as $var) {
				$this->_mysql[$var] = $$var;
			}
		}
		$this->hasLogin = $hasLogin;
		if ($hasLogin) {
			$this->log('MySQL Dump initialized for database: ' . $this->_mysql['db']);
		}
		return $hasLogin;
	}
	
	private function error($msg, $timeFlag = null) {
		$this->log('Error: ' . $msg, $timeFlag);
		throw new Exception('MysqlDump Error: ' . $msg);
		return false;
	}
	
	private function log($msg, $timeFlag = null) {
		$this->log[] = date('YmdHis') . ': ' . $msg;
		
		if (!empty($this->Console)) {
			$this->Console->out($msg);
		}
		
		if (empty($this->LogFile)) {
			$this->setLog();
		}
		$this->LogFile->write($msg, $timeFlag);
	}
	
	private function setLog() {
		if ($this->useLogFile) {
			if (empty($this->logDir)) {
				$this->logDir = $this->dumpDir;
			}
		}
		$this->LogFile = new LogFile($this->logDir, $this->logFile);
	}
}