<?php

namespace Taproot;

use DateTime;
use GuzzleHttp;
use Icap\HtmlDiff\HtmlDiff;
use InvalidArgumentException;
use Mf2;
use Psr;

function urlToFilesystemPath($url) {
	$u = parse_url($url);
	
	if (isset($u['path']) and strstr('/' . $u['path'] . '/', '/../') !== false)
		return null;
	
	return rtrim($u['scheme']
		. '/'
		. $u['host']
		. (isset($u['port']) ? ':' . $u['port'] : '')
		. '/'
		. (isset($u['path']) ? trim($u['path'], '/') : ''), '/');
}

function areCachablyDifferent(Psr\Http\Message\ResponseInterface $oldResp, Psr\Http\Message\ResponseInterface $newResp) {
	// look for last-updated header
	if ($newResp->getHeaderLine('Last-modified') != null) {
		$lastModified = new DateTime($newResp->getHeaderLine('Last-modified'));
		$lastArchived = new DateTime($oldResp->getHeaderLine('Date'));
		
		return $lastModified > $lastArchived;
	}
	
	$differ = new HtmlDiff((string) $oldResp->getBody(), (string) $newResp->getBody(), true);
	$mods = $differ->outputDiff()->getModifications();
	// to do : figure out a sensible threshold here
	return max($mods) > 2;
}

class Archive {
	public $path;
	
	public function __construct($path) {
		$this->path = rtrim($path, DIRECTORY_SEPARATOR);
	}
	
	// returns [$response, exception]
	public function get($url) {
		$archives = $this->archives($url);
		if (count($archives) > 0) {
			// currently just fetching the latest. to do: add ability to get older version
			return [$this->getResponse($url), null];
		} else {
			return $this->archive($url);
		}
	}
	
	// returns [$response, exception]
	public function archive($url, $forceUpdate = false) {
		$client = new GuzzleHttp\Client();
		$archivePath = $this->basepathForUrl($url);
		
		$respEffectiveUri = null;

		try {
			$response = $client->get($url, [
				'on_stats' => function (GuzzleHttp\TransferStats $stats) use (&$respEffectiveUri) {
					$respEffectiveUri = (string) $stats->getEffectiveUri();
				}
			]);
			if (!$response->getHeaderLine('Content-type') == 'text/html' and !$response->getHeaderLine('Content-type') == 'application/xhtml+xml') {
				return [$response, null];
			}
		} catch (GuzzleHttp\Exception\ClientException $e) {
			// to do: what is more useful, returning the error or the latest response? maybe both?
			return [null, $e];
		}
		
		if (count($this->archives($url)) > 0) {
			$oldResponse = $this->getResponse($url);
			
			if (!areCachablyDifferent($oldResponse, $response) and !$forceUpdate) {
				return [$oldResponse, null];
			}
		}
		
		$response = $response->withHeader('X-Archive-Effective-URL', $respEffectiveUri);
		
		// archive
		@mkdir($archivePath, 0777, true);
		$fetched = new DateTime();
		// to do: ensure the response has Content-location header
		// to do: ensure the response has Date header
		$p = $archivePath . DIRECTORY_SEPARATOR . $fetched->format('Y-m-d\THis');
		file_put_contents($p . '.html', (string) $response->getBody());

		$headers = "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} {$response->getReasonPhrase()}\r\n";
		foreach ($response->getHeaders() as $name => $values) {
			$headers .= $name . ': ' . implode(', ', $values) . "\r\n";
		}

		file_put_contents($p . '-headers.txt', $headers);
		return [$response, null];
	}
	
	public function archives($url) {
		$archivePath = $this->basepathForUrl($url);
		if (!file_exists($archivePath))
			return [];
		$archiveFiles = array_filter(scandir($archivePath), function ($i) use ($archivePath) {
			// ignore directories and hidden files
			if ($i[0] == '.')
				return false;
			$p = $archivePath . DIRECTORY_SEPARATOR . $i;
			return !is_dir($p);
		});
		
		$archives = array_reduce($archiveFiles, function ($versions, $current) {
			$archiveId = substr($current, 0, 17);
			$versions[] = $archiveId;
			return array_unique($versions);
		}, []);
		
		return $archives;
	}
	
	public function getResponse($url, $version=null) {
		if ($version === null) {
			$vs = $this->archives($url);
			$version = array_pop($vs);
		}
		
		$headers = file_get_contents($this->basepathForVersion($url, $version) . '-headers.txt');
		$body = file_get_contents($this->basepathForVersion($url, $version) . '.html');
		
		try {
			$resp = GuzzleHttp\Psr7\Message::parseResponse($headers . $body);
		} catch (InvalidArgumentException $e) {
			// Fix invalid headers caused by badly assembling the header strings in one version.
			// Assume HTTP/1.1 200 OK
			$headers = str_replace("\n", "\r\n", $headers);
			$headers = "HTTP/1.1 200 OK\r\n{$headers}";
			file_put_contents($this->basepathForVersion($url, $version) . '-headers.txt', $headers);
			$resp = GuzzleHttp\Psr7\Message::parseResponse($headers . $body);
		}
		
		return $resp;
	}
	
	public function basepathForUrl($url) {
		return $this->path . DIRECTORY_SEPARATOR . urlToFilesystemPath($url);
	}
	
	public function basepathForVersion($url, $version) {
		return $this->basepathForUrl($url) . DIRECTORY_SEPARATOR . $version;
	}
}
