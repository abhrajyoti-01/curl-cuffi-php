<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Requests\Cookie;
use CurlCffi\Requests\Cookies;
use CurlCffi\Requests\Exceptions\CookieConflict;
use PHPUnit\Framework\TestCase;

final class CookiesTest extends TestCase
{
    public function testArrayAccessAndDict(): void
    {
        $jar = new Cookies(['a' => '1']);
        $jar['b'] = '2';
        self::assertSame('1', $jar['a']);
        self::assertSame(['a' => '1', 'b' => '2'], $jar->getDict());
        unset($jar['a']);
        self::assertNull($jar->get('a'));
        self::assertCount(1, $jar);
    }

    public function testConflictAcrossDomains(): void
    {
        $jar = new Cookies();
        $jar->set('id', '1', 'a.com');
        $jar->set('id', '2', 'b.com');
        self::assertSame('2', $jar->get('id', domain: 'b.com'));
        $this->expectException(CookieConflict::class);
        $jar->get('id');
    }

    public function testCurlFormatRoundTrip(): void
    {
        $line = "#HttpOnly_.example.com\tTRUE\t/app\tTRUE\t1999999999\tsid\tabc";
        $cookie = Cookie::fromCurlFormat($line);
        self::assertTrue($cookie->httpOnly);
        self::assertTrue($cookie->subdomains);
        self::assertSame('.example.com', $cookie->domain);
        self::assertSame('/app', $cookie->path);
        self::assertSame($line, $cookie->toCurlFormat());
    }

    public function testCurlChangesAndHostBinding(): void
    {
        $jar = new Cookies(['plain' => 'x']);
        $forCurl = $jar->forCurl('Example.com:8080');
        self::assertSame('example.com', $forCurl[0]->domain);
        self::assertSame('', $jar->all()[0]->domain, 'the jar itself is unchanged');

        $jar->updateFromCurlChanges([
            "SET\texample.com\tFALSE\t/\tFALSE\t0\tnew\tv",
            "SET\texample.com\tFALSE\t/\tFALSE\t1\told\tv",   // already expired
        ]);
        self::assertSame('v', $jar->get('new'));
        self::assertNull($jar->get('old'));

        $jar->updateFromCurlChanges(["DELETE\texample.com\tFALSE\t/\tFALSE\t0\tnew\tv"]);
        self::assertNull($jar->get('new'));
    }
}
