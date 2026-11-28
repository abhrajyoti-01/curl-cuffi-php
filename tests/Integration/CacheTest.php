<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Cache\CacheBackend;
use CurlCffi\Requests\Cache\FileCacheBackend;
use CurlCffi\Requests\Exceptions\HTTPError;
use CurlCffi\Requests\Headers;
use CurlCffi\Requests\Request;
use CurlCffi\Requests\Response;
use CurlCffi\Requests\Session;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'curl-cffi-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * @param list<string>|null $methods
     * @param list<string>|null $ignored
     */
    private function backend(int|float $expires = 60, ?array $methods = null, ?array $ignored = null): FileCacheBackend
    {
        return new FileCacheBackend(expires: $expires, path: $this->dir, methods: $methods, ignored: $ignored);
    }

    public function testSecondGetComesFromTheCache(): void
    {
        $s = new Session(cache: $this->backend());
        self::assertInstanceOf(FileCacheBackend::class, $s->cacheBackend());
        self::assertSame($s->cacheBackend(), $s->cache_backend);

        $a = $s->get(LocalServer::url('/random'), params: ['q' => 1]);
        $b = $s->get(LocalServer::url('/random'), params: ['q' => 1]);
        self::assertSame($a->json()['value'], $b->json()['value'], 'served from the cache');
        self::assertSame($a->url, $b->url);
        self::assertSame(200, $b->statusCode);
        self::assertSame('application/json', $b->headers['content-type']);
        self::assertSame('GET', $b->request?->method);
        self::assertCount(1, glob($this->dir . '/*.json'));

        $c = $s->get(LocalServer::url('/random'), params: ['q' => 2]);
        self::assertNotSame($a->json()['value'], $c->json()['value'], 'another URL is another entry');
    }

    public function testWhatIsNotCached(): void
    {
        $s = new Session(cache: $this->backend());
        // POST is not in the default methods
        $a = $s->post(LocalServer::url('/random'), data: 'x');
        self::assertNotSame($a->json()['value'], $s->post(LocalServer::url('/random'), data: 'x')->json()['value']);
        // streamed and content_callback requests are never cached
        $r = $s->get(LocalServer::url('/random'), stream: true);
        $r->read();
        $r->close();
        $s->get(LocalServer::url('/random'), content_callback: static function (string $chunk): void {});
        // a response that is not ok is not stored
        $s->get(LocalServer::url('/random'), params: ['status' => 404]);
        self::assertSame([], glob($this->dir . '/*.json'));
    }

    public function testPostIsKeyedByBodyAndIgnoredParamsAreDropped(): void
    {
        $s = new Session(cache: $this->backend(60, methods: ['get', 'POST'], ignored: ['_ts']));
        $a = $s->post(LocalServer::url('/random'), json: ['a' => 1])->json()['value'];
        self::assertSame($a, $s->post(LocalServer::url('/random'), json: ['a' => 1])->json()['value']);
        self::assertNotSame($a, $s->post(LocalServer::url('/random'), json: ['a' => 2])->json()['value']);

        $b = $s->get(LocalServer::url('/random?_ts=1&x=1'))->json()['value'];
        self::assertSame($b, $s->get(LocalServer::url('/random?x=1&_ts=2'))->json()['value']);
    }

    public function testExpiryAndBrokenEntries(): void
    {
        $s = new Session(cache: $this->backend(0.001));
        $a = $s->get(LocalServer::url('/random'))->json()['value'];
        usleep(20_000);
        self::assertNotSame($a, $s->get(LocalServer::url('/random'))->json()['value'], 'expired');

        $s = new Session(cache: $this->backend());
        $a = $s->get(LocalServer::url('/random'))->json()['value'];
        [$file] = glob($this->dir . '/*.json');
        file_put_contents($file, '{not json');
        self::assertNotSame($a, $s->get(LocalServer::url('/random'))->json()['value'], 'a broken entry is a miss');

        $s->cacheBackend()?->clear();
        self::assertSame([], glob($this->dir . '/*.json'));
    }

    public function testCachedCookiesAndRaiseForStatus(): void
    {
        $backend = $this->backend();
        (new Session(cache: $backend))->get(LocalServer::url('/random'), params: ['cookie' => 'v1']);

        $s = new Session(cache: $backend);
        $r = $s->get(LocalServer::url('/random'), params: ['cookie' => 'v1']);
        self::assertSame('v1', $r->cookies['cache_cookie']);
        self::assertSame('v1', $s->cookies['cache_cookie'], 'a cached response still sets session cookies');

        $s = new Session(cache: $backend, discard_cookies: true);
        $s->get(LocalServer::url('/random'), params: ['cookie' => 'v1']);
        self::assertNull($s->cookies->get('cache_cookie'));

        // raise_for_status applies to cached responses too
        $strict = new Session(cache: $backend, raise_for_status: true);
        self::assertSame(200, $strict->get(LocalServer::url('/random'))->statusCode);
        self::assertSame(200, $strict->get(LocalServer::url('/random'))->statusCode, 'cached');
        $this->expectException(HTTPError::class);
        $strict->get(LocalServer::url('/random'), params: ['status' => 500]);
    }

    public function testCacheOptionShortcuts(): void
    {
        $backend = CacheBackend::normalize(3600);
        self::assertInstanceOf(FileCacheBackend::class, $backend);
        self::assertSame(3600.0, $backend->expiresSeconds);
        self::assertSame(FileCacheBackend::defaultPath(), $backend->path);
        self::assertSame(['GET'], $backend->methods);
        self::assertSame(86400.0, CacheBackend::normalize(new \DateInterval('P1D'))?->expiresSeconds);
        self::assertNull(CacheBackend::normalize(null));
        self::assertNull((new Session())->cacheBackend());

        $this->expectException(\LogicException::class);
        new AsyncSession(cache: 60);   // not supported, as in Python
    }

    public function testNegativeExpiryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->backend(-1);
    }

    /**
     * Keys computed with Python's own algorithm (curl_cffi/requests/cache.py,
     * stdlib only), so PHP and Python read each other's cache files.
     */
    public function testKeysMatchPython(): void
    {
        $probe = new class (expires: 60) extends CacheBackend {
            public function clear(): void
            {
            }

            protected function readPayload(string $key): ?array
            {
                return null;
            }

            protected function writePayload(string $key, array $payload): void
            {
            }

            protected function deletePayload(string $key): void
            {
            }

            public function keyOf(Request $r): string
            {
                return $this->cacheKey($r);
            }

            public function urlOf(string $url): string
            {
                return $this->normalizedUrl($url);
            }
        };
        $ignoring = new class (expires: 60, ignored: ['_ts']) extends CacheBackend {
            public function clear(): void
            {
            }

            protected function readPayload(string $key): ?array
            {
                return null;
            }

            protected function writePayload(string $key, array $payload): void
            {
            }

            protected function deletePayload(string $key): void
            {
            }

            public function keyOf(Request $r): string
            {
                return $this->cacheKey($r);
            }

            public function urlOf(string $url): string
            {
                return $this->normalizedUrl($url);
            }
        };
        $cases = [
            [$ignoring, 'GET', 'https://example.com/a/b?x=1&y=two words&_ts=99#frag', null,
                'https://example.com/a/b?x=1&y=two+words', 'c77f2b39a58ea032d5cb9ae4289340e7c8933be324ce2c063ddd90aaa0cb4589'],
            [$probe, 'get', 'HTTPS://Example.com', null,
                'https://Example.com', '82cf58b723a07a022aa5550cb02324985f14cbd415a73fa91a1e68adea9daebe'],
            [$probe, 'POST', 'http://127.0.0.1:8080/post?q=caf%C3%A9&e=&flag&t=a~b*c', '{"a":1}',
                'http://127.0.0.1:8080/post?q=caf%C3%A9&e=&flag=&t=a~b%2Ac', '52732b04110e09f5877de2289af5e37b8ec81ba40ac605fbcf4a8fc7b3030074'],
            [$probe, 'GET', 'https://user:pw@example.com:8443/p%20q?a=1+2&a=3', null,
                'https://user:pw@example.com:8443/p%20q?a=1+2&a=3', 'c47ec134692bfca62c4f567718779f82b8155ff21c491b0d87344f6d3dd1df89'],
        ];
        foreach ($cases as [$backend, $method, $url, $body, $normalized, $key]) {
            self::assertSame($normalized, $backend->urlOf($url), $url);
            self::assertSame($key, $backend->keyOf(new Request($url, new Headers(), $method, $body)), $url);
        }
    }

    public function testHarRoundTrip(): void
    {
        $backend = $this->backend();
        $request = new Request('https://example.com/x?a=1', new Headers(['Content-Type' => 'text/plain']), 'GET', 'body');
        $r = new Response(null, $request);
        $r->url = 'https://example.com/x?a=1';
        $r->content = "\x00binary\xff";
        $r->statusCode = 201;
        $r->reason = 'Created';
        $r->headers = new Headers([['Content-Type', 'application/octet-stream'], ['Set-Cookie', 'a=1'], ['Set-Cookie', 'b=2']]);
        $r->elapsed = 0.25;
        $r->httpVersion = 3;
        $r->primaryIp = '93.184.216.34';
        $r->primaryPort = 443;
        $backend->set($request, $r);

        [$file] = glob($this->dir . '/*.json');
        $har = json_decode((string) file_get_contents($file), true);
        self::assertSame('1.2', $har['log']['version']);
        self::assertSame('base64', $har['log']['entries'][0]['response']['content']['encoding']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $har['log']['entries'][0]['startedDateTime']);

        $back = $backend->get($request);
        self::assertNotNull($back);
        self::assertSame("\x00binary\xff", $back->content);
        self::assertSame([201, 'Created', 3, '93.184.216.34', 443], [$back->statusCode, $back->reason, $back->httpVersion, $back->primaryIp, $back->primaryPort]);
        self::assertSame(['a' => '1', 'b' => '2'], $back->cookies->getDict());
        self::assertEqualsWithDelta(0.25, $back->elapsed, 1e-9);

        $backend->delete($request);
        self::assertNull($backend->get($request));
    }
}
