<?php
$config['DbToolkit'] = array(
	'global' => array(
		'sources' => array('default', 'shop'),
		'ftp' => array(
			'directory' => '/MySQL/',
			'server' => 'server.souperbowl.org',
			'userName' => 'mysql_backup',
			'password' => 'soupBow1',
			'ascii' => false,
			'port' => 21,
		),
	),
	'backup' => array(
		'ftp' => array(
			'directory' => '/MySQL/backup/',
		),
	),
	'schema' => array(
		'ftp' => array(
			'directory' => '/MySQL/schema/',
		)
	),
);