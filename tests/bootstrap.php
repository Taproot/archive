<?php

namespace Taproot;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');

function archivePath() {
	return realpath(__DIR__ . '/data-live');
}

function clearTestData() {
	$source = __DIR__ . '/data-fixture/';
	$dest = __DIR__ . '/data-live/';
	`rm -r $dest`;
	`cp -r $source $dest`;
}
