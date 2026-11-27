<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use PHPUnit\Framework\TestCase;

final class ConstantsTest extends TestCase
{
    public function testStandardOptionsMatchLibcurl(): void
    {
        self::assertSame(10002, CurlOpt::URL);
        self::assertSame(20011, CurlOpt::WRITEFUNCTION);
        self::assertSame(52, CurlOpt::FOLLOWLOCATION);
        self::assertSame(10023, CurlOpt::HTTPHEADER);
        self::assertSame(CurlOpt::WRITEDATA, CurlOpt::FILE);
    }

    public function testImpersonateOptionsMatchCurlCffi(): void
    {
        self::assertSame(11006, CurlOpt::HTTP2_SETTINGS);
        self::assertSame(11005, CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER);
        self::assertSame(1011, CurlOpt::TLS_GREASE);
        self::assertSame(11012, CurlOpt::TLS_EXTENSION_ORDER);
    }

    public function testInfoAndErrorCodes(): void
    {
        self::assertSame(0x200000 + 2, CurlInfo::RESPONSE_CODE);
        self::assertSame(0x100000 + 1, CurlInfo::EFFECTIVE_URL);
        self::assertSame(28, CurlECode::OPERATION_TIMEDOUT);
        self::assertSame(3, CurlHttpVersion::V2_0);
    }

    public function testNameOf(): void
    {
        self::assertSame('URL', CurlOpt::nameOf(10002));
        self::assertNull(CurlOpt::nameOf(-1));
        self::assertGreaterThan(300, count(CurlOpt::all()));
    }
}
