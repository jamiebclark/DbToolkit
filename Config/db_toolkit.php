<?php
$config['DbToolkit'] = array(
	'global' => array(
		'sources' => array('default'),
		'ftp' => array(
			'directory' => '/MySQL/',
			'server' => '',
			'userName' => '',
			'password' => '',
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