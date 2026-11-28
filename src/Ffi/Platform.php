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

    /**
     * Other spellings of a CPU name that release assets may use, canonical first:
     * curl-impersonate names Apple Silicon builds "arm64" but Linux ones "aarch64".
     *
     * @return list<string>
     */
    public static function archAliases(string $arch): array
    {
        $arch = strtolower($arch);
        foreach ([['x86_64', 'amd64', 'x64'], ['aarch64', 'arm64'], ['i686', 'i386', 'x86'], ['arm', 'armv7', 'armv7l']] as $group) {
            if (in_array($arch, $group, true)) {
                return $group;
            }
        }

        return [$arch];
    }

    /** @return list<string> other spellings of an OS name, canonical first */
    public static function osAliases(string $os): array
    {
        $os = strtolower($os);
        foreach ([['macos', 'darwin', 'apple-darwin'], ['win32', 'windows', 'win64'], ['linux-gnu', 'linux'], ['linux-musl', 'musl']] as $group) {
            if (in_array($os, $group, true)) {
                return $group;
            }
        }

        return [$os];
    }

    /** "arm64-macos" -> "aarch64-macos": the folder name Library looks in. */
    public static function normalize(string $id): string
    {
        [$arch, $os] = array_pad(explode('-', strtolower($id), 2), 2, '');

        return self::archAliases($arch)[0] . '-' . self::osAliases($os)[0];
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
