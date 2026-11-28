<?php

declare(strict_types=1);

namespace CurlCffi\Ffi;

use FFI;

/**
 * Finds and loads the native libcurl-impersonate library once per process.
 *
 * Search order for the library:
 *  1. Library::setPath() or the CURL_CFFI_LIBRARY environment variable
 *  2. the package's own lib/ folder, filled by `vendor/bin/curl-cffi-install`
 *  3. the system library search path (libcurl-impersonate.so.4 and friends)
 */
final class Library
{
    private static ?FFI $ffi = null;
    private static ?FFI $impersonateFfi = null;
    private static bool $impersonateChecked = false;
    private static ?FFI $wsFfi = null;
    private static bool $wsChecked = false;
    private static ?string $path = null;
    private static ?string $loadedPath = null;
    private static ?string $caBundle = null;

    /** Root of the installed package. */
    public static function packageDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Folder the installer writes binaries to. */
    public static function libDir(): string
    {
        return self::packageDir() . DIRECTORY_SEPARATOR . 'lib';
    }

    /** Use this library file instead of searching. Call before the first request. */
    public static function setPath(string $path): void
    {
        if (self::$ffi !== null && self::$loadedPath !== $path) {
            throw new LibraryException('The native library is already loaded from ' . self::$loadedPath);
        }
        self::$path = $path;
    }

    /** Use this CA bundle (PEM file) by default for TLS verification. */
    public static function setCaBundle(?string $path): void
    {
        self::$caBundle = $path;
    }

    public static function ffi(): FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (!extension_loaded('ffi')) {
            throw new LibraryException(
                'The PHP FFI extension is not loaded. Install/enable ext-ffi and set ffi.enable=true '
                . '(or "preload" for CLI only) in php.ini.'
            );
        }

        $header = (string) file_get_contents(__DIR__ . '/curl.h');
        if (PHP_OS_FAMILY === 'Windows') {
            // curl_socket_t is SOCKET (UINT_PTR) on Windows
            $header = str_replace('typedef int curl_socket_t;', 'typedef uint64_t curl_socket_t;', $header);
        }
        $errors = [];
        foreach (self::candidates() as $candidate) {
            try {
                self::allowSiblingDlls($candidate);
                self::$ffi = FFI::cdef($header, $candidate);
                self::$loadedPath = $candidate;
                // CURL_GLOBAL_DEFAULT = SSL | WIN32 = 3
                self::$ffi->curl_global_init(3);

                return self::$ffi;
            } catch (FFI\Exception $e) {
                $errors[] = "$candidate: " . $e->getMessage();
            }
        }

        throw new LibraryException(
            "Could not load libcurl-impersonate. Run `vendor/bin/curl-cffi-install` to download it, "
            . "or set CURL_CFFI_LIBRARY to the library path.\nTried:\n  " . implode("\n  ", $errors)
        );
    }

    /**
     * FFI instance for impersonation symbols, or null when the loaded library
     * is a stock libcurl without impersonation support.
     */
    public static function impersonateFfi(): ?FFI
    {
        if (self::$impersonateChecked) {
            return self::$impersonateFfi;
        }
        self::ffi();
        self::$impersonateChecked = true;
        try {
            self::$impersonateFfi = FFI::cdef(
                (string) file_get_contents(__DIR__ . '/impersonate.h'),
                (string) self::$loadedPath
            );
        } catch (FFI\Exception) {
            self::$impersonateFfi = null;
        }

        return self::$impersonateFfi;
    }

    /**
     * FFI instance for the WebSocket API (curl_ws_*), or null when the loaded
     * libcurl is too old to have it.
     */
    public static function wsFfi(): ?FFI
    {
        if (self::$wsChecked) {
            return self::$wsFfi;
        }
        self::ffi();
        self::$wsChecked = true;
        try {
            self::$wsFfi = FFI::cdef((string) file_get_contents(__DIR__ . '/ws.h'), (string) self::$loadedPath);
        } catch (FFI\Exception) {
            self::$wsFfi = null;
        }

        return self::$wsFfi;
    }

    /** Whether the loaded libcurl speaks ws:// and wss:// (libcurl-impersonate does). */
    public static function supportsWebSocket(): bool
    {
        if (self::wsFfi() === null) {
            return false;
        }
        return in_array('ws', self::protocols(), true);
    }

    /** @return list<string> protocols the loaded libcurl supports, e.g. ["http", "https", "ws", "wss"] */
    public static function protocols(): array
    {
        $info = self::ffi()->curl_version_info(0);
        $out = [];
        for ($i = 0; $i < 256; $i++) {
            $p = $info->protocols[$i];
            if ($p === null || ($p instanceof FFI\CData && FFI::isNull($p))) {
                break;
            }
            $out[] = is_string($p) ? $p : FFI::string($p);
        }

        return $out;
    }

    public static function supportsImpersonation(): bool
    {
        return self::impersonateFfi() !== null;
    }

    public static function loadedPath(): ?string
    {
        return self::$loadedPath;
    }

    /** libcurl version string, e.g. "libcurl/8.x BoringSSL zlib brotli nghttp2". */
    public static function version(): string
    {
        return FFI::string(self::ffi()->curl_version());
    }

    /**
     * Default CA bundle: setCaBundle(), then SSL_CERT_FILE / CURL_CA_BUNDLE,
     * then the bundle the installer downloaded, then the system bundle.
     * libcurl-impersonate is built with BoringSSL, which has no built-in
     * system store, so a file path is always passed, as Python curl_cffi does
     * with certifi.
     */
    public static function caBundle(): ?string
    {
        if (self::$caBundle !== null) {
            return self::$caBundle;
        }
        $candidates = [];
        foreach (['SSL_CERT_FILE', 'CURL_CA_BUNDLE'] as $env) {
            $value = getenv($env);
            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }
        $candidates[] = self::libDir() . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (function_exists('openssl_get_cert_locations')) {
            $locations = openssl_get_cert_locations();
            $candidates[] = (string) ($locations['default_cert_file'] ?? '');
        }
        array_push(
            $candidates,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
            '/usr/local/etc/openssl/cert.pem',
            '/opt/homebrew/etc/openssl@3/cert.pem',
        );
        foreach ($candidates as $file) {
            if ($file !== '' && is_file($file)) {
                return self::$caBundle = $file;
            }
        }

        return null;
    }

    /**
     * Windows looks for a DLL's own dependencies next to php.exe and on PATH,
     * not next to the DLL. Add the library's folder to the search path so DLLs
     * shipped beside libcurl-impersonate.dll are found.
     */
    private static function allowSiblingDlls(string $file): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !preg_match('#[\\\\/]#', $file)) {
            return;
        }
        try {
            FFI::cdef('int SetDllDirectoryA(const char *lpPathName);', 'kernel32.dll')
                ->SetDllDirectoryA(dirname($file));
        } catch (\Throwable) {
            // Not fatal: a self-contained DLL loads without it.
        }
    }

    /** @return list<string> */
    private static function candidates(): array
    {
        $explicit = self::$path ?? (getenv('CURL_CFFI_LIBRARY') ?: null);
        if ($explicit !== null) {
            return [$explicit];
        }

        $found = [];
        $platformDir = self::libDir() . DIRECTORY_SEPARATOR . Platform::id();
        foreach ([$platformDir, self::libDir()] as $dir) {
            foreach (Platform::libraryFileNames() as $name) {
                $file = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($file)) {
                    $found[] = $file;
                }
            }
        }

        // Fall back to the dynamic linker's search path.
        return array_values(array_unique([...$found, ...Platform::libraryFileNames()]));
    }

    /** Forget the loaded library. Only meant for tests. */
    public static function reset(): void
    {
        self::$ffi = null;
        self::$impersonateFfi = null;
        self::$impersonateChecked = false;
        self::$wsFfi = null;
        self::$wsChecked = false;
        self::$loadedPath = null;
        self::$path = null;
        self::$caBundle = null;
    }
}
