<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

use CurlCffi\Ffi\Library;
use CurlCffi\Impersonate\FingerprintManager;

/**
 * `curl-cffi doctor`: diagnostic information for bug reports, like
 * curl_cffi.cli.doctor. The python lines become php ones, and the loaded
 * library's path, impersonation support and ffi.enable are added, since
 * those are what usually goes wrong with FFI.
 *
 * @internal
 */
final class Doctor
{
    public static function handle(Console $io): int
    {
        $configPath = FingerprintManager::getConfigPath();
        $fingerprintPath = FingerprintManager::getFingerprintPath();

        $io->out('curl-cffi doctor');
        $io->out('----------------');
        $io->out('php: ' . PHP_VERSION);
        $io->out('executable: ' . PHP_BINARY);
        $io->out('platform: ' . php_uname('s') . '-' . php_uname('r') . '-' . php_uname('m'));
        $io->out('machine: ' . php_uname('m'));
        $io->out('curl_cffi: ' . self::packageVersion());
        $io->out('ffi_enable: ' . (extension_loaded('ffi') ? (string) ini_get('ffi.enable') : 'extension not loaded'));
        try {
            $io->out('libcurl: ' . Library::version());
            $io->out('library: ' . (Library::loadedPath() ?? ''));
            $io->out('impersonation: ' . self::bool(Library::supportsImpersonation()));
        } catch (\Throwable $e) {
            $io->out('libcurl_error: ' . str_replace("\n", ' ', $e->getMessage()));
        }
        $io->out('api_root: ' . FingerprintManager::getApiRoot());
        $io->out("config_path: {$configPath}");
        $io->out('config_present: ' . self::bool(file_exists($configPath)));
        $io->out('api_key_configured: ' . self::bool(FingerprintManager::getApiKey() !== null));
        $io->out("fingerprint_path: {$fingerprintPath}");
        $io->out('fingerprint_present: ' . self::bool(file_exists($fingerprintPath)));
        try {
            FingerprintManager::clearCache();
            $io->out('fingerprint_count: ' . count(FingerprintManager::loadFingerprints()));
        } catch (\Throwable $e) {
            $io->out('fingerprint_count_error: ' . (new \ReflectionClass($e))->getShortName());
        }

        return 0;
    }

    /** This package's version from Composer, e.g. "v0.1.0" or "dev-main". */
    public static function packageVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('abhrajyoti-01/curl-cffi-php');
                if ($version !== null) {
                    return $version;
                }
            } catch (\OutOfBoundsException) {
                // not installed through Composer
            }
        }

        return 'unknown';
    }

    /** Python prints booleans as True and False. */
    private static function bool(bool $value): string
    {
        return $value ? 'True' : 'False';
    }
}
