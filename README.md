# taproot/archive

Your personal opinionated indieweb- and microformats-oriented HTML archiver.

## Usage

Install with [Composer](https://getcomposer.org): `./composer.phar require taproot/archive:~0.1`

### Creating an archive

Pass a basepath to the constructor — that’s the root of the archive.

```php
<?php

$archive = new Taproot\Archive(__DIR__ . '/data/');
```

### Archive a URL

Methods for archiving a URL return an array of [Guzzle Response Object, Error|null]. Typical usage looks like this:

```php
<?php

list($response, $err) = $archive->archive('http://indiewebcamp.com/Taproot');
if ($err !== null) {
	// handle the exception, which is an instance implementing Guzzle\Common\Exception\GuzzleException
} else {
	echo $response->getBody(true);
}

// The data directory now looks something like this:
// 	data/
// 		http/
// 			indiewebcamp.com/
// 				Taproot/
// 					YYYY-MM-DDTHHMMSS.html
// 					YYYY-MM-DDTHHMMSS-headers.txt

```

Calling `archive` will *always* fetch the latest version of a page from the network and return what it fetched. Along the way, it might also archive it depending on the following conditions:

* is the page served as HTML or XHTML? if not do not archive
* was $force (the second parameter of `archive()` set to true? if so, archive
* is there already an archived copy of this page?
	* if so, compare the two. If they’re cacheably different, archive the new one

So, to ensure another copy of already cached HTML page is made, regardless of them being exactly the same, call `$archive->archive($url, true);`.

Query strings and hash fragments are ignored when saving archives (hence opinionated), as hash fragments have no effect on the HTML content returned by the server, and using query strings is bad permalink design.

### Fetching Archived Pages

There are two ways to fetch pages from the archive: getting a single copy, or getting a list of version IDs (timestamps) which exist for that URL.

```php
<?php

$url = 'http://waterpigs.co.uk/notes/1000';

list($resp, $err) = $archive->get($url);
if ($err !== null) {
	// Failure! There was no archived copy of that URL, and fetching it from the server returned an error response
} else {
	// Success! Either there was an archived copy, in which case the latest one was returned, or the URL was archived as if $archive->archive($url) had been called.
}

$versions = $archive->archives($url);
// $versions is an array of string archive IDs which match the form 'YYYY-MM-DDTHHMMSS'

// getResponse allows you to get a particular archived version of a URL
list($resp, $err) = $archive->getResponse($url, $versions[0]);

```

Currently if you want to get a particular version of a URL you have to do the dance given above — in the future the `get` method will be extended to accept a datetime, and returning the closest existing archive to that time, falling back to a fresh copy as usual.

## Testing

taproot/archive has a minimal PHPUnit test suite, which at the moment just tests some of the URL-to-filesystem path cases, and does a basic functional test of archiving a page.

Contributions very welcome, whether they’re issues raised or pull requests! If you have a problem and you’re capable of writing a unit test demonstrating it please do, as it makes it so much easier to fix. Otherwise don’t worry, just raise an issue with as much useful information as you can :)

## Changelog

### v0.1.0

* Initial extraction from Taproot
* Stub of a test suite
* Basic documentation
