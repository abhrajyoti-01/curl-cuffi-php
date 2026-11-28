<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;

/**
 * Named fingerprints, like curl_cffi.FingerprintManager: the targets built
 * into libcurl-impersonate plus any downloaded from the fingerprint service
 * (impersonate.pro) into the same files Python uses, so both share them.
 *
 *     FingerprintManager::setApiKey('imp_...');       // or IMPERSONATE_API_KEY
 *     FingerprintManager::updateFingerprints();
 *     Requests::get($url, impersonate: 'some_downloaded_target');
 *
 * Files: $IMPERSONATE_CONFIG_DIR, else ~/.config/impersonate
 * (%APPDATA%\impersonate on Windows): config.json and fingerprints.json.
 */
final class FingerprintManager
{
    public const DEFAULT_API_ROOT = 'https://api.impersonate.pro/v1';
    public const PAGE_LIMIT = 100;

    /** @var array<string, Fingerprint>|null */
    private static ?array $cache = null;

    public static function getConfigDir(): string
    {
        $dir = getenv('IMPERSONATE_CONFIG_DIR');
        if (is_string($dir) && $dir !== '') {
            return $dir;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['APPDATA' => '', 'USERPROFILE' => '/AppData/Roaming'] as $var => $suffix) {
                $base = getenv($var);
                if (is_string($base) && $base !== '') {
                    return $base . $suffix . '/impersonate';
                }
            }

            return self::home() . '/AppData/Roaming/impersonate';
        }
        $xdg = getenv('XDG_CONFIG_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return $xdg . '/impersonate';
        }

        return self::home() . '/.config/impersonate';
    }

    public static function getConfigPath(): string
    {
        return self::getConfigDir() . '/config.json';
    }

    public static function getFingerprintPath(): string
    {
        return self::getConfigDir() . '/fingerprints.json';
    }

    public static function getApiRoot(): string
    {
        $root = getenv('IMPERSONATE_API_ROOT');

        return is_string($root) && $root !== '' ? $root : self::DEFAULT_API_ROOT;
    }

    public static function getApiKey(): ?string
    {
        $key = getenv('IMPERSONATE_API_KEY');
        if (is_string($key) && $key !== '') {
            return $key;
        }
        $config = self::readJson(self::getConfigPath());
        $key = $config['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Save the API key for the fingerprint service in config.json. */
    public static function setApiKey(string $apiKey): void
    {
        $config = self::readJson(self::getConfigPath()) ?? [];
        $config['api_key'] = $apiKey;
        $config['update_time'] = gmdate('Y-m-d\TH:i:s');
        self::writeJson(self::getConfigPath(), $config);
    }

    /**
     * Download the latest fingerprints into fingerprints.json.
     *
     * @return int how many were saved
     */
    public static function updateFingerprints(?string $apiRoot = null): int
    {
        $baseUrl = rtrim($apiRoot ?? self::getApiRoot(), '/') . '/fingerprints';
        $headers = [];
        $apiKey = self::getApiKey();
        if ($apiKey !== null) {
            $headers[] = "Authorization: Bearer {$apiKey}";
        }

        $fingerprints = [];
        $skip = 0;
        while (true) {
            $url = $baseUrl . '?' . http_build_query(['skip' => $skip, 'limit' => self::PAGE_LIMIT]);
            $data = json_decode(self::fetch($url, $headers), true);
            if (!is_array($data) || array_is_list($data) && $data !== []) {
                throw new FingerprintUpdateError("Invalid fingerprint response from {$url}: expected object");
            }
            $items = $data['items'] ?? $data['data'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new FingerprintUpdateError("No fingerprints found at {$url}");
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = ($item['name'] ?? null) ?: ($item['target'] ?? null);
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $raw = $item['data'] ?? $item['fingerprint'] ?? [];
                if (is_string($raw)) {
                    $raw = json_decode($raw, true);
                }
                if (is_array($raw)) {
                    $fingerprints[$name] = $raw;
                }
            }
            $pagination = $data['pagination'] ?? null;
            if (!is_array($pagination) || empty($pagination['has_more'])) {
                break;
            }
            $next = $pagination['next_skip'] ?? null;
            if (!is_int($next) || $next <= $skip) {
                throw new FingerprintUpdateError("Invalid fingerprint pagination from {$url}: expected increasing next_skip");
            }
            $skip = $next;
        }

        self::writeJson(self::getFingerprintPath(), $fingerprints);
        self::$cache = null;

        return count($fingerprints);
    }

    /** @return array<string, Fingerprint> built-in targets plus downloaded ones */
    public static function loadFingerprints(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $parsed = [];
        foreach (NativeTargets::TARGETS as $t) {
            $parsed[$t['target_name']] = new Fingerprint(
                client: strtolower($t['browser']),
                client_version: $t['version'],
                os: $t['os'],
                os_version: $t['os_version'],
            );
        }
        $downloaded = self::readJson(self::getFingerprintPath());
        foreach ($downloaded ?? [] as $name => $value) {
            if (is_array($value)) {
                $parsed[(string) $name] = Fingerprint::fromArray($value);
            }
        }

        return self::$cache = $parsed;
    }

    /** A copy of the named fingerprint, safe to change. */
    public static function getFingerprint(string $target): Fingerprint
    {
        $all = self::loadFingerprints();
        if (!isset($all[$target])) {
            throw new \OutOfBoundsException("Fingerprint target not found: {$target}");
        }

        return clone $all[$target];
    }

    /** @return list<array{type: string, name: string, browser: string, version: string, os: string, os_version: string, h3_fingerprints: bool}> */
    public static function listFingerprints(): array
    {
        $native = array_column(NativeTargets::TARGETS, null, 'target_name');
        $all = self::loadFingerprints();
        ksort($all, SORT_STRING);
        $rows = [];
        foreach ($all as $name => $fp) {
            $name = (string) $name;
            if (isset($native[$name])) {
                $n = $native[$name];
                $rows[] = [
                    'type' => 'builtin', 'name' => $name, 'browser' => $n['browser'], 'version' => $n['version'],
                    'os' => $n['os'], 'os_version' => $n['os_version'], 'h3_fingerprints' => $n['h3_fingerprints'],
                ];
            } else {
                $rows[] = [
                    'type' => 'custom', 'name' => $name, 'browser' => $fp->client, 'version' => $fp->clientVersion,
                    'os' => $fp->os, 'os_version' => $fp->osVersion,
                    'h3_fingerprints' => in_array($fp->httpVersion, ['v3', 'v3only'], true),
                ];
            }
        }

        return $rows;
    }

    /** Forget loaded fingerprints, e.g. after fingerprints.json changed. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @param list<string> $headers */
    private static function fetch(string $url, array $headers): string
    {
        $curl = new Curl();
        $body = '';
        try {
            $curl->setopt(CurlOpt::URL, $url);
            if ($headers) {
                $curl->setopt(CurlOpt::HTTPHEADER, $headers);
            }
            $curl->setopt(CurlOpt::WRITEFUNCTION, static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            });
            $curl->perform();
            $status = (int) $curl->getinfo(CurlInfo::RESPONSE_CODE);
        } catch (CurlError $e) {
            throw new FingerprintUpdateError("Failed to access fingerprint endpoint at {$url}: {$e->getMessage()}", $e->getCode(), $e);
        } finally {
            $curl->close();
        }
        if ($status >= 400) {
            throw new FingerprintUpdateError("Failed to access fingerprint endpoint at {$url}: HTTP {$status}: {$body}");
        }

        return $body;
    }

    /** @return array<mixed>|null */
    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $json = json_encode($data === [] ? new \stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json);
    }

    private static function home(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $var) {
            $home = getenv($var);
            if (is_string($home) && $home !== '') {
                return $home;
            }
        }

        return sys_get_temp_dir();
    }
}
