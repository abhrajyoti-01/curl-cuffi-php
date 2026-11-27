# curl-cffi-php

A PHP port of Python's [curl_cffi](https://github.com/lexiforest/curl_cffi): an HTTP client that
impersonates real browsers, with matching TLS (JA3/JA4) and HTTP/2 fingerprints. Pure PHP on top
of [libcurl-impersonate](https://github.com/lexiforest/curl-impersonate) through PHP's FFI
extension. No Python, no compiled PHP extension.

> **Status: milestone 2 of 5.** The requests-style API (`Requests`, `Session`, `Response`) and the
> low-level `Curl` class work today. `AsyncSession`, custom JA3/Akamai strings and WebSocket come
> in the next milestones.

## Why not ext-curl?

Stock PHP curl links a system libcurl built on OpenSSL. Its TLS ClientHello and HTTP/2 SETTINGS
frame look nothing like Chrome's, so sites that fingerprint clients block it. libcurl-impersonate
is libcurl patched to use BoringSSL and send byte-for-byte browser handshakes. Python curl_cffi
is a thin wrapper around it; this library is the same wrapper in PHP, so the fingerprints are the
same.

## Requirements

- PHP 8.1+ on a 64-bit OS, with `ext-ffi`
- `ffi.enable=true` in php.ini for web servers (the default `preload` only allows the CLI)
- Linux (glibc or musl), macOS or Windows on x86_64 or arm64

## Install

```bash
composer require abhrajyoti-01/curl-cffi-php
vendor/bin/curl-cffi-install      # downloads libcurl-impersonate + CA bundle into the package's lib/
```

To use your own build, set `CURL_CFFI_LIBRARY=/path/to/libcurl-impersonate.so` or call
`CurlCffi\Ffi\Library::setPath()` before the first request.

## Usage

```php
use CurlCffi\Requests\Requests;
use CurlCffi\Requests\Session;

// One-off request, like curl_cffi.requests.get()
$r = Requests::get('https://tls.peet.ws/api/clean', impersonate: 'chrome');
echo $r->statusCode, "\n", $r->text;
print_r($r->json());

// Session: keeps cookies, headers and connections, like curl_cffi.requests.Session()
$s = new Session(impersonate: 'safari', timeout: 15, headers: ['X-App' => 'demo']);
$r = $s->get('https://httpbin.org/get', params: ['q' => 'php']);
$r = $s->post('https://httpbin.org/post', json: ['a' => 1]);
$r = $s->post('https://httpbin.org/post', data: ['field' => 'value']);
$r = $s->post('https://httpbin.org/post', files: ['doc' => '/path/to/file.pdf']);
echo $s->cookies['session_id'] ?? '';

// Proxies, auth, redirects, TLS
$r = Requests::get($url, proxy: 'http://user:pass@127.0.0.1:8080', auth: ['user', 'pass'],
                   allow_redirects: false, verify: false);

// Streaming
$r = $s->stream('GET', 'https://example.com/big.ndjson');
foreach ($r->iterLines() as $line) { /* ... */ }
$r->close();

// Errors are typed like Python's
try {
    Requests::get('https://example.com', timeout: 0.5)->raiseForStatus();
} catch (\CurlCffi\Requests\Exceptions\Timeout $e) {
} catch (\CurlCffi\Requests\Exceptions\HTTPError $e) {
    echo $e->response?->statusCode;
}
```

Options are passed as PHP named arguments with the same names as Python (`allow_redirects`,
`max_redirects`, `proxy_auth`, `default_headers`, ...). camelCase spellings (`allowRedirects`) work
too. Unknown options throw a `TypeError`, like Python.

### Python to PHP

| Python curl_cffi | PHP |
| --- | --- |
| `requests.get(url, impersonate="chrome")` | `Requests::get($url, impersonate: 'chrome')` |
| `requests.post(url, json={...})` | `Requests::post($url, json: [...])` |
| `s = Session(impersonate="chrome")` | `$s = new Session(impersonate: 'chrome')` |
| `s.get(url, params={...}, headers={...})` | `$s->get($url, params: [...], headers: [...])` |
| `with s.stream("GET", url) as r:` | `$r = $s->stream('GET', $url); ...; $r->close();` |
| `r.status_code`, `r.text`, `r.content`, `r.json()` | `$r->statusCode`, `$r->text`, `$r->content`, `$r->json()` |
| `r.headers["content-type"]`, `r.cookies`, `r.history` | `$r->headers['content-type']`, `$r->cookies`, `$r->history` |
| `r.raise_for_status()`, `r.iter_lines()` | `$r->raiseForStatus()`, `$r->iterLines()` |
| `CurlMime()` + `multipart=` | `new CurlMime()` + `multipart:` |
| `RetryStrategy(count=3, delay=1)` | `new RetryStrategy(count: 3, delay: 1)` |
| `curl_cffi.requests.exceptions.*` | `CurlCffi\Requests\Exceptions\*` |

Response properties also answer to their Python names (`$r->status_code`, `$r->raise_for_status()`).

Differences from Python:

- PHP has no threads. Streaming runs on `curl_multi` in the same process; read the body with
  `iterContent()`, `iterLines()` or `read()`, then `close()`.
- `files:` is a PHP shortcut for simple uploads (`['field' => '/path']` or
  `['field' => ['data' => '...', 'filename' => 'a.txt', 'content_type' => 'text/plain']]`).
  Python only has `multipart=`, which works here too.
- `json()` returns arrays by default; pass `associative: false` for objects.
- Not yet: `ja3`, `akamai`, `extra_fp` and `perk` (milestone 4), the response cache and `thread=`.

### Low level, same as `curl_cffi.Curl`

```php
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Const\CurlInfo;

$c = new Curl();
$c->impersonate('chrome');
$c->setopt(CurlOpt::URL, 'https://tls.peet.ws/api/clean');
$body = '';
$c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body) { $body .= $chunk; });
$c->perform();
echo $c->getinfo(CurlInfo::RESPONSE_CODE), "\n", $body;
```

All `CurlOpt`, `CurlInfo`, `CurlECode`, `CurlHttpVersion` and other constants have the same names
and values as curl_cffi, including the impersonation options such as `CurlOpt::HTTP2_SETTINGS` and
`CurlOpt::TLS_EXTENSION_ORDER`. They are generated from curl_cffi's `const.py` by
`tools/generate-constants.php`.

## Roadmap

1. Core binding: FFI loader, installer, `Curl`, constants (done)
2. `Requests::get()`, `Session`, `Response`, cookies, redirects, proxies, multipart, streaming (done)
3. `AsyncSession` on `curl_multi` + PHP Fibers
4. Custom `ja3` / `akamai` / `extra_fp`, `WebSocket`
5. Parity test suite, CI on Linux/macOS/Windows, Packagist release

## Tests

```bash
composer install
CURL_CFFI_LIBRARY=libcurl.so.4 composer test     # against stock libcurl (impersonation tests skip)
php bin/curl-cffi-install && composer test       # against libcurl-impersonate
```

CI also runs `tests/Fingerprint`, which compares the JA4 and HTTP/2 fingerprints PHP sends with
what Python curl_cffi sends for the same browser targets.

## License

MIT. Constant tables are derived from curl_cffi (MIT).
