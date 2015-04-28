<?php
App::uses('AppShell', 'Console/Command');
App::uses('PluginConfig', 'DbToolkit.Utility');
App::uses('MysqlDump', 'DbToolkit.Utility');
App::uses('DataSource', 'DbToolkit.Utility');

PluginConfig::init('DbToolkit');

class MysqldumpTask extends AppShell {

	public function execute() {
		$this->complete();
	}
	
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
		
		$dataSources = DataSource::getSources();
		foreach ($sources as $dataSource) {
			$this->out($dataSource);

			if (empty($dataSources[$dataSource])) {
				$this->out("Datasource not found: $dataSource");
				continue;
			}
			$dbConfig = $dataSources[$dataSource];
			if (!empty($MysqlDump)) {
				unset($MysqlDump);
			}
			$MysqlDump = new MysqlDump(
				$dbConfig['host'], 
				$dbConfig['login'], 
				$dbConfig['password'], 
				$dbConfig['database'], 
				null, 
				$this
			);
			
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
}