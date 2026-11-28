<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Impersonate\Fingerprint;
use CurlCffi\Impersonate\FingerprintManager;
use CurlCffi\Impersonate\FingerprintUpdateError;
use PHPUnit\Framework\TestCase;

final class FingerprintManagerTest extends TestCase
{
    private string $dir;
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
        $this->dir = sys_get_temp_dir() . '/curl-cffi-fp-' . bin2hex(random_bytes(4));
        foreach (['IMPERSONATE_CONFIG_DIR', 'IMPERSONATE_API_KEY', 'IMPERSONATE_API_ROOT'] as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
        putenv("IMPERSONATE_CONFIG_DIR={$this->dir}");
        FingerprintManager::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
        FingerprintManager::clearCache();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testPathsAndApiKey(): void
    {
        self::assertSame($this->dir, FingerprintManager::getConfigDir());
        self::assertSame($this->dir . '/config.json', FingerprintManager::getConfigPath());
        self::assertSame($this->dir . '/fingerprints.json', FingerprintManager::getFingerprintPath());
        self::assertSame('https://api.impersonate.pro/v1', FingerprintManager::getApiRoot());

        self::assertNull(FingerprintManager::getApiKey());
        FingerprintManager::setApiKey('imp_saved');
        self::assertSame('imp_saved', FingerprintManager::getApiKey());
        self::assertSame('imp_saved', json_decode((string) file_get_contents($this->dir . '/config.json'), true)['api_key']);
        putenv('IMPERSONATE_API_KEY=imp_env');
        self::assertSame('imp_env', FingerprintManager::getApiKey(), 'the environment wins, as in Python');
    }

    public function testBuiltinTargets(): void
    {
        $all = FingerprintManager::loadFingerprints();
        self::assertArrayHasKey('chrome131', $all);
        self::assertSame('chrome', $all['chrome131']->client);
        self::assertSame('131', $all['chrome131']->clientVersion);

        $copy = FingerprintManager::getFingerprint('chrome131');
        $copy->client = 'changed';
        self::assertSame('chrome', FingerprintManager::getFingerprint('chrome131')->client, 'callers get a copy');

        $row = array_values(array_filter(FingerprintManager::listFingerprints(), fn ($r) => $r['name'] === 'tor145'))[0];
        self::assertSame(['type' => 'builtin', 'name' => 'tor145', 'browser' => 'Tor', 'version' => '14.5',
            'os' => 'macOS', 'os_version' => 'Sonoma', 'h3_fingerprints' => false], $row);

        $this->expectException(\OutOfBoundsException::class);
        FingerprintManager::getFingerprint('netscape4');
    }

    public function testUpdateFingerprintsFromService(): void
    {
        $root = LocalServer::url('/fp');
        try {
            FingerprintManager::updateFingerprints($root);
            self::fail('Expected FingerprintUpdateError without a key');
        } catch (FingerprintUpdateError $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
        }

        putenv('IMPERSONATE_API_KEY=imp_test');
        putenv("IMPERSONATE_API_ROOT={$root}");
        self::assertSame(2, FingerprintManager::updateFingerprints());

        $a = FingerprintManager::getFingerprint('custom_a');
        self::assertInstanceOf(Fingerprint::class, $a);
        self::assertSame('1.3', $a->tlsVersion);
        self::assertSame(['User-Agent' => 'B'], FingerprintManager::getFingerprint('custom_b')->headers);

        $rows = array_column(FingerprintManager::listFingerprints(), null, 'name');
        self::assertSame('custom', $rows['custom_a']['type']);
        self::assertTrue($rows['custom_a']['h3_fingerprints']);
        self::assertSame('firefox', $rows['custom_b']['browser']);
        self::assertArrayHasKey('chrome131', $rows);

        // Same file format as Python, so either can read what the other saved.
        $saved = json_decode((string) file_get_contents(FingerprintManager::getFingerprintPath()), true);
        self::assertSame(['client' => 'firefox', 'headers' => ['User-Agent' => 'B']], $saved['custom_b']);
    }
}
