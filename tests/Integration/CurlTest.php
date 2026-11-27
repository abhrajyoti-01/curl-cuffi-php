<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the FFI binding against a local server. Runs against any libcurl
 * (set CURL_CFFI_LIBRARY=libcurl.so.4 to use the system one); impersonation
 * tests skip unless libcurl-impersonate is loaded.
 */
final class CurlTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    /** @return array{0: Curl, 1: \Closure(): string} */
    private function curlWithBuffer(string $path): array
    {
        $c = new Curl();
        $body = '';
        $c->setopt(CurlOpt::URL, LocalServer::url($path));
        $c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return [$c, function () use (&$body): string {
            return $body;
        }];
    }

    public function testVersion(): void
    {
        self::assertStringStartsWith('libcurl/', Library::version());
    }

    public function testGetWithHeadersAndInfo(): void
    {
        [$c, $body] = $this->curlWithBuffer('/echo?a=1');
        $c->setopt(CurlOpt::HTTPHEADER, ['X-Foo: bar', 'X-Baz: qux']);
        $c->perform();

        $json = json_decode($body(), true);
        self::assertSame('GET', $json['method']);
        self::assertSame(['a' => '1'], $json['query']);
        self::assertSame('bar', $json['headers']['X-Foo']);
        self::assertSame('qux', $json['headers']['X-Baz']);
        self::assertSame(200, $c->getinfo(CurlInfo::RESPONSE_CODE));
        self::assertStringEndsWith('/echo?a=1', $c->getinfo(CurlInfo::EFFECTIVE_URL));
        self::assertIsFloat($c->getinfo(CurlInfo::TOTAL_TIME));
    }

    public function testPostFieldsWithBinaryBody(): void
    {
        [$c, $body] = $this->curlWithBuffer('/echo');
        $payload = "a=1&b=\x00\xff&c=" . str_repeat('x', 100_000);
        $c->setopt(CurlOpt::POSTFIELDS, $payload);
        $c->perform();

        $json = json_decode($body(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertSame('POST', $json['method']);
        self::assertSame(strlen($payload), (int) $json['headers']['Content-Length']);
    }

    public function testCustomRequestAndHeaderCallback(): void
    {
        [$c, $body] = $this->curlWithBuffer('/echo');
        $headers = [];
        $c->setopt(CurlOpt::CUSTOMREQUEST, 'PATCH');
        $c->setopt(CurlOpt::HEADERFUNCTION, function (string $line) use (&$headers): void {
            $headers[] = trim($line);
        });
        $c->perform();

        self::assertSame('PATCH', json_decode($body(), true)['method']);
        self::assertStringStartsWith('HTTP/1.1 200', $headers[0]);
        self::assertContains('X-Test: yes', $headers);
    }

    public function testWriteDataStreamAndRedirects(): void
    {
        $c = new Curl();
        $stream = fopen('php://memory', 'w+');
        $c->setopt(CurlOpt::URL, LocalServer::url('/redirect'));
        $c->setopt(CurlOpt::FOLLOWLOCATION, true);
        $c->setopt(CurlOpt::WRITEDATA, $stream);
        $c->perform();
        rewind($stream);

        $json = json_decode((string) stream_get_contents($stream), true);
        self::assertSame(['redirected' => '1'], $json['query']);
        self::assertSame(1, $c->getinfo(CurlInfo::REDIRECT_COUNT));
    }

    public function testReadFunctionUpload(): void
    {
        [$c, $body] = $this->curlWithBuffer('/echo');
        $data = 'uploaded body';
        $c->setopt(CurlOpt::UPLOAD, 1);
        $c->setopt(CurlOpt::INFILESIZE_LARGE, strlen($data));
        $c->setopt(CurlOpt::READFUNCTION, function (int $max) use (&$data): string {
            $chunk = substr($data, 0, $max);
            $data = (string) substr($data, strlen($chunk));

            return $chunk;
        });
        $c->perform();

        $json = json_decode($body(), true);
        self::assertSame('PUT', $json['method']);
        self::assertSame('uploaded body', $json['body']);
    }

    public function testConnectionErrorHasCurlCode(): void
    {
        $c = new Curl();
        $c->setopt(CurlOpt::URL, 'http://127.0.0.1:1/');
        try {
            $c->perform();
            self::fail('Expected CurlError');
        } catch (CurlError $e) {
            self::assertSame(CurlECode::COULDNT_CONNECT, $e->getCode());
            self::assertStringContainsString('curl: (7)', $e->getMessage());
        }
    }

    public function testStrerror(): void
    {
        $c = new Curl();
        self::assertSame("Couldn't connect to server", $c->strerror(CurlECode::COULDNT_CONNECT));
        self::assertSame('No error', $c->strerror(CurlECode::OK));
    }

    public function testExceptionInCallbackIsRethrown(): void
    {
        [$c] = $this->curlWithBuffer('/echo');
        $c->setopt(CurlOpt::WRITEFUNCTION, function (): void {
            throw new \LogicException('stop here');
        });
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stop here');
        $c->perform();
    }

    public function testHeadersAreClearedAfterPerform(): void
    {
        [$c, $body] = $this->curlWithBuffer('/echo');
        $c->setopt(CurlOpt::HTTPHEADER, ['X-Once: 1']);
        $c->perform();
        self::assertArrayHasKey('X-Once', json_decode($body(), true)['headers']);

        $collected = '';
        $c->setopt(CurlOpt::WRITEFUNCTION, function (string $s) use (&$collected): void {
            $collected .= $s;
        });
        $c->perform();
        self::assertArrayNotHasKey('X-Once', json_decode($collected, true)['headers']);
    }

    public function testResetAndClose(): void
    {
        [$c] = $this->curlWithBuffer('/echo');
        $c->setopt(CurlOpt::TIMEOUT_MS, 5000);
        $c->reset();
        $c->close();
        $c->close();
        self::assertSame(0, $c->getinfo(CurlInfo::RESPONSE_CODE));
        $this->expectException(CurlError::class);
        $c->perform();
    }

    public function testImpersonateSetsBrowserHeaders(): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('Loaded libcurl has no impersonation support');
        }
        [$c, $body] = $this->curlWithBuffer('/echo');
        $c->impersonate('chrome');
        $c->perform();

        $headers = json_decode($body(), true)['headers'];
        self::assertStringContainsString('Chrome/', $headers['User-Agent']);
        self::assertArrayHasKey('sec-ch-ua', array_change_key_case($headers, CASE_LOWER));
    }

    public function testImpersonateUnknownTargetFails(): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('Loaded libcurl has no impersonation support');
        }
        $this->expectException(CurlError::class);
        (new Curl())->impersonate('netscape4');
    }
}
