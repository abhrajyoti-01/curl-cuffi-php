# curl-cffi-php

A PHP port of Python's [curl_cffi](https://github.com/lexiforest/curl_cffi): an HTTP client that
impersonates real browsers, with matching TLS (JA3/JA4) and HTTP/2 fingerprints. Pure PHP on top
of [libcurl-impersonate](https://github.com/lexiforest/curl-impersonate) through PHP's FFI
extension. No Python, no compiled PHP extension.

> **Status: milestone 4 of 5.** `Requests`, `Session`, `AsyncSession`, custom fingerprints
> (`ja3`, `akamai`, `extra_fp`, `perk`, `Fingerprint`), `WebSocket` / `AsyncWebSocket` and the
> low-level `Curl` class work today. Milestone 5 adds more platforms in CI and the Packagist release.

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

### Async

`AsyncSession` runs many requests at once on one `curl_multi` handle, like Python's
`AsyncSession`. Its methods return a `Promise`; `await()` gives the `Response` or throws.
Coroutines are PHP Fibers, so no extension or event-loop library is needed.

```php
use CurlCffi\Requests\AsyncSession;

$s = new AsyncSession(impersonate: 'chrome', max_clients: 10);

// await s.get(url)
$r = $s->get('https://example.com')->await();

// await asyncio.gather(*[s.get(u) for u in urls]); keys are kept
$responses = $s->gather(array_map(fn ($u) => $s->get($u), $urls));
$results = $s->gather(['a' => $s->get($a), 'b' => $s->get($b)], returnExceptions: true);

// async def ...: coroutines that await one step after another
$task = function (string $user) use ($s) {
    $s->post('https://example.com/login', data: ['user' => $user])->await();
    $s->sleep(0.5)->await();                       // await asyncio.sleep(0.5)

    return $s->get('https://example.com/me')->await()->json();
};
$profiles = $s->run(fn () => $s->gather([fn () => $task('ann'), fn () => $task('bob')]));

// callbacks, if you prefer them
$s->get($url)->then(fn ($r) => print($r->statusCode))->catch(fn ($e) => print($e->getMessage()));
$s->loop()->run();                                 // run until everything has finished

$s->close();                                       // unfinished requests reject with SessionClosed
```

At most `max_clients` transfers run at once; the rest wait their turn. Cookies, headers,
`retry`, `raise_for_status` and every other session option work as in `Session`. Each promise's
`await()` inside a coroutine (`run()` or `spawn()`) suspends only that coroutine; anywhere else it
runs the loop until the result is ready.

### Custom fingerprints

Besides the built-in browser targets, you can send any TLS and HTTP/2 fingerprint, exactly as in
Python:

```php
use CurlCffi\Impersonate\Fingerprint;
use CurlCffi\Impersonate\FingerprintManager;

// ja3 + akamai strings, as printed by tls.peet.ws, plus what they can't express
$r = Requests::get($url,
    ja3: '771,4865-4866-4867-49195-49199-49196-49200-52393-52392-49171-49172-156-157-47-53,0-23-65281-10-11-35-16-5-13-18-51-45-43-27-17513,29-23-24,0',
    akamai: '1:65536;2:0;4:6291456;6:262144|15663105|0|m,a,s,p',
    extra_fp: ['tls_signature_algorithms' => ['ecdsa_secp256r1_sha256', 'rsa_pss_rsae_sha256'], 'tls_grease' => true],
);

// A whole fingerprint object (TLS, HTTP/2, HTTP/3, default headers)
$fp = new Fingerprint(tls_version: '1.3', tls_ciphers: [...], http2_settings: '1:65536;4:6291456',
                      headers: ['User-Agent' => '...']);
$r = Requests::get($url, impersonate: $fp);

// Named fingerprints from the fingerprint service, shared with Python's files
FingerprintManager::setApiKey('imp_...');     // or IMPERSONATE_API_KEY
FingerprintManager::updateFingerprints();
$r = Requests::get($url, impersonate: 'a_downloaded_target');
```

`extra_fp` takes an `ExtraFingerprints` object or an array with its field names. These options
need libcurl-impersonate; with stock libcurl they throw `ImpersonateError`.

### WebSocket

```php
use CurlCffi\Requests\WebSocket\WebSocket;

// The handshake uses the session's settings: impersonate, cookies, headers, proxies
$ws = $s->wsConnect('wss://example.com/ws', impersonate: 'chrome', headers: ['Origin' => 'https://example.com']);
$ws->sendStr('hello');                    // also sendBinary(), sendJson(), ping()
[$data, $flags] = $ws->recv(timeout: 5);  // or recvStr(), recvJson()
foreach ($ws as $message) { /* until the server closes */ }
$ws->close();

// Callbacks, like Python's run_forever()
$ws = new WebSocket(
    on_open: fn (WebSocket $ws) => $ws->sendStr('subscribe'),
    on_message: fn (WebSocket $ws, string $message) => print($message . "\n"),
    on_close: fn (WebSocket $ws, int $code, string $reason) => print("closed {$code}\n"),
);
$ws->runForever('wss://example.com/ws', impersonate: 'chrome');

// Async: many sockets and requests at once
$s = new AsyncSession(impersonate: 'chrome');
$s->run(function () use ($s) {
    $ws = $s->wsConnect('wss://example.com/ws')->await();
    $ws->sendStr('hello')->await();
    foreach ($ws as $message) { /* other coroutines keep running */ }
});
```

libcurl answers pings, messages are reassembled from fragments (up to `max_message_size`,
4 MiB by default), and big messages are sent in 64 KiB frames, as in Python.

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
| `ExtraFingerprints(tls_grease=True)` | `new ExtraFingerprints(tls_grease: true)` or `['tls_grease' => true]` |
| `Fingerprint(...)`, `FingerprintManager.get_fingerprint(t)` | `new Fingerprint(...)`, `FingerprintManager::getFingerprint($t)` |
| `ws = s.ws_connect(url)`, `ws.send_str(m)`, `ws.recv()` | `$ws = $s->wsConnect($url)`, `$ws->sendStr($m)`, `$ws->recv()` |
| `WebSocket(on_message=f).run_forever(url)` | `(new WebSocket(on_message: $f))->runForever($url)` |
| `ws = await s.ws_connect(url)`, `await ws.recv()` | `$ws = $s->wsConnect($url)->await()`, `$ws->recv()->await()` |
| `async for msg in ws:` | `foreach ($ws as $msg)` inside `$s->run()` |
| `s = AsyncSession(max_clients=10)` | `$s = new AsyncSession(max_clients: 10)` |
| `r = await s.get(url)` | `$r = $s->get($url)->await()` |
| `await asyncio.gather(*tasks)` | `$s->gather($promisesOrClosures)` |
| `asyncio.gather(..., return_exceptions=True)` | `$s->gather($items, returnExceptions: true)` |
| `asyncio.run(main())` | `$s->run($main)` |
| `asyncio.create_task(coro())` | `$s->spawn($fn)` |
| `await asyncio.sleep(1)` | `$s->sleep(1)->await()` |
| `curl_cffi.requests.exceptions.*` | `CurlCffi\Requests\Exceptions\*` |

Response properties also answer to their Python names (`$r->status_code`, `$r->raise_for_status()`).

Differences from Python:

- PHP has no threads. Streaming runs on `curl_multi` in the same process; read the body with
  `iterContent()`, `iterLines()` or `read()`, then `close()`. In `AsyncSession`, reading a streamed
  body blocks the loop, as Python's sync iteration would.
- There is no `async`/`await` syntax: call `->await()` on a promise, and give coroutines as
  closures to `run()`, `spawn()` or `gather()`.
- `files:` is a PHP shortcut for simple uploads (`['field' => '/path']` or
  `['field' => ['data' => '...', 'filename' => 'a.txt', 'content_type' => 'text/plain']]`).
  Python only has `multipart=`, which works here too.
- `json()` returns arrays by default; pass `associative: false` for objects.
- Python's `warnings.warn` (e.g. `ja3` together with `impersonate`) is a PHP `E_USER_WARNING`.
- `WebSocketClosed` and `WebSocketTimeout` extend `WebSocketError` (PHP has no multiple
  inheritance, so they are not also `SessionClosed` / `Timeout`).
- `AsyncSession::wsConnect()` does the handshake synchronously (Python uses a thread), and an
  `AsyncWebSocket` reads while a `recv()` is waiting rather than in a background task. Python's
  queue-tuning options such as `recv_queue_size` don't exist here.
- Not yet: the response cache, the `curl-cffi` command line tool and `thread=`.

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
3. `AsyncSession` on `curl_multi` + PHP Fibers (done)
4. Custom `ja3` / `akamai` / `extra_fp` / `perk`, `Fingerprint`, `WebSocket` / `AsyncWebSocket` (done)
5. Parity test suite, CI on Linux/macOS/Windows, Packagist release

## Tests

```bash
composer install
CURL_CFFI_LIBRARY=libcurl.so.4 composer test     # against stock libcurl (impersonation and ws:// tests may skip)
php bin/curl-cffi-install && composer test       # against libcurl-impersonate
```

CI also runs `tests/Fingerprint`, which compares the JA4 and HTTP/2 fingerprints PHP sends with
what Python curl_cffi sends for the same browser targets, through `Curl`, `Requests` and
`AsyncSession`, and for a custom `ja3` + `akamai` + `extra_fp` request.

## License

MIT. Constant tables are derived from curl_cffi (MIT).
