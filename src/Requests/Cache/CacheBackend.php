<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Cache;

use CurlCffi\Requests\Cookies;
use CurlCffi\Requests\Headers;
use CurlCffi\Requests\Request;
use CurlCffi\Requests\Response;

/**
 * A response cache for Session, like Python's curl_cffi.requests.CacheBackend.
 *
 * Entries are HAR 1.2 documents (one entry each) keyed by a SHA-256 of the
 * method, the URL without its fragment and ignored query parameters, and
 * the body. The format and keys are the same as Python's, so PHP and Python
 * can read each other's cache.
 *
 * Subclass it and implement readPayload(), writePayload(), deletePayload()
 * and clear() for another store; FileCacheBackend keeps one JSON file per
 * entry.
 */
abstract class CacheBackend
{
    public readonly \DateInterval $expires;
    /** 0 means entries never expire. */
    public readonly float $expiresSeconds;
    /** @var list<string> upper-case methods to cache, GET by default */
    public readonly array $methods;
    /** @var list<string> query parameters left out of the key, e.g. ['_ts'] */
    public readonly array $ignored;

    /**
     * @param int|float|\DateInterval $expires how long entries stay fresh, in seconds or as an interval
     * @param list<string>|null       $methods
     * @param list<string>|null       $ignored
     */
    public function __construct(int|float|\DateInterval $expires, ?array $methods = null, ?array $ignored = null)
    {
        if ($expires instanceof \DateInterval) {
            $seconds = (float) ((new \DateTimeImmutable('@0'))->add($expires)->format('U.u'));
            $interval = $expires;
        } else {
            $seconds = (float) $expires;
            $interval = new \DateInterval('PT' . max(0, (int) floor($seconds)) . 'S');
        }
        if ($seconds < 0) {
            throw new \InvalidArgumentException('expires must be >= 0');
        }
        $this->expires = $interval;
        $this->expiresSeconds = $seconds;
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods ?: ['GET'])));
        $this->ignored = array_values(array_unique($ignored ?? []));
    }

    /**
     * Python's normalize_cache_backend(): a CacheBackend is used as is; seconds
     * or a DateInterval give a FileCacheBackend with that lifetime.
     */
    public static function normalize(self|int|float|\DateInterval|null $cache): ?self
    {
        if ($cache === null || $cache instanceof self) {
            return $cache;
        }

        return new FileCacheBackend(expires: $cache);
    }

    /** Whether to look up and store this request. Streamed responses are never cached. */
    public function shouldCacheRequest(Request $request, bool $stream = false, mixed $contentCallback = null): bool
    {
        return in_array(strtoupper($request->method), $this->methods, true) && !$stream && $contentCallback === null;
    }

    public function shouldStoreResponse(Response $response): bool
    {
        return $response->ok;
    }

    /**
     * The cached response for $request, or null when there is none or it expired.
     *
     * @param class-string<Response> $responseClass
     */
    public function get(Request $request, string $responseClass = Response::class): ?Response
    {
        $payload = $this->readPayload($this->cacheKey($request));
        if ($payload === null) {
            return null;
        }
        $entry = $payload['log']['entries'][0];
        $createdAt = self::parseTimestamp((string) $entry['startedDateTime']);
        if ($this->expiresSeconds > 0 && (microtime(true) - $createdAt) > $this->expiresSeconds) {
            $this->delete($request);

            return null;
        }

        return $this->responseFromPayload($request, $payload, $responseClass);
    }

    public function set(Request $request, Response $response): void
    {
        if (!$this->shouldStoreResponse($response)) {
            return;
        }
        $this->writePayload($this->cacheKey($request), $this->payloadFromResponse($response));
    }

    public function delete(Request $request): void
    {
        $this->deletePayload($this->cacheKey($request));
    }

    /** Remove every entry. */
    abstract public function clear(): void;

    /** @return array<string, mixed>|null the HAR document stored under $key */
    abstract protected function readPayload(string $key): ?array;

    /** @param array<string, mixed> $payload */
    abstract protected function writePayload(string $key, array $payload): void;

    abstract protected function deletePayload(string $key): void;

    /** sha256 of {"body":sha256(body),"method":...,"url":...}, as Python's _cache_key(). */
    protected function cacheKey(Request $request): string
    {
        $raw = json_encode([
            'body' => hash('sha256', $request->body ?? ''),
            'method' => strtoupper($request->method),
            'url' => $this->normalizedUrl($request->url),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        // Same bytes as Python's json.dumps(..., separators=(",", ":"), sort_keys=True)
        return hash('sha256', $raw);
    }

    /** The URL without its fragment and ignored query parameters, re-encoded like Python's urlencode(). */
    protected function normalizedUrl(string $url): string
    {
        [$scheme, $netloc, $path, $query] = self::urlsplit($url);
        $pairs = [];
        foreach (self::parseQsl($query) as [$k, $v]) {
            if (!in_array($k, $this->ignored, true)) {
                $pairs[] = self::quotePlus($k) . '=' . self::quotePlus($v);
            }
        }
        // Python's urlunsplit()
        $out = $path;
        if ($netloc !== '') {
            if ($out !== '' && $out[0] !== '/') {
                $out = '/' . $out;
            }
            $out = '//' . $netloc . $out;
        } elseif (str_starts_with($out, '//')) {
            $out = '//' . $out;
        }
        if ($scheme !== '') {
            $out = $scheme . ':' . $out;
        }
        if ($pairs !== []) {
            $out .= '?' . implode('&', $pairs);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function payloadFromResponse(Response $response): array
    {
        $request = $response->request;
        $defaultEncoding = is_string($response->defaultEncoding) ? $response->defaultEncoding : 'utf-8';
        $elapsedMs = $response->elapsed * 1000;

        $harRequest = [
            'method' => $request?->method ?? '',
            'url' => $request?->url ?? '',
            'httpVersion' => '',
            'cookies' => [],
            'headers' => $request !== null ? self::harHeaders($request->headers) : [],
            'queryString' => $request !== null ? self::harQueryString($request->url) : [],
            'headersSize' => -1,
            'bodySize' => $request?->body !== null ? strlen($request->body) : 0,
        ];
        if ($request?->body !== null) {
            $harRequest['postData'] = [
                'mimeType' => $request->headers->get('Content-Type') ?? 'application/octet-stream',
                'text' => base64_encode($request->body),
                'encoding' => 'base64',
            ];
        }

        return [
            'log' => [
                'version' => '1.2',
                'creator' => ['name' => 'curl_cffi', 'version' => 'cache'],
                'entries' => [[
                    'startedDateTime' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                    'time' => $elapsedMs,
                    'request' => $harRequest,
                    'response' => [
                        'status' => $response->statusCode,
                        'statusText' => $response->reason,
                        'httpVersion' => (string) $response->httpVersion,
                        'cookies' => [],
                        'headers' => self::harHeaders($response->headers),
                        'content' => [
                            'size' => strlen($response->content),
                            'mimeType' => $response->headers->get('Content-Type') ?? 'application/octet-stream',
                            'text' => base64_encode($response->content),
                            'encoding' => 'base64',
                        ],
                        'redirectURL' => $response->redirectUrl,
                        'headersSize' => $response->headerSize,
                        'bodySize' => $response->downloadSize,
                        '_curl_cffi' => [
                            'url' => $response->url,
                            'default_encoding' => $defaultEncoding,
                            'redirect_count' => $response->redirectCount,
                            'primary_ip' => $response->primaryIp,
                            'primary_port' => $response->primaryPort,
                            'local_ip' => $response->localIp,
                            'local_port' => $response->localPort,
                            'download_size' => $response->downloadSize,
                            'upload_size' => $response->uploadSize,
                            'header_size' => $response->headerSize,
                            'request_size' => $response->requestSize,
                            'response_size' => $response->responseSize,
                        ],
                    ],
                    'cache' => new \stdClass(),
                    'timings' => ['send' => 0, 'wait' => $elapsedMs, 'receive' => 0],
                ]],
            ],
        ];
    }

    /**
     * @param array<string, mixed>   $payload
     * @param class-string<Response> $responseClass
     */
    protected function responseFromPayload(Request $request, array $payload, string $responseClass): Response
    {
        $entry = $payload['log']['entries'][0];
        $har = $entry['response'];
        $extra = $har['_curl_cffi'] ?? [];

        $headers = new Headers(array_map(
            static fn (array $h): array => [(string) ($h['name'] ?? ''), (string) ($h['value'] ?? '')],
            $har['headers'] ?? []
        ));
        $r = new $responseClass(null, $request);
        $r->request = $request;
        $r->url = (string) ($extra['url'] ?? $request->url);
        $r->content = (string) base64_decode((string) $har['content']['text'], true);
        $r->statusCode = (int) $har['status'];
        $r->reason = (string) ($har['statusText'] ?? '');
        $r->ok = $r->statusCode >= 200 && $r->statusCode < 400;
        $r->headers = $headers;
        $r->cookies = Cookies::fromSetCookieHeaders($headers->getList('set-cookie'));
        $r->defaultEncoding = (string) ($extra['default_encoding'] ?? 'utf-8');
        $r->elapsed = (float) ($entry['time'] ?? 0.0) / 1000;
        $r->redirectCount = (int) ($extra['redirect_count'] ?? 0);
        $r->redirectUrl = (string) ($har['redirectURL'] ?? '');
        $r->httpVersion = (int) ($har['httpVersion'] ?? 0);
        $r->primaryIp = (string) ($extra['primary_ip'] ?? '');
        $r->primaryPort = (int) ($extra['primary_port'] ?? 0);
        $r->localIp = (string) ($extra['local_ip'] ?? '');
        $r->localPort = (int) ($extra['local_port'] ?? 0);
        $r->downloadSize = (int) ($extra['download_size'] ?? ($har['content']['size'] ?? strlen($r->content)));
        $r->uploadSize = (int) ($extra['upload_size'] ?? 0);
        $r->headerSize = (int) ($extra['header_size'] ?? 0);
        $r->requestSize = (int) ($extra['request_size'] ?? 0);
        $r->responseSize = (int) ($extra['response_size'] ?? ($r->downloadSize + $r->headerSize));

        return $r;
    }

    /** Seconds since the epoch of a HAR startedDateTime such as 2026-11-28T08:40:55.123456Z. */
    protected static function parseTimestamp(string $value): float
    {
        $dt = new \DateTimeImmutable($value);

        return (float) $dt->format('U.u');
    }

    /** @return list<array{name: string, value: string}> */
    private static function harHeaders(Headers $headers): array
    {
        return array_map(
            static fn (array $h): array => ['name' => $h[0], 'value' => $h[1] ?? ''],
            $headers->multiItems()
        );
    }

    /** @return list<array{name: string, value: string}> */
    private static function harQueryString(string $url): array
    {
        return array_map(
            static fn (array $p): array => ['name' => $p[0], 'value' => $p[1]],
            self::parseQsl(self::urlsplit($url)[3])
        );
    }

    /**
     * Python's urlsplit(): [scheme (lower-case), netloc, path, query]; the fragment is dropped.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function urlsplit(string $url): array
    {
        preg_match('~^(?:([A-Za-z][A-Za-z0-9+.\-]*):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?~', $url, $m);

        return [strtolower($m[1] ?? ''), $m[2] ?? '', $m[3] ?? '', $m[4] ?? ''];
    }

    /**
     * Python's parse_qsl(query, keep_blank_values=True).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function parseQsl(string $query): array
    {
        $out = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $out[] = [urldecode($k), urldecode($v)];
        }

        return $out;
    }

    /** Python's quote_plus(): only letters, digits and "_.-~" stay as they are. */
    private static function quotePlus(string $s): string
    {
        return str_replace('%20', '+', rawurlencode($s));
    }
}
