<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Ffi\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function testIdIsArchDashOs(): void
    {
        $id = Platform::id();
        self::assertMatchesRegularExpression('/^[a-z0-9_]+-[a-z0-9-]+$/', $id);
        self::assertSame($id, Platform::normalize($id), 'the id is already canonical');
        self::assertContains(Platform::os(), ['linux-gnu', 'linux-musl', 'macos', 'win32', strtolower(PHP_OS_FAMILY)]);
    }

    public function testAliasesStartWithTheCanonicalName(): void
    {
        self::assertSame(['aarch64', 'arm64'], Platform::archAliases('arm64'));
        self::assertSame(['x86_64', 'amd64', 'x64'], Platform::archAliases('AMD64'));
        self::assertSame(['riscv64'], Platform::archAliases('riscv64'));
        self::assertSame(['macos', 'darwin', 'apple-darwin'], Platform::osAliases('darwin'));
        self::assertSame(['win32', 'windows', 'win64'], Platform::osAliases('win32'));
    }

    public function testNormalize(): void
    {
        self::assertSame('aarch64-macos', Platform::normalize('arm64-macos'));
        self::assertSame('x86_64-win32', Platform::normalize('amd64-windows'));
        self::assertSame('aarch64-linux-gnu', Platform::normalize('aarch64-linux-gnu'));
        self::assertSame('x86_64-linux-musl', Platform::normalize('x86_64-linux-musl'));
    }

    public function testLibraryFileNamesMatchTheOs(): void
    {
        $names = Platform::libraryFileNames();
        $ext = match (PHP_OS_FAMILY) {
            'Darwin' => '.dylib',
            'Windows' => '.dll',
            default => '.so',
        };
        foreach ($names as $name) {
            self::assertStringContainsString($ext, $name);
        }
    }
}
