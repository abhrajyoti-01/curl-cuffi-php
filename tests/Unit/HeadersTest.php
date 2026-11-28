<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Requests\Headers;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testCaseInsensitiveAndMultiValue(): void
    {
        $h = new Headers(['Accept' => 'text/html', 'X-Many' => ['a', 'b']]);
        self::assertSame('text/html', $h['accept']);
        self::assertSame('a, b', $h->get('x-many'));
        self::assertSame(['a', 'b'], $h->getList('X-MANY'));
        self::assertTrue(isset($h['ACCEPT']));
        self::assertNull($h->get('missing'));
        self::assertSame('d', $h->get('missing', 'd'));
    }

    public function testAcceptsLinesAndPairs(): void
    {
        $lines = new Headers(['Content-Type: text/plain', 'X-A: 1']);
        $pairs = new Headers([['Content-Type', 'text/plain'], ['X-A', '1']]);
        self::assertSame($lines->toArray(), $pairs->toArray());
    }

    public function testSetReplacesInPlaceAndUpdateMerges(): void
    {
        $h = new Headers(['A' => '1', 'B' => '2', 'a' => '3']);
        $h->set('a', 'x');
        self::assertSame([['a', 'x'], ['B', '2']], $h->multiItems());

        $h->update(['b' => ['y', 'z'], 'C' => null]);
        self::assertSame(['y', 'z'], $h->getList('B'));
        self::assertTrue($h->has('c'));
        self::assertNull($h->get('c'));
        unset($h['c']);
        self::assertFalse($h->has('C'));
    }

    public function testFromLinesAndSplitCommas(): void
    {
        $h = Headers::fromLines(["Vary: Accept, Cookie\r\n", "Set-Cookie: a=1\r\n", "Set-Cookie: b=2\r\n"]);
        self::assertSame(['Accept', 'Cookie'], $h->getList('vary', true));
        self::assertSame(['a=1', 'b=2'], $h->getList('set-cookie'));
        self::assertCount(2, $h);
    }

    public function testPythonViews(): void
    {
        $h = new Headers([['Accept', 'a'], ['X-Many', '1'], ['x-many', '2']]);
        self::assertSame(['Accept' => 'a', 'X-Many' => '1, 2'], $h->items());
        self::assertSame(['a', '1, 2'], $h->values());
        self::assertSame([['Accept', 'a'], ['X-Many', '1'], ['x-many', '2']], $h->raw());

        self::assertSame('ascii', $h->encoding());
        self::assertSame('utf-8', (new Headers(['X' => "caf\u{e9}"]))->encoding());
        self::assertSame('iso-8859-1', (new Headers(['X' => "caf\xe9"]))->encoding());
        $h->setEncoding('utf-8');
        self::assertSame('utf-8', $h->encoding());
    }
}
