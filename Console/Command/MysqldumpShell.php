<?php
App::uses('ConnectionManager', 'Model');
App::uses('AppShell', 'Console/Command');
App::import('Utilities', 'DbToolkit.PluginConfig');
App::import('Vendor', 'DbToolkit.Mysqldump');

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

		//Makes sure the sources are unique
		$sources = array_keys(array_flip($config['sources']));
		
		$dataSources = ConnectionManager::enumConnectionObjects();
		foreach ($sources as $dataSource) {
			$this->out($dataSource);

			if (empty($dataSources[$dataSource])) {
				$this->out("Datasource not found: $dataSource");
				continue;
			}
			$dbConfig = $dataSources[$dataSource];
			if (!empty($Mysqldump)) {
				unset($Mysqldump);
			}
			$Mysqldump = new Mysqldump(
				$dbConfig['host'], 
				$dbConfig['login'], 
				$dbConfig['password'], 
				$dbConfig['database'], 
				null, 
				$this
			);
			
			if ($schema) {
				$Mysqldump->getSchema = true;
			}

			$this->hr();
			
			$this->out("Connected to database {$dbConfig['database']}");
			$this->hr();
			$Mysqldump->run();
			$this->hr();
			$this->out("Completed mysqldump. Uploading to:{$ftp['server']}");
			$this->hr();
			
			$Mysqldump->ftpUpload($ftp['directory'], $ftp['server'], $ftp['userName'], $ftp['password'], $ftp);
			$this->out("Finished uploading");
		}
		$this->out("Finished MySQL Dump Backup");
	}
}