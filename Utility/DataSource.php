<?php
App::uses('ConnectionManager', 'Model');
class DataSource {
	public static function getSources() {
		return ConnectionManager::enumConnectionObjects();
	}

	public static function exists($sourceName) {
		$sources = self::getSources();
		return !empty($sources[$sourceName]);
	}

	public static function get($sourceName) {
		$sources = self::getSources();
		return !empty($sources[$sourceName]) ? $sources[$sourceName] : null;
	}
}