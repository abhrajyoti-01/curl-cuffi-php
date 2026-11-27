<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Curl;
use PHPUnit\Framework\TestCase;

final class StatusLineTest extends TestCase
{
    public function testParsesHttp11(): void
    {
        self::assertSame([CurlHttpVersion::V1_1, 404, 'Not Found'], Curl::parseStatusLine("HTTP/1.1 404 Not Found\r\n"));
    }

    public function testParsesHttp2WithoutReason(): void
    {
        self::assertSame([CurlHttpVersion::V2_0, 200, ''], Curl::parseStatusLine("HTTP/2 200 \r\n"));
    }

    public function testInvalidLine(): void
    {
        self::assertSame([CurlHttpVersion::NONE, 0, ''], Curl::parseStatusLine('garbage'));
        self::assertSame('OK', Curl::getReasonPhrase('HTTP/1.0 200 OK'));
    }
}
