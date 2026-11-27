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
}
