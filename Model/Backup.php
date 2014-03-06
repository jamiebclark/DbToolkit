<?php
App::import('Ftp', 'DBToolkit.Vendor');

class Backup extends AppModel {
	public $name = 'Backup';
	public $useTable = false;
	
	public function find($type = 'first', $query = array()) {
	
	}
}