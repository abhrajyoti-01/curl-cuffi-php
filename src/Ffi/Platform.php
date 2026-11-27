<?php

declare(strict_types=1);

namespace CurlCffi\Ffi;

/**
 * Describes the current OS and CPU in the terms curl-impersonate release
 * assets use, e.g. "x86_64-linux-gnu", "aarch64-macos", "x86_64-win32".
 */
final class Platform
{
    public static function os(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Windows' => 'win32',
            'Linux' => self::isMusl() ? 'linux-musl' : 'linux-gnu',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    public static function arch(): string
    {
        $machine = strtolower(php_uname('m'));

        return match ($machine) {
            'x86_64', 'amd64', 'x64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            'i386', 'i686', 'x86' => 'i686',
            'armv7l', 'armv7' => 'arm',
            default => $machine,
        };
    }

    /** e.g. "x86_64-linux-gnu". Used as the folder name under lib/. */
    public static function id(): string
    {
        return self::arch() . '-' . self::os();
    }

    /** @return list<string> file names to try, most specific first */
    public static function libraryFileNames(): array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['libcurl-impersonate.4.dylib', 'libcurl-impersonate.dylib'],
            'Windows' => ['libcurl-impersonate.dll', 'libcurl.dll'],
            default => ['libcurl-impersonate.so.4', 'libcurl-impersonate.so'],
        };
    }

    private static function isMusl(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        return glob('/lib/ld-musl-*') !== [] && glob('/lib/ld-musl-*') !== false;
    }
}
