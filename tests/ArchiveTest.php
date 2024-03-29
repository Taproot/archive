<?php

namespace Taproot;

use PHPUnit\Framework\TestCase;

class ArchiveTest extends TestCase {
	public static function setUpBeforeClass(): void {
		clearTestData();
	}
	
	public function testUrlToFilesystemPath() {
		$this->assertEquals(urlToFilesystemPath('http://example.com'), 'http/example.com');
		$this->assertEquals(urlToFilesystemPath('https://example.com/things/more?stuff=blah#fragment'), 'https/example.com/things/more');
		$this->assertEquals(urlToFilesystemPath('https://localhost:8000/some-local-file/'), 'https/localhost:8000/some-local-file');
	}
	
	public function testArchiveUrl() {
		$archive = new Archive(archivePath());
		$url = 'http://waterpigs.co.uk';
		list($resp, $err) = $archive->archive($url);
		$this->assertCount(1, $archive->archives($url));
	}
}
