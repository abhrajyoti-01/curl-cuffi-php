# Changelog

All notable changes to this project are listed here. Versions follow
[Semantic Versioning](https://semver.org/); before 1.0, a minor version may change the API.

## Unreleased

First release: a PHP port of Python curl_cffi 0.17 on libcurl-impersonate, with no Python
dependency.

- `Curl`, `CurlMulti`, `CurlMime` and every curl_cffi constant (`CurlOpt`, `CurlInfo`,
  `CurlECode`, ...) over PHP FFI, plus `bin/curl-cffi-install` to download libcurl-impersonate
  and a CA bundle for Linux (glibc, musl), macOS and Windows on x86_64 and arm64.
- Browser impersonation (`impersonate: 'chrome'`, `'safari'`, `'firefox'`, `'edge'`, `'tor'`, ...)
  with the same JA3/JA4 and HTTP/2 fingerprints as Python, checked against Python in CI.
- `Requests::get()` and friends, `Session`, `Response`, `Headers`, `Cookies`: params, data, json,
  multipart and files, auth, proxies, redirects and history, retries, streaming,
  `raise_for_status`, `base_url`, `curl_options` and `curl_infos`.
- `AsyncSession` on `curl_multi` and PHP Fibers: promises, `gather()`, `run()`, `spawn()`,
  `sleep()`, `max_clients`.
- Custom fingerprints: `ja3`, `akamai`, `extra_fp` (`ExtraFingerprints`), `perk`,
  `Fingerprint` and `FingerprintManager`.
- `WebSocket` and `AsyncWebSocket`: send/recv of text, binary and JSON, ping/pong, close codes,
  fragmentation, `run_forever()` callbacks, `ws_retry`.
- `Session::upkeep()`, `AsyncSession::upkeep()`, `Curl::upkeep()` and `Session(curl: ...)`.
- `CurlCffi\Warnings`: curl_cffi's warnings, off by default as in Python
  (`Warnings::config(on: true)` is `config_warnings(on=True)`).
- An API parity test suite that checks every option, method, class, exception and constant of
  Python curl_cffi against the PHP port, with each difference listed and explained.
