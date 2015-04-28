<?php

App::uses('AppShell', 'Console/Command');
App::uses('PluginConfig', 'DbToolkit.Utility');
App::uses('MysqlDump', 'DbToolkit.Utility');
App::uses('DataSource', 'DbToolkit.Utility');

PluginConfig::init('DbToolkit');

class MysqldumpShell extends AppShell {

	private function getConfig($key = 'backup') {
		$ftp = Configure::read('DbToolkit.ftp');
		$config = Configure::read("DbToolkit.$key");
		if (empty($config)) {
			$config = array('ftp' => array());
		}
		if ($global = Configure::read('DbToolkit.global')) {
			foreach ($global as $key => $val) {
				if (!empty($config[$key])) {
					$config[$key] = array_merge($val, $config[$key]);
				} else {
					$config[$key] = $val;
				}
			}
		}
		return $config;
	}

	// Restores a dump
	public function restore() {
		$config = $this->getConfig('backup');
		$dbSourceName = $this->args[0];
		$MysqlDump = $this->getMysqlDumpFromSource($dbSourceName);

		if (!empty($this->args[1])) {
			$file = $this->args[1];
		} else {
			$file = $MysqlDump->getLastFile($config['ftp']['directory'] . 'nightly' . DS . '*' . $dbSourceName . '*');
		}
		$this->out(sprintf('Restoring Database Config: %s from file: %s', $dbSourceName, $file));
		$MysqlDump->restore($file);
		
		$this->out('Completed Restoring');
	}
	
	//Backs up both schema and data
	public function complete() {
		$this->schema();
		$this->backup();
	}

	//Backs up only the schema
	public function schema() {
		$config = $this->getConfig('schema');
		$this->out('DbToolkit Automated MySQL Schema Dump');
		$this->hr();
		$this->dump($config, array(
			'schema' => true,
		));
	}
	
	//Backs up the data
	public function backup() {
		$config = $this->getConfig('backup');
		$this->out('DbToolkit Automated MySQL Dump');
		$this->hr();
		
		$this->dump($config);		
	}
	
	private function dump($config, $options = array()) {
		$options = array_merge(array(
			'schema' => false,
		), $options);
		extract($options);
		
		$ftp = $config['ftp'];
		$ftp['directory'] .= !empty($this->args[0]) ? trim($this->args[0]) : 'nightly';

		// Makes sure the sources are unique
		$sources = array_keys(array_flip($config['sources']));
		
		foreach ($sources as $dataSource) {
			$this->out("Dumping from Datasource: $dataSource");
			if (!DataSource::exists($dataSource)) {
				$this->out("Datasource not found: $dataSource");
				continue;
			}
			$dbConfig = DataSource::get($dataSource);
			$MysqlDump = null;
			$MysqlDump = $this->getMysqlDumpFromSource($dataSource);
			
			if ($schema) {
				$MysqlDump->getSchema = true;
			}

			$this->hr();
			
			$this->out("Connected to database {$dbConfig['database']}");
			$this->hr();
			$MysqlDump->run();
			$this->hr();
			$this->out("Completed mysqldump. Uploading to:{$ftp['server']}");
			$this->hr();
			
			$MysqlDump->ftpUpload($ftp['directory'], $ftp['server'], $ftp['userName'], $ftp['password'], $ftp);
			$this->out("Finished uploading");
		}
		$this->out("Finished MySQL Dump Backup");
	}

	protected function getMysqlDumpFromSource($dbSourceName) {
		$dbSource = DataSource::get($dbSourceName);
		if (empty($dbSource)) {
			throw new Exception('Could not find datasource: ' . $dbSourceName);
		}
		return new MysqlDump(
			$dbSource['host'], 
			$dbSource['login'], 
			$dbSource['password'], 
			$dbSource['database'], 
			null, 
			$this
		);
	}
}