<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Curl\CurlMime;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Impersonate\Fingerprint;
use CurlCffi\Requests\Exceptions\ConnectionError;
use CurlCffi\Requests\Exceptions\HTTPError;
use CurlCffi\Requests\Exceptions\ImpersonateError;
use CurlCffi\Requests\Exceptions\JSONDecodeError;
use CurlCffi\Requests\Exceptions\SessionClosed;
use CurlCffi\Requests\Exceptions\Timeout;
use CurlCffi\Requests\Exceptions\TooManyRedirects;
use CurlCffi\Requests\Requests;
use CurlCffi\Requests\Response;
use CurlCffi\Requests\RetryStrategy;
use CurlCffi\Requests\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    private static function url(string $path): string
    {
        return LocalServer::url($path);
    }

    public function testGetWithParamsAndHeaders(): void
    {
        $r = Requests::get(self::url('/echo?x=0'), params: ['a' => 1, 'flag' => true], headers: ['X-Foo' => 'bar']);

        self::assertSame(200, $r->statusCode);
        self::assertSame(200, $r->status_code, 'Python spelling works too');
        self::assertSame('OK', $r->reason);
        self::assertTrue($r->ok);
        self::assertStringEndsWith('/echo?x=0&a=1&flag=true', $r->url);
        $json = $r->json();
        self::assertSame(['x' => '0', 'a' => '1', 'flag' => 'true'], $json['query']);
        self::assertSame('bar', $json['headers']['X-Foo']);
        self::assertSame('yes', $r->headers['x-test']);
        self::assertSame('abc123', $r->cookies['session']);
        self::assertGreaterThan(0, $r->elapsed);
        self::assertSame('GET', $r->request?->method);
    }

    public function testBodies(): void
    {
        $r = Requests::post(self::url('/echo'), json: ['name' => 'café', 'n' => [1, 2]]);
        self::assertSame('{"name":"café","n":[1,2]}', $r->json()['body']);
        self::assertSame('application/json', $r->json()['headers']['Content-Type']);

        $r = Requests::post(self::url('/echo'), data: ['a' => 'x y', 'b' => 2]);
        self::assertSame('a=x+y&b=2', $r->json()['body']);
        self::assertSame('application/x-www-form-urlencoded', $r->json()['headers']['Content-Type']);

        $r = Requests::put(self::url('/echo'), data: ['a' => '1']);
        self::assertSame('PUT', $r->json()['method']);
        self::assertSame('application/x-www-form-urlencoded', $r->json()['headers']['Content-Type']);

        $r = Requests::patch(self::url('/echo'), content: 'raw bytes');
        self::assertSame('raw bytes', $r->json()['body']);
        self::assertSame('application/octet-stream', $r->json()['headers']['Content-Type']);

        $stream = fopen('php://memory', 'w+');
        fwrite($stream, 'streamed upload');
        rewind($stream);
        $r = Requests::put(self::url('/echo'), content: $stream);
        self::assertSame('streamed upload', $r->json()['body']);

        $r = Requests::post(self::url('/echo'), content: (static function () {
            yield 'part1-';
            yield 'part2';
        })(), headers: ['Content-Length' => '11']);
        self::assertSame('part1-part2', $r->json()['body']);
    }

    public function testMethods(): void
    {
        $s = new Session();
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::assertSame($method, $s->request($method, self::url('/echo'))->json()['method']);
        }
        self::assertSame('DELETE', $s->delete(self::url('/echo'))->json()['method']);
        $head = $s->head(self::url('/echo'));
        self::assertSame(200, $head->statusCode);
        self::assertSame('', $head->content);
    }

    public function testRedirectsAndHistory(): void
    {
        $r = Requests::get(self::url('/redirect/3'));
        self::assertStringEndsWith('/echo', $r->url);
        self::assertSame(3, $r->redirectCount);
        self::assertCount(3, $r->history);
        self::assertSame(302, $r->history[0]->statusCode);
        self::assertStringEndsWith('/redirect/3', $r->history[0]->url);
        self::assertStringEndsWith('/redirect/1', $r->history[2]->url);
        self::assertTrue($r->history[0]->isRedirect());

        $r = Requests::get(self::url('/redirect/1'), allow_redirects: false);
        self::assertSame(302, $r->statusCode);
        self::assertSame('/echo', $r->headers['location']);
        self::assertStringEndsWith('/echo', $r->redirectUrl);

        $this->expectException(TooManyRedirects::class);
        Requests::get(self::url('/redirect/5'), max_redirects: 2);
    }

    public function testSessionCookies(): void
    {
        $s = new Session(cookies: ['preset' => 'p']);
        $r = $s->get(self::url('/cookies/set'), params: ['k1' => 'v1', 'k2' => 'v2']);
        self::assertEqualsCanonicalizing(['preset' => 'p', 'k1' => 'v1', 'k2' => 'v2'], $r->json()['cookies']);
        self::assertSame('v1', $s->cookies['k1']);

        // per-request cookies are sent but not stored
        $r = $s->get(self::url('/cookies'), cookies: ['tmp' => 'x']);
        self::assertSame('x', $r->json()['cookies']['tmp']);
        self::assertNull($s->cookies->get('tmp'));

        // the server deleting a cookie removes it from the session
        $s->get(self::url('/cookies/delete'), params: ['k1' => '']);
        self::assertNull($s->cookies->get('k1'));
        self::assertSame('v2', $s->cookies->get('k2'));

        $s->get(self::url('/cookies/set'), params: ['ignored' => '1'], discard_cookies: true);
        self::assertNull($s->cookies->get('ignored'));
    }

    public function testErrorsAndStatus(): void
    {
        $r = Requests::get(self::url('/status/404'));
        self::assertFalse($r->ok);
        self::assertSame('Not Found', $r->reason);
        try {
            $r->raiseForStatus();
            self::fail('Expected HTTPError');
        } catch (HTTPError $e) {
            self::assertSame($r, $e->response);
        }

        $s = new Session(raise_for_status: true);
        $this->expectException(HTTPError::class);
        $s->get(self::url('/status/500'));
    }

    public function testTimeoutAndConnectionErrors(): void
    {
        try {
            Requests::get(self::url('/delay?s=2'), timeout: 0.3);
            self::fail('Expected Timeout');
        } catch (Timeout $e) {
            self::assertSame(28, $e->getCode());
        }
        $this->expectException(ConnectionError::class);
        Requests::get('http://127.0.0.1:1/');
    }

    public function testRetry(): void
    {
        $s = new Session(retry: new RetryStrategy(count: 2, delay: 0.01));
        $start = microtime(true);
        try {
            $s->get('http://127.0.0.1:1/');
        } catch (ConnectionError) {
        }
        self::assertGreaterThan(0.02, microtime(true) - $start, 'two retries with 0.01s and 0.02s delays');
    }

    public function testStreaming(): void
    {
        $s = new Session();
        $r = $s->stream('GET', self::url('/stream?n=5'));
        self::assertSame(200, $r->statusCode);
        self::assertSame('text/plain;charset=UTF-8', str_replace(' ', '', (string) $r->headers['content-type']));
        $lines = iterator_to_array($r->iterLines(), false);
        self::assertSame(['line 0', 'line 1', 'line 2', 'line 3', 'line 4'], $lines);

        $r = $s->get(self::url('/stream?n=3'), stream: true);
        self::assertSame("line 0\nline 1\nline 2\n", $r->read());

        // closing early stops the transfer
        $r = $s->stream('GET', self::url('/stream?n=50'));
        foreach ($r->iterContent() as $chunk) {
            break;
        }
        $r->close();
        self::assertSame(200, $s->get(self::url('/echo'))->statusCode, 'session still usable');
    }

    public function testContentCallback(): void
    {
        $chunks = [];
        $r = Requests::get(self::url('/stream?n=3'), content_callback: function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        self::assertSame("line 0\nline 1\nline 2\n", implode('', $chunks));
        self::assertSame('', $r->content);
    }

    public function testMultipartAndFiles(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cffi');
        file_put_contents($file, 'file body');

        $r = Requests::post(self::url('/upload'), files: [
            'doc' => $file,
            'inline' => ['data' => 'in memory', 'filename' => 'a.txt', 'content_type' => 'text/plain'],
        ], data: ['field' => 'value']);
        $json = $r->json();
        self::assertSame(['field' => 'value'], $json['post']);
        self::assertSame('file body', $json['files']['doc']['content']);
        self::assertSame('in memory', $json['files']['inline']['content']);
        self::assertSame('a.txt', $json['files']['inline']['name']);

        $mp = new CurlMime();
        $mp->addpart('up', contentType: 'image/png', filename: 'x.png', data: "\x89PNG");
        $r = Requests::post(self::url('/upload'), multipart: $mp);
        $mp->close();
        self::assertSame('image/png', $r->json()['files']['up']['type']);
        unlink($file);
    }

    public function testBaseUrlSessionHeadersAndAuth(): void
    {
        $s = new Session(base_url: self::url('/'), headers: ['X-Session' => '1'], params: ['s' => '1']);
        $r = $s->get('echo', headers: ['X-Request' => '2'], auth: ['user', 'pass']);
        $json = $r->json();
        self::assertSame('/echo', $json['path']);
        self::assertSame(['s' => '1'], $json['query']);
        self::assertSame('1', $json['headers']['X-Session']);
        self::assertSame('2', $json['headers']['X-Request']);
        self::assertSame('Basic ' . base64_encode('user:pass'), $json['headers']['Authorization']);
        self::assertArrayNotHasKey('Expect', $json['headers']);

        // a null header value removes the header
        $r = $s->get('echo', headers: ['Accept' => null]);
        self::assertArrayNotHasKey('Accept', $r->json()['headers']);
    }

    public function testEncodingAndJsonErrors(): void
    {
        $r = Requests::get(self::url('/latin1'));
        self::assertSame('iso-8859-1', $r->encoding);
        self::assertSame('café', $r->text);

        $this->expectException(JSONDecodeError::class);
        $r->json();
    }

    public function testCustomResponseClassAndCurlInfos(): void
    {
        $s = new Session(response_class: MyResponse::class, curl_infos: [\CurlCffi\Curl\Const\CurlInfo::NUM_CONNECTS]);
        $r = $s->get(self::url('/echo'));
        self::assertInstanceOf(MyResponse::class, $r);
        self::assertArrayHasKey(\CurlCffi\Curl\Const\CurlInfo::NUM_CONNECTS, $r->infos);
    }

    public function testOptionValidation(): void
    {
        $this->expectException(\TypeError::class);
        Requests::get(self::url('/echo'), not_an_option: 1);
    }

    public function testCamelCaseOptions(): void
    {
        $r = Requests::get(self::url('/redirect/1'), allowRedirects: false);
        self::assertSame(302, $r->statusCode);
    }

    public function testClosedSession(): void
    {
        $s = new Session();
        $s->close();
        $this->expectException(SessionClosed::class);
        $s->get(self::url('/echo'));
    }

    public function testImpersonate(): void
    {
        if (!Library::supportsImpersonation()) {
            $this->expectException(ImpersonateError::class);
            Requests::get(self::url('/echo'), impersonate: 'chrome');

            return;
        }
        $r = Requests::get(self::url('/echo'), impersonate: 'chrome');
        self::assertStringContainsString('Chrome/', $r->json()['headers']['User-Agent']);

        $r = Requests::get(self::url('/echo'), impersonate: 'chrome', default_headers: false);
        self::assertStringNotContainsString('Chrome/', $r->json()['headers']['User-Agent'] ?? '');

        $this->expectException(ImpersonateError::class);
        Requests::get(self::url('/echo'), impersonate: 'netscape4');
    }

    public function testCustomFingerprintOptions(): void
    {
        $ja3 = '771,4865-4866-4867-49195-49196-52393-49199-49200-52392-49171-49172-156-157-47-53,'
            . '0-23-65281-10-11-35-16-5-13-51-45-43,29-23-24,0';
        $akamai = '4:16777216|16711681|0|m,p,a,s';
        $fp = new Fingerprint(headers: ['User-Agent' => 'FP/1.0', 'X-Fp' => 'from-fingerprint'], http2_settings: '1:65536');

        if (!Library::supportsImpersonation()) {
            // Stock libcurl has no fingerprint options: a clear error, not a silent plain request.
            foreach ([['ja3' => $ja3], ['akamai' => $akamai], ['impersonate' => $fp]] as $opts) {
                try {
                    Requests::get(self::url('/echo'), ...$opts);
                    self::fail('Expected ImpersonateError for ' . key($opts));
                } catch (ImpersonateError $e) {
                    self::assertStringContainsString('libcurl-impersonate', $e->getMessage());
                }
            }

            return;
        }

        $r = Requests::get(self::url('/echo'), ja3: $ja3, akamai: $akamai, extra_fp: ['tls_grease' => false]);
        self::assertSame(200, $r->statusCode);

        // Fingerprint default headers fill in, but never replace the request's own.
        $r = Requests::get(self::url('/echo'), impersonate: $fp, headers: ['X-Fp' => 'mine']);
        $headers = $r->json()['headers'];
        self::assertSame('FP/1.0', $headers['User-Agent']);
        self::assertSame('mine', $headers['X-Fp']);

        $r = Requests::get(self::url('/echo'), impersonate: $fp, default_headers: false);
        self::assertArrayNotHasKey('X-Fp', $r->json()['headers']);

        // Mixing impersonate with ja3 warns, as in Python.
        $warning = null;
        set_error_handler(static function (int $no, string $msg) use (&$warning): bool {
            $warning = $msg;

            return true;
        }, E_USER_WARNING);
        try {
            Requests::get(self::url('/echo'), impersonate: 'chrome', ja3: $ja3);
        } finally {
            restore_error_handler();
        }
        self::assertSame('JA3 fingerprint was altered after impersonated version was set.', $warning);
    }
}

final class MyResponse extends Response
{
}
