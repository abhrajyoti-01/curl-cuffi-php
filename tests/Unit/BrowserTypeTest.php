<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Impersonate\BrowserType;
use PHPUnit\Framework\TestCase;

final class BrowserTypeTest extends TestCase
{
    public function testAliasesResolveToNewestVersion(): void
    {
        self::assertSame(BrowserType::DEFAULT_CHROME, BrowserType::resolve('chrome'));
        self::assertSame(BrowserType::DEFAULT_FIREFOX, BrowserType::resolve('firefox'));
        self::assertSame(BrowserType::DEFAULT_SAFARI_IOS, BrowserType::resolve('safari_ios'));
    }

    public function testExplicitTargetsPassThrough(): void
    {
        self::assertSame('chrome124', BrowserType::resolve('chrome124'));
        self::assertSame('chrome131', BrowserType::resolve(BrowserType::chrome131));
        self::assertSame('some_future_target', BrowserType::resolve('some_future_target'));
    }

    public function testDefaultsAreRealCases(): void
    {
        foreach (BrowserType::REAL_TARGET_MAP as $target) {
            self::assertNotNull(BrowserType::tryFrom($target), $target);
        }
    }
}
