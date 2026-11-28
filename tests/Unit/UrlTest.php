<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Requests\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testUpdateParamsLikePython(): void
    {
        self::assertSame(
            'http://x.com/test?answers=false&data=some&data=values',
            Url::updateParams('http://x.com/test?answers=true', ['answers' => false, 'data' => ['some', 'values']])
        );
        // one existing + one new value of a key: replaced; otherwise appended
        self::assertSame('http://x.com/?a=2#frag', Url::updateParams('http://x.com/?a=1#frag', [['a', '2']]));
        self::assertSame('http://x.com/?a=1&a=2&a=3', Url::updateParams('http://x.com/?a=1', [['a', '2'], ['a', '3']]));
        self::assertSame('http://x.com/?q=a+b', Url::updateParams('http://x.com/', ['q' => 'a b']));
    }

    public function testEncodeForm(): void
    {
        self::assertSame('a=1&b=x+y&c=1&c=2', Url::encodeForm(['a' => 1, 'b' => 'x y', 'c' => [1, 2]]));
    }

    public function testJoin(): void
    {
        self::assertSame('https://a.com/v1/users', Url::join('https://a.com/v1/', 'users'));
        self::assertSame('https://a.com/users', Url::join('https://a.com/v1/', '/users'));
        self::assertSame('https://a.com/users', Url::join('https://a.com/v1/x', '../users'));
        self::assertSame('https://b.com/', Url::join('https://a.com/v1/', 'https://b.com/'));
        self::assertSame('https://a.com/v1/?q=1', Url::join('https://a.com/v1/', '?q=1'));
    }

    public function testRequote(): void
    {
        self::assertSame('http://x.com/a%20b?c=~~', Url::requote('http://x.com/a b?c=%7E~'));
        self::assertSame('http://x.com/%E4%BD%A0', Url::requote('http://x.com/你'));
        self::assertSame('http://x.com/~a', Url::requote('http://x.com/%7Ea'));
        self::assertSame('http://x.com/100%', Url::requote('http://x.com/100%'));
        // an invalid escape makes the whole URL quoted, % included
        self::assertSame('http://x.com/%25zz', Url::requote('http://x.com/%zz'));
    }
}
