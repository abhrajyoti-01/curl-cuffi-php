<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Const\CurlSslVersion;
use CurlCffi\Curl\Curl;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Impersonate\ExtraFingerprints;
use CurlCffi\Impersonate\Fingerprint;
use CurlCffi\Impersonate\FingerprintOptions;
use CurlCffi\Requests\Exceptions\ImpersonateError;
use CurlCffi\Warnings;
use PHPUnit\Framework\TestCase;

/** Checks which curl options each fingerprint input turns into, as curl_cffi's utils.py does. */
final class FingerprintOptionsTest extends TestCase
{
    // okhttp 4 on Android 10, from curl_cffi's docs (without the padding extension)
    private const JA3 = '771,4865-4866-4867-49195-49196-52393-49199-49200-52392-49171-49172-156-157-47-53,'
        . '0-23-65281-10-11-35-16-5-13-51-45-43,29-23-24,0';

    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    public function testJa3(): void
    {
        $c = new RecordingCurl();
        FingerprintOptions::setJa3Options($c, self::JA3);

        self::assertSame(CurlSslVersion::TLSv1_2 | CurlSslVersion::MAX_DEFAULT, $c->options[CurlOpt::SSLVERSION]);
        self::assertSame(
            'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:'
            . 'TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256:TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384:'
            . 'TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256:'
            . 'TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256:'
            . 'TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA:TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA:'
            . 'TLS_RSA_WITH_AES_128_GCM_SHA256:TLS_RSA_WITH_AES_256_GCM_SHA384:'
            . 'TLS_RSA_WITH_AES_128_CBC_SHA:TLS_RSA_WITH_AES_256_CBC_SHA',
            $c->options[CurlOpt::SSL_CIPHER_LIST]
        );
        self::assertSame('0-23-65281-10-11-35-16-5-13-51-45-43', $c->options[CurlOpt::TLS_EXTENSION_ORDER]);
        self::assertSame('X25519:P-256:P-384', $c->options[CurlOpt::SSL_EC_CURVES]);

        $c = new RecordingCurl();
        FingerprintOptions::setJa3Options($c, self::JA3, permute: true);
        self::assertArrayNotHasKey(CurlOpt::TLS_EXTENSION_ORDER, $c->options, 'permuted: no fixed order');
    }

    public function testJa3PaddingExtensionWarns(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = [$no, $msg];

            return true;
        });
        try {
            // Silent by default, as Python's config_warnings(on=False) on import
            $c = new RecordingCurl();
            FingerprintOptions::setJa3Options($c, str_replace(',29-23', '-21,29-23', self::JA3));
            self::assertSame([], $warnings);

            Warnings::config(on: true);
            $c = new RecordingCurl();
            FingerprintOptions::setJa3Options($c, str_replace(',29-23', '-21,29-23', self::JA3));
        } finally {
            Warnings::config(on: false);
            restore_error_handler();
        }
        self::assertSame('0-23-65281-10-11-35-16-5-13-51-45-43', $c->options[CurlOpt::TLS_EXTENSION_ORDER]);
        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][0]);
        self::assertStringStartsWith('Padding(21) extension found', $warnings[0][1]);
    }

    /** @return array<string, array{string, string}> */
    public static function badJa3(): array
    {
        return [
            'tls 1.3' => ['772,4865,0,29,0', 'Only TLS v1.2 works for now.'],
            'unknown version' => ['700,4865,0,29,0', 'TLS version 700 is not supported in ja3'],
            'unknown cipher' => ['771,4865-1,0,29,0', 'Cipher 0x1 is not found'],
            'unknown curve' => ['771,4865,0,29-99,0', 'Curve 99 is not found'],
            'point formats' => ['771,4865,0,29,1', 'Only curve_formats == 0 is supported.'],
            'fields' => ['771,4865,0', 'Invalid ja3 string'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badJa3')]
    public function testBadJa3(string $ja3, string $message): void
    {
        $this->expectException(ImpersonateError::class);
        $this->expectExceptionMessage($message);
        FingerprintOptions::setJa3Options(new RecordingCurl(), $ja3);
    }

    public function testAkamai(): void
    {
        $c = new RecordingCurl();
        // tls.peet.ws prints settings with commas
        FingerprintOptions::setAkamaiOptions($c, '1:65536,2:0,4:6291456,6:262144|15663105|0|m,a,s,p');

        self::assertSame(CurlHttpVersion::V2_0, $c->options[CurlOpt::HTTP_VERSION]);
        self::assertSame('1:65536;2:0;4:6291456;6:262144', $c->options[CurlOpt::HTTP2_SETTINGS]);
        self::assertSame(15663105, $c->options[CurlOpt::HTTP2_WINDOW_UPDATE]);
        self::assertArrayNotHasKey(CurlOpt::HTTP2_STREAMS, $c->options, '"0" means no priority frames');
        self::assertSame('masp', $c->options[CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER]);

        $c = new RecordingCurl();
        FingerprintOptions::setAkamaiOptions($c, '1:65536|12517377|3:0:0:201,5:0:0:101|m,p,a,s');
        self::assertSame('3:0:0:201,5:0:0:101', $c->options[CurlOpt::HTTP2_STREAMS]);
        self::assertSame('mpas', $c->options[CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER]);

        $this->expectException(ImpersonateError::class);
        FingerprintOptions::setAkamaiOptions($c, '1:65536|0');
    }

    public function testPerk(): void
    {
        $c = new RecordingCurl();
        FingerprintOptions::setPerkOptions($c, '1:65536;6:262144|m,a,s,p|0x1:30000;0x4:1048576');
        self::assertSame('1:65536;6:262144', $c->options[CurlOpt::HTTP3_SETTINGS]);
        self::assertSame('masp', $c->options[CurlOpt::HTTP3_PSEUDO_HEADERS_ORDER]);
        self::assertSame('0x1:30000;0x4:1048576', $c->options[CurlOpt::QUIC_TRANSPORT_PARAMETERS]);
    }

    public function testExtraFp(): void
    {
        $c = new RecordingCurl();
        FingerprintOptions::setExtraFp($c, [
            'tls_signature_algorithms' => ['ecdsa_secp256r1_sha256', 'rsa_pss_rsae_sha256'],
            'tls_min_version' => CurlSslVersion::TLSv1_2,
            'tls_grease' => true,
            'tls_permute_extensions' => false,
            'tls_cert_compression' => 'brotli',
            'http2_stream_weight' => 256,
            'http2_stream_exclusive' => 1,
            'http2_no_priority' => true,
            'tls_record_size_limit' => 4001,
            'tls_delegated_credential' => 'ecdsa_secp256r1_sha256',
            'header_order' => 'host,user-agent',
        ]);
        self::assertSame('ecdsa_secp256r1_sha256,rsa_pss_rsae_sha256', $c->options[CurlOpt::SSL_SIG_HASH_ALGS]);
        self::assertSame(CurlSslVersion::TLSv1_2 | CurlSslVersion::MAX_DEFAULT, $c->options[CurlOpt::SSLVERSION]);
        self::assertSame(1, $c->options[CurlOpt::TLS_GREASE]);
        self::assertSame(0, $c->options[CurlOpt::SSL_PERMUTE_EXTENSIONS]);
        self::assertSame('brotli', $c->options[CurlOpt::SSL_CERT_COMPRESSION]);
        self::assertSame(256, $c->options[CurlOpt::STREAM_WEIGHT]);
        self::assertSame(1, $c->options[CurlOpt::STREAM_EXCLUSIVE]);
        self::assertSame(1, $c->options[CurlOpt::HTTP2_NO_PRIORITY]);
        self::assertSame(4001, $c->options[CurlOpt::TLS_RECORD_SIZE_LIMIT]);
        self::assertSame('ecdsa_secp256r1_sha256', $c->options[CurlOpt::TLS_DELEGATED_CREDENTIALS]);
        self::assertSame('host,user-agent', $c->options[CurlOpt::HTTPHEADER_ORDER]);
        self::assertArrayNotHasKey(CurlOpt::SPLIT_COOKIES, $c->options, 'unset fields set nothing');

        $this->expectException(\TypeError::class);
        FingerprintOptions::setExtraFp($c, ['not_a_field' => 1]);
    }

    public function testFingerprint(): void
    {
        $fp = new Fingerprint(
            tls_version: '1.3',
            tls_ciphers: ['TLS_AES_128_GCM_SHA256', 'TLS_AES_256_GCM_SHA384'],
            tls_extension_order: '0-23-21-65281-10',
            tls_supported_groups: ['X25519Kyber768', 'X25519'],
            tls_cert_compression: ['brotli'],
            tls_grease: true,
            http2_settings: '1:65536;4:6291456',
            http2_window_update: 15663105,
            http2_pseudo_headers_order: 'm,a,s,p',
            headers: ['User-Agent' => 'FP/1.0', 'Accept' => '*/*', 'Host' => '', 'X-Fp' => 'fp'],
            ws_headers: ['Sec-WebSocket-Extensions' => 'permessage-deflate'],
        );
        $c = new RecordingCurl();
        $existing = ['x-fp' => true];
        FingerprintOptions::applyFingerprint($c, $fp, $existing, true);

        self::assertSame(CurlSslVersion::TLSv1_3 | CurlSslVersion::MAX_DEFAULT, $c->options[CurlOpt::SSLVERSION]);
        self::assertSame('TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384', $c->options[CurlOpt::SSL_CIPHER_LIST]);
        self::assertSame('0-23-65281-10', $c->options[CurlOpt::TLS_EXTENSION_ORDER], 'padding (21) is left to BoringSSL');
        self::assertSame('X25519Kyber768Draft00:X25519', $c->options[CurlOpt::SSL_EC_CURVES]);
        self::assertSame('brotli', $c->options[CurlOpt::SSL_CERT_COMPRESSION]);
        self::assertSame(1, $c->options[CurlOpt::TLS_GREASE]);
        self::assertSame(1, $c->options[CurlOpt::SSL_ENABLE_ALPN]);
        self::assertSame(0, $c->options[CurlOpt::SSL_ENABLE_TICKET]);
        self::assertSame(2, $c->options[CurlOpt::TLS_KEY_SHARES_LIMIT]);
        self::assertSame('1:65536;4:6291456', $c->options[CurlOpt::HTTP2_SETTINGS]);
        self::assertSame('masp', $c->options[CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER]);
        self::assertSame(['User-Agent: FP/1.0', 'Accept: */*'], $c->options[CurlOpt::HTTPHEADER], 'no empty Host, X-Fp is the request\'s');
        self::assertSame(['Sec-WebSocket-Extensions: permessage-deflate'], $c->options[CurlOpt::WS_HTTPHEADER]);
        self::assertSame(['x-fp' => true, 'user-agent' => true, 'accept' => true], $existing);

        $c = new RecordingCurl();
        $existing = [];
        FingerprintOptions::applyFingerprint($c, $fp, $existing, false);
        self::assertArrayNotHasKey(CurlOpt::HTTPHEADER, $c->options, 'default_headers: false');

        $this->expectException(ImpersonateError::class);
        FingerprintOptions::applyFingerprint($c, new Fingerprint(tls_version: '2.0'), $existing, true);
    }

    public function testDataclassFields(): void
    {
        $fp = new Fingerprint(tlsVersion: '1.3', http2_window_update: 1);
        self::assertSame('1.3', $fp->tls_version);
        self::assertSame(1, $fp->http2WindowUpdate);
        $fp->tls_grease = true;
        self::assertTrue($fp->tlsGrease);
        self::assertSame('v2', $fp->toArray()['http_version']);
        self::assertArrayHasKey('ws_tls_cert_compression', $fp->toArray());

        $copy = Fingerprint::fromArray($fp->toArray() + ['from_a_newer_version' => 1]);
        self::assertEquals($fp, $copy, 'unknown keys are ignored');

        $extra = ExtraFingerprints::fromArray(['tls_record_size_limit' => '4001']);
        self::assertSame(4001, $extra->tlsRecordSizeLimit, 'JSON strings are converted');

        $this->expectException(\TypeError::class);
        new Fingerprint(nope: 1);
    }
}

/** Records options instead of passing them to libcurl, which may not know curl-impersonate's. */
final class RecordingCurl extends Curl
{
    /** @var array<int, mixed> */
    public array $options = [];

    public function setopt(int $option, mixed $value): int
    {
        if (in_array($option, [CurlOpt::HTTPHEADER, CurlOpt::WS_HTTPHEADER, CurlOpt::HTTP3_HTTPHEADER], true)) {
            $this->options[$option] = array_merge($this->options[$option] ?? [], $value);
        } else {
            $this->options[$option] = $value;
        }

        return 0;
    }
}
