# curl-cffi-php

A PHP port of Python's [curl_cffi](https://github.com/lexiforest/curl_cffi): an HTTP client that
impersonates real browsers, with matching TLS (JA3/JA4) and HTTP/2 fingerprints. Pure PHP on top
of [libcurl-impersonate](https://github.com/lexiforest/curl-impersonate) through PHP's FFI
extension. No Python, no compiled PHP extension.

> **Status: milestone 1 of 5.** The low-level `Curl` class works today. The requests-style API
> (`Session`, `Response`, `AsyncSession`, WebSocket) comes in the next milestones.

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

## Usage (low level, same as `curl_cffi.Curl`)

```php
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Const\CurlInfo;

$c = new Curl();
$c->impersonate('chrome');                 // or 'safari', 'firefox', 'edge101', 'chrome131', ...
$c->setopt(CurlOpt::URL, 'https://tls.peet.ws/api/clean');
$body = '';
$c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body) { $body .= $chunk; });
$c->perform();

echo $c->getinfo(CurlInfo::RESPONSE_CODE), "\n", $body;
```

| Python curl_cffi | PHP |
| --- | --- |
| `Curl()` | `new Curl()` |
| `c.setopt(CurlOpt.URL, b"...")` | `$c->setopt(CurlOpt::URL, '...')` |
| `c.getinfo(CurlInfo.RESPONSE_CODE)` | `$c->getinfo(CurlInfo::RESPONSE_CODE)` |
| `c.impersonate("chrome")` | `$c->impersonate('chrome')` |
| `c.perform()` | `$c->perform()` |
| `CurlError` | `CurlCffi\Curl\CurlError` (`getCode()` is the CURLcode) |
| `BrowserType.chrome131` | `BrowserType::chrome131` |

All `CurlOpt`, `CurlInfo`, `CurlECode`, `CurlHttpVersion` and other constants have the same names
and values as curl_cffi, including the impersonation options such as `CurlOpt::HTTP2_SETTINGS` and
`CurlOpt::TLS_EXTENSION_ORDER`. They are generated from curl_cffi's `const.py` by
`tools/generate-constants.php`.

## Roadmap

1. Core binding: FFI loader, installer, `Curl`, constants (this release)
2. `Requests::get()`, `Session`, `Response`, cookies, redirects, proxies, multipart, streaming
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
