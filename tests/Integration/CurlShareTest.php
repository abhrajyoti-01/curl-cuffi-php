<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlLockData;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Const\CurlShareCode;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlShare;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Exceptions\DNSError;
use CurlCffi\Requests\Session;
use PHPUnit\Framework\TestCase;

/**
 * A name only CURLOPT_RESOLVE knows proves the DNS cache is shared: one
 * handle adds it, and a handle on the same share can then reach it too.
 */
final class CurlShareTest extends TestCase
{
    private const HOST = 'curl-cffi-share.invalid';

    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    private static function port(): int
    {
        return (int) parse_url(LocalServer::url('/echo'), PHP_URL_PORT);
    }

    private static function hostUrl(): string
    {
        return 'http://' . self::HOST . ':' . self::port() . '/echo';
    }

    private static function resolve(): array
    {
        return [self::HOST . ':' . self::port() . ':127.0.0.1'];
    }

    /** @return int CURLcode of a GET on hostUrl() */
    private static function fetch(Curl $c): int
    {
        $c->setopt(CurlOpt::URL, self::hostUrl());
        $c->setopt(CurlOpt::WRITEFUNCTION, static fn (string $s): int => strlen($s));
        $c->setopt(CurlOpt::TIMEOUT, 10);
        try {
            $c->perform();

            return 0;
        } catch (CurlError $e) {
            return $e->getCode();
        }
    }

    public function testHandlesOnOneShareShareTheDnsCache(): void
    {
        $share = new CurlShare();
        $a = new Curl();
        $a->setopt(CurlOpt::SHARE, $share);
        $a->setopt(CurlOpt::RESOLVE, self::resolve());
        self::assertSame(0, self::fetch($a));

        $b = new Curl();
        $b->setopt(CurlOpt::SHARE, $share);
        self::assertSame(0, self::fetch($b), 'b found the name in the shared DNS cache');

        $b->reset();
        self::assertSame($share, $b->attachedShare(), 'libcurl keeps the share across reset');
        self::assertSame(0, self::fetch($b));

        $copy = $b->duphandle();
        self::assertSame($share, $copy->attachedShare(), 'duphandle attaches the share again');
        self::assertSame(0, self::fetch($copy));

        self::assertSame(CurlECode::COULDNT_RESOLVE_HOST, self::fetch(new Curl()), 'a handle without the share cannot');

        $noDns = new CurlShare(dns: false);
        $c = new Curl();
        $c->setopt(CurlOpt::SHARE, $noDns);
        self::assertSame(CurlECode::COULDNT_RESOLVE_HOST, self::fetch($c));
    }

    public function testSessionsShareThroughCurlShare(): void
    {
        $share = new CurlShare(ssl_session: false);
        $a = new Session(curl_share: $share, curl_options: [CurlOpt::RESOLVE => self::resolve()]);
        self::assertSame(200, $a->get(self::hostUrl())->statusCode);

        $b = new Session(curl_share: $share);
        self::assertSame(200, $b->get(self::hostUrl())->statusCode);
        self::assertSame(200, $b->get(self::hostUrl())->statusCode, 'and again after the handle was reset');

        $async = new AsyncSession(curlShare: $share);
        $rs = $async->gather([$async->get(self::hostUrl()), $async->get(self::hostUrl())]);
        self::assertSame([200, 200], [$rs[0]->statusCode, $rs[1]->statusCode]);

        $this->expectException(DNSError::class);
        (new Session())->get(self::hostUrl());
    }

    public function testCloseWhileInUse(): void
    {
        $share = new CurlShare(connect: true);
        $share->share(CurlLockData::COOKIE);
        $share->unshare(CurlLockData::COOKIE);
        $c = new Curl();
        $c->setopt(CurlOpt::SHARE, $share);
        try {
            $share->close();
            self::fail('closed while a handle was attached');
        } catch (CurlError $e) {
            self::assertSame(CurlShareCode::IN_USE, $e->getCode());
            self::assertFalse($share->isClosed(), 'kept open so close() can be retried');
        }
        $c->close();
        $share->close();
        self::assertTrue($share->isClosed());
        $share->close(); // twice is fine

        $this->expectException(CurlError::class);
        (new Curl())->setopt(CurlOpt::SHARE, $share);
    }

    public function testDetachAndOptionChecks(): void
    {
        $share = new CurlShare();
        $c = new Curl();
        $c->setopt(CurlOpt::SHARE, $share);
        $c->setopt(CurlOpt::SHARE, null);
        self::assertNull($c->attachedShare());
        $share->close();   // nothing attached any more

        try {
            new CurlShare(bogus: true);
            self::fail('unknown option accepted');
        } catch (\TypeError) {
        }
        $this->expectException(\TypeError::class);
        new Session(curl_share: 'not a share');
    }
}
