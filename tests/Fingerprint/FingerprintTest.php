<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Fingerprint;

use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Curl;
use CurlCffi\Ffi\Library;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves fingerprint parity with Python curl_cffi.
 *
 * Needs network access and two inputs:
 *  - libcurl-impersonate loaded (run bin/curl-cffi-install)
 *  - CURL_CFFI_FP_REFERENCE: a JSON file written by tests/Fingerprint/python_reference.py,
 *    i.e. what Python curl_cffi reports for the same targets
 *
 * JA3 is not compared because Chrome randomises TLS extension order on every
 * connection; JA4 sorts extensions, so it is stable and compared instead.
 */
#[Group('network')]
final class FingerprintTest extends TestCase
{
    public const ECHO_URL = 'https://tls.peet.ws/api/clean';
    public const TARGETS = ['chrome', 'chrome131', 'edge101', 'safari', 'safari_ios', 'firefox', 'tor'];

    /** @return array<string, array{string}> */
    public static function targets(): array
    {
        $out = [];
        foreach (self::TARGETS as $t) {
            $out[$t] = [$t];
        }

        return $out;
    }

    #[DataProvider('targets')]
    public function testMatchesPythonCurlCffi(string $target): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('libcurl-impersonate is not loaded');
        }
        $refFile = getenv('CURL_CFFI_FP_REFERENCE');
        if (!is_string($refFile) || !is_file($refFile)) {
            self::markTestSkipped('Set CURL_CFFI_FP_REFERENCE to the output of python_reference.py');
        }
        $reference = json_decode((string) file_get_contents($refFile), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($reference[$target])) {
            self::markTestSkipped("No Python reference for $target");
        }

        $php = self::fetch($target);
        $py = $reference[$target];

        self::assertSame($py['ja4'], $php['ja4'], "JA4 differs for $target");
        self::assertSame($py['akamai_hash'], $php['akamai_hash'], "HTTP/2 Akamai fingerprint differs for $target");
        self::assertSame($py['akamai'], $php['akamai']);
    }

    #[DataProvider('targets')]
    public function testRequestsApiMatchesPythonCurlCffi(string $target): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('libcurl-impersonate is not loaded');
        }
        $refFile = getenv('CURL_CFFI_FP_REFERENCE');
        if (!is_string($refFile) || !is_file($refFile)) {
            self::markTestSkipped('Set CURL_CFFI_FP_REFERENCE to the output of python_reference.py');
        }
        $reference = json_decode((string) file_get_contents($refFile), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($reference[$target])) {
            self::markTestSkipped("No Python reference for $target");
        }

        // Same call as Python's requests.get(URL, impersonate=target, timeout=30)
        $php = \CurlCffi\Requests\Requests::get(self::ECHO_URL, impersonate: $target, timeout: 30)->json();
        $py = $reference[$target];

        self::assertSame($py['ja4'], $php['ja4'], "JA4 differs for $target");
        self::assertSame($py['akamai_hash'], $php['akamai_hash'], "HTTP/2 Akamai fingerprint differs for $target");
    }

    public function testAsyncSessionMatchesPythonCurlCffi(): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('libcurl-impersonate is not loaded');
        }
        $refFile = getenv('CURL_CFFI_FP_REFERENCE');
        if (!is_string($refFile) || !is_file($refFile)) {
            self::markTestSkipped('Set CURL_CFFI_FP_REFERENCE to the output of python_reference.py');
        }
        $reference = json_decode((string) file_get_contents($refFile), true, 512, JSON_THROW_ON_ERROR);

        // Every target at once on one curl_multi loop, like
        // asyncio.gather(*[s.get(URL, impersonate=t) for t in targets])
        $s = new \CurlCffi\Requests\AsyncSession(timeout: 30);
        $promises = [];
        foreach (self::TARGETS as $target) {
            if (isset($reference[$target])) {
                $promises[$target] = $s->get(self::ECHO_URL, impersonate: $target);
            }
        }
        if ($promises === []) {
            self::markTestSkipped('No Python reference targets');
        }
        foreach ($s->gather($promises) as $target => $r) {
            $php = $r->json();
            self::assertSame($reference[$target]['ja4'], $php['ja4'], "JA4 differs for $target (async)");
            self::assertSame($reference[$target]['akamai_hash'], $php['akamai_hash'], "HTTP/2 fingerprint differs for $target (async)");
        }
    }

    public function testCustomJa3AndAkamaiMatchPythonCurlCffi(): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('libcurl-impersonate is not loaded');
        }
        $refFile = getenv('CURL_CFFI_FP_REFERENCE');
        if (!is_string($refFile) || !is_file($refFile)) {
            self::markTestSkipped('Set CURL_CFFI_FP_REFERENCE to the output of python_reference.py');
        }
        $reference = json_decode((string) file_get_contents($refFile), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($reference['custom'])) {
            self::markTestSkipped('No Python reference for the custom fingerprint');
        }
        $custom = json_decode((string) file_get_contents(__DIR__ . '/custom.json'), true, 512, JSON_THROW_ON_ERROR);

        // Same call as python_reference.py: requests.get(URL, ja3=..., akamai=..., extra_fp=...)
        $php = \CurlCffi\Requests\Requests::get(
            self::ECHO_URL,
            ja3: $custom['ja3'],
            akamai: $custom['akamai'],
            extra_fp: $custom['extra_fp'],
            timeout: 30,
        )->json();
        $py = $reference['custom'];

        // A fixed extension order makes even the JA3 hash stable.
        self::assertSame($py['ja3_hash'], $php['ja3_hash'], 'JA3 differs');
        self::assertSame($py['ja4'], $php['ja4'], 'JA4 differs');
        self::assertSame($py['akamai_hash'], $php['akamai_hash'], 'HTTP/2 fingerprint differs');
        self::assertSame($py['peetprint_hash'] ?? null, $php['peetprint_hash'] ?? null, 'peetprint differs');
    }

    /** @return array<string, mixed> */
    public static function fetch(string $target): array
    {
        $c = new Curl();
        $body = '';
        $c->impersonate($target);
        $c->setopt(CurlOpt::URL, self::ECHO_URL);
        $c->setopt(CurlOpt::TIMEOUT, 30);
        $c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });
        $c->perform();

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
