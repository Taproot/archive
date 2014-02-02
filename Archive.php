<?php

namespace Taproot;

use DateTime;
use Guzzle;
use Icap\HtmlDiff\HtmlDiff;
use Mf2;

function urlToFilesystemPath($url) {
	$u = parse_url($url);
	
	if (isset($u['path']) and strstr('/' . $u['path'] . '/', '/../') !== false)
		return null;
	
	return $u['scheme']
		. '/'
		. $u['host']
		. (isset($u['port']) ? ':' . $u['port'] : '')
		. '/'
		. (isset($u['path']) ? trim($u['path'], '/') : '');
}

// This shim switching based on hostname really belongs in mf2/shim.
function mfForResponse(Guzzle\Http\Message\Response $resp) {
	$html = $resp->getBody(true);
	$host = parse_url($resp->getEffectiveUrl(), PHP_URL_HOST);
	if ($host == 'twitter.com') {
		return Mf2\Shim\parseTwitter($html, $resp->getEffectiveUrl());
	} elseif ($host == 'facebook.com') {
		return Mf2\Shim\parseFacebook($html, $resp->getEffectiveUrl());
	} else {
		return Mf2\parse($html, $resp->getEffectiveUrl());
	}
}

function hasMicroformats($resp) {
	$mf = mfForResponse($resp);
	return count($mf['items']) > 0;
}

function areCachablyDifferent(Guzzle\Http\Message\Response $oldResp, Guzzle\Http\Message\Response $newResp) {
	if (hasMicroformats($oldResp) and hasMicroformats($newResp)) {
		// if mf changed, probably significantly different enough to warrant a new cache entry
		return mfForResponse($oldResp) != mfForResponse($newResp);
	} else {
		// look for last-updated header
		if ($newResp->getLastModified() != null) {
			$lastModified = new DateTime($newResp->getLastModified());
			$lastArchived = new DateTime($oldResp->getDate());
			
			return $lastModified > $lastArchived;
		}
		
		$differ = new HtmlDiff($oldResp->getBody(true), $newResp->getBody(true), true);
		$mods = $differ->outputDiff()->getModifications();
		// to do : figure out a sensible threshold here
		return max($mods) > 2;
	}
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
		$client = new Guzzle\Http\Client();
		$archivePath = $this->basepathForUrl($url);
		
		try {
			$response = $client->get($url)->send();
			if (!$response->isContentType('text/html') and !$response->isContentType('application/xhtml+xml')) {
				return [$response, null];
			}
		} catch (Guzzle\Common\Exception\GuzzleException $e) {
			// to do: what is more useful, returning the error or the latest response? maybe both?
			return [null, $e];
		}
		
		if (count($this->archives($url)) > 0) {
			$oldResponse = $this->getResponse($url);
			
			if (!areCachablyDifferent($oldResponse, $response) and !$forceUpdate) {
				return [$oldResponse, null];
			}
		}
		
		$response->setHeader('X-Archive-Effective-URL', $response->getEffectiveUrl());
		
		// archive
		@mkdir($archivePath, 0777, true);
		$fetched = new DateTime();
		// to do: ensure the response has Content-location header
		// to do: ensure the response has Date header
		$p = $archivePath . DIRECTORY_SEPARATOR . $fetched->format('Y-m-d\THis');
		file_put_contents($p . '.html', $response->getBody());
		file_put_contents($p . '-headers.txt', $response->getRawHeaders());
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
		
		$resp = Guzzle\Http\Message\Response::fromMessage($headers . $body);
		$resp->setEffectiveUrl((string) $resp->getHeader('x-archive-effective-url') ?: $url);
		$resp->removeHeader('x-archive-effective-url');
		return $resp;
	}
	
	public function basepathForUrl($url) {
		return $this->path . DIRECTORY_SEPARATOR . urlToFilesystemPath($url);
	}
	
	public function basepathForVersion($url, $version) {
		return $this->basepathForUrl($url) . DIRECTORY_SEPARATOR . $version;
	}
}
