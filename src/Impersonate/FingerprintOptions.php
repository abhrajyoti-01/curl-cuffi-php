<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Const\CurlSslVersion;
use CurlCffi\Curl\Curl;
use CurlCffi\Requests\Exceptions\ImpersonateError;
use CurlCffi\Warnings;

/**
 * Turns ja3 / akamai / perk strings, ExtraFingerprints and Fingerprint
 * objects into curl-impersonate options. Port of set_ja3_options(),
 * set_akamai_options(), set_perk_options(), set_extra_fp() and
 * _apply_fingerprint() in curl_cffi/requests/utils.py.
 */
final class FingerprintOptions
{
    /**
     * ja3: "TLSVersion,Ciphers,Extensions,Curves,PointFormats", e.g.
     * "771,4865-4866-4867-49195,0-23-65281-10-11-35-16-5-13-18-51-45-43-27-17513,29-23-24,0".
     * With $permute, the extension order is left to SSL_PERMUTE_EXTENSIONS.
     */
    public static function setJa3Options(Curl $curl, string $ja3, bool $permute = false): void
    {
        $parts = explode(',', $ja3);
        if (count($parts) !== 5) {
            throw new ImpersonateError("Invalid ja3 string, expected 5 comma-separated fields: {$ja3}");
        }
        [$tlsVersion, $ciphers, $extensions, $curves, $curveFormats] = $parts;

        $curlTlsVersion = TlsMaps::TLS_VERSION_MAP[(int) $tlsVersion]
            ?? throw new ImpersonateError("TLS version {$tlsVersion} is not supported in ja3");
        $curl->setopt(CurlOpt::SSLVERSION, $curlTlsVersion | CurlSslVersion::MAX_DEFAULT);
        if ($curlTlsVersion !== CurlSslVersion::TLSv1_2) {
            throw new ImpersonateError('Only TLS v1.2 works for now.');
        }

        $cipherNames = [];
        foreach (explode('-', $ciphers) as $cipher) {
            $id = (int) $cipher;
            $cipherNames[] = TlsMaps::TLS_CIPHER_NAME_MAP[$id]
                ?? throw new ImpersonateError(sprintf('Cipher 0x%x is not found', $id));
        }
        $curl->setopt(CurlOpt::SSL_CIPHER_LIST, implode(':', $cipherNames));

        if (str_ends_with($extensions, '-21')) {
            $extensions = substr($extensions, 0, -3);
            Warnings::warn(
                'Padding(21) extension found in ja3 string, whether to add it should be managed by the SSL engine. '
                . 'The TLS client hello packet may contain or not contain this extension, any of which should be correct.'
            );
        }
        if (!$permute) {
            $curl->setopt(CurlOpt::TLS_EXTENSION_ORDER, $extensions);
        }

        $curveNames = [];
        foreach (explode('-', $curves) as $curve) {
            $id = (int) $curve;
            $curveNames[] = TlsMaps::TLS_EC_CURVES_MAP[$id]
                ?? throw new ImpersonateError("Curve {$id} is not found");
        }
        $curl->setopt(CurlOpt::SSL_EC_CURVES, implode(':', $curveNames));

        if ((int) $curveFormats !== 0) {
            throw new ImpersonateError('Only curve_formats == 0 is supported.');
        }
    }

    /**
     * akamai HTTP/2 fingerprint: "SETTINGS|WINDOW_UPDATE|PRIORITY|PSEUDO_HEADER_ORDER",
     * e.g. "1:65536;2:0;4:6291456;6:262144|15663105|0|m,a,s,p".
     */
    public static function setAkamaiOptions(Curl $curl, string $akamai): void
    {
        $parts = explode('|', $akamai);
        if (count($parts) !== 4) {
            throw new ImpersonateError("Invalid akamai string, expected 4 '|'-separated fields: {$akamai}");
        }
        [$settings, $windowUpdate, $streams, $headerOrder] = $parts;

        // tls.peet.ws writes settings with commas
        $settings = str_replace(',', ';', $settings);

        $curl->setopt(CurlOpt::HTTP_VERSION, CurlHttpVersion::V2_0);
        $curl->setopt(CurlOpt::HTTP2_SETTINGS, $settings);
        $curl->setopt(CurlOpt::HTTP2_WINDOW_UPDATE, (int) $windowUpdate);
        if ($streams !== '0') {
            $curl->setopt(CurlOpt::HTTP2_STREAMS, $streams);
        }
        // m,a,s,p -> masp: curl-impersonate takes it without commas
        $curl->setopt(CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER, str_replace(',', '', $headerOrder));
    }

    /** perk HTTP/3 fingerprint: "SETTINGS|PSEUDO_HEADER_ORDER|QUIC_TRANSPORT_PARAMETERS". */
    public static function setPerkOptions(Curl $curl, string $perk): void
    {
        $parts = explode('|', $perk);
        if (count($parts) !== 3) {
            throw new ImpersonateError("Invalid perk string, expected 3 '|'-separated fields: {$perk}");
        }
        [$settings, $headerOrder, $quicTransportParameters] = $parts;

        $curl->setopt(CurlOpt::HTTP3_SETTINGS, $settings);
        $curl->setopt(CurlOpt::HTTP3_PSEUDO_HEADERS_ORDER, str_replace(',', '', $headerOrder));
        $curl->setopt(CurlOpt::QUIC_TRANSPORT_PARAMETERS, $quicTransportParameters);
    }

    /** @param ExtraFingerprints|array<string, mixed> $fp */
    public static function setExtraFp(Curl $curl, ExtraFingerprints|array $fp): void
    {
        $fp = is_array($fp) ? self::extraFp($fp) : $fp;

        if ($fp->tlsSignatureAlgorithms) {
            $curl->setopt(CurlOpt::SSL_SIG_HASH_ALGS, implode(',', $fp->tlsSignatureAlgorithms));
        }
        if ($fp->tlsMinVersion !== null) {
            $curl->setopt(CurlOpt::SSLVERSION, $fp->tlsMinVersion | CurlSslVersion::MAX_DEFAULT);
        }
        if ($fp->tlsGrease !== null) {
            $curl->setopt(CurlOpt::TLS_GREASE, (int) $fp->tlsGrease);
        }
        if ($fp->tlsPermuteExtensions !== null) {
            $curl->setopt(CurlOpt::SSL_PERMUTE_EXTENSIONS, (int) $fp->tlsPermuteExtensions);
        }
        if ($fp->tlsCertCompression !== null) {
            $curl->setopt(CurlOpt::SSL_CERT_COMPRESSION, $fp->tlsCertCompression);
        }
        if ($fp->http2StreamWeight !== null) {
            $curl->setopt(CurlOpt::STREAM_WEIGHT, $fp->http2StreamWeight);
        }
        if ($fp->http2StreamExclusive !== null) {
            $curl->setopt(CurlOpt::STREAM_EXCLUSIVE, $fp->http2StreamExclusive);
        }
        if ($fp->tlsDelegatedCredential !== '') {
            $curl->setopt(CurlOpt::TLS_DELEGATED_CREDENTIALS, $fp->tlsDelegatedCredential);
        }
        if ($fp->tlsRecordSizeLimit) {
            $curl->setopt(CurlOpt::TLS_RECORD_SIZE_LIMIT, $fp->tlsRecordSizeLimit);
        }
        if ($fp->http2NoPriority) {
            $curl->setopt(CurlOpt::HTTP2_NO_PRIORITY, 1);
        }
        if ($fp->headerOrder) {
            $curl->setopt(CurlOpt::HTTPHEADER_ORDER, $fp->headerOrder);
        }
        if ($fp->formBoundary !== null) {
            $curl->setopt(CurlOpt::FORM_BOUNDARY, $fp->formBoundary);
        }
        if ($fp->splitCookies !== null) {
            $curl->setopt(CurlOpt::SPLIT_COOKIES, (int) $fp->splitCookies);
        }
        if ($fp->http3SigHashAlgs !== null) {
            $curl->setopt(CurlOpt::HTTP3_SIG_HASH_ALGS, $fp->http3SigHashAlgs);
        }
        if ($fp->http3TlsExtensionOrder !== null) {
            $curl->setopt(CurlOpt::HTTP3_TLS_EXTENSION_ORDER, $fp->http3TlsExtensionOrder);
        }
        if ($fp->quicInitialPacketNumber !== null) {
            $curl->setopt(CurlOpt::QUIC_INITIAL_PACKET_NUMBER, $fp->quicInitialPacketNumber);
        }
    }

    /**
     * Apply a full Fingerprint. Its default headers go only where the request
     * has not set a header of the same name.
     *
     * @param array<string, true> $existingHeaderNames lower-case names; updated
     */
    public static function applyFingerprint(Curl $curl, Fingerprint $fp, array &$existingHeaderNames, bool $defaultHeaders): void
    {
        if ($fp->tlsVersion !== '') {
            $curl->setopt(CurlOpt::SSLVERSION, self::normalizeTlsVersion($fp->tlsVersion) | CurlSslVersion::MAX_DEFAULT);
        }
        if ($fp->tlsCiphers) {
            $curl->setopt(CurlOpt::SSL_CIPHER_LIST, implode(':', $fp->tlsCiphers));
        }
        if ($fp->tlsExtensionOrder !== '' && !$fp->tlsPermuteExtensions) {
            $curl->setopt(CurlOpt::TLS_EXTENSION_ORDER, self::stripPaddingExtension($fp->tlsExtensionOrder));
        }

        $curl->setopt(CurlOpt::SSL_ENABLE_ALPN, (int) $fp->tlsAlpn);
        $curl->setopt(CurlOpt::SSL_ENABLE_ALPS, (int) $fp->tlsAlps);
        $curl->setopt(CurlOpt::SSL_ENABLE_TICKET, (int) $fp->tlsSessionTicket);
        $curl->setopt(CurlOpt::TLS_GREASE, (int) $fp->tlsGrease);
        if ($fp->tlsUseNewAlpsCodepoint) {
            $curl->setopt(CurlOpt::SSL_ENABLE_ALPS, 1);
            $curl->setopt(CurlOpt::TLS_USE_NEW_ALPS_CODEPOINT, 1);
        }
        // An explicit extension order is also an allowlist in curl-impersonate's
        // BoringSSL, so enable the capabilities and let the order drop them.
        $curl->setopt(CurlOpt::TLS_STATUS_REQUEST, 1);
        $curl->setopt(CurlOpt::TLS_SIGNED_CERT_TIMESTAMPS, (int) $fp->tlsSignedCertTimestamps);
        $curl->setopt(CurlOpt::TLS_KEY_SHARES_LIMIT, $fp->tlsKeySharesLimit);
        $curl->setopt(CurlOpt::SSL_CERT_COMPRESSION, implode(',', $fp->tlsCertCompression));

        if ($fp->tlsSignatureHashes) {
            $curl->setopt(CurlOpt::SSL_SIG_HASH_ALGS, implode(',', $fp->tlsSignatureHashes));
        }
        if ($fp->tlsSupportedGroups) {
            $curl->setopt(CurlOpt::SSL_EC_CURVES, implode(':', array_map(self::normalizeSupportedGroup(...), $fp->tlsSupportedGroups)));
        }
        if ($fp->tlsDelegatedCredentials) {
            $curl->setopt(CurlOpt::TLS_DELEGATED_CREDENTIALS, implode(':', $fp->tlsDelegatedCredentials));
        }
        $curl->setopt(CurlOpt::SSL_PERMUTE_EXTENSIONS, (int) $fp->tlsPermuteExtensions);
        if ($fp->tlsRecordSizeLimit !== null) {
            $curl->setopt(CurlOpt::TLS_RECORD_SIZE_LIMIT, $fp->tlsRecordSizeLimit);
        }
        if ($fp->tlsEch !== null) {
            $curl->setopt(CurlOpt::ECH, $fp->tlsEch);
        }
        if ($fp->tlsTrustAnchors !== null) {
            $curl->setopt(CurlOpt::TLS_TRUST_ANCHORS, implode(',', $fp->tlsTrustAnchors));
        }

        // HTTP/2
        if ($fp->http2Settings !== '') {
            $curl->setopt(CurlOpt::HTTP2_SETTINGS, $fp->http2Settings);
        }
        if ($fp->http2WindowUpdate) {
            $curl->setopt(CurlOpt::HTTP2_WINDOW_UPDATE, $fp->http2WindowUpdate);
        }
        if ($fp->http2PseudoHeadersOrder !== '') {
            $curl->setopt(CurlOpt::HTTP2_PSEUDO_HEADERS_ORDER, str_replace(',', '', $fp->http2PseudoHeadersOrder));
        }
        if ($fp->http2StreamWeight !== null) {
            $curl->setopt(CurlOpt::STREAM_WEIGHT, $fp->http2StreamWeight);
        }
        if ($fp->http2StreamExclusive !== null) {
            $curl->setopt(CurlOpt::STREAM_EXCLUSIVE, $fp->http2StreamExclusive);
        }
        if ($fp->http2NoPriority) {
            $curl->setopt(CurlOpt::HTTP2_NO_PRIORITY, 1);
        }

        if ($fp->headerOrder !== '') {
            $curl->setopt(CurlOpt::HTTPHEADER_ORDER, $fp->headerOrder);
        }
        $curl->setopt(CurlOpt::SPLIT_COOKIES, (int) $fp->splitCookies);
        if ($fp->formBoundary !== '') {
            $curl->setopt(CurlOpt::FORM_BOUNDARY, $fp->formBoundary);
        }

        // HTTP/3
        if ($fp->http3Settings !== '') {
            $curl->setopt(CurlOpt::HTTP3_SETTINGS, $fp->http3Settings);
        }
        if ($fp->http3PseudoHeadersOrder !== '') {
            $curl->setopt(CurlOpt::HTTP3_PSEUDO_HEADERS_ORDER, str_replace(',', '', $fp->http3PseudoHeadersOrder));
        }
        if ($fp->http3TlsExtensionOrder !== '') {
            $curl->setopt(CurlOpt::HTTP3_TLS_EXTENSION_ORDER, $fp->http3TlsExtensionOrder);
        }
        if ($fp->http3HeaderOrder !== '') {
            $curl->setopt(CurlOpt::HTTP3_HTTPHEADER_ORDER, $fp->http3HeaderOrder);
        }
        if ($fp->http3TlsSupportedGroups) {
            $curl->setopt(CurlOpt::HTTP3_SSL_EC_CURVES, implode(':', array_map(self::normalizeSupportedGroup(...), $fp->http3TlsSupportedGroups)));
        }
        if ($fp->quicTransportParameters !== '') {
            $curl->setopt(CurlOpt::QUIC_TRANSPORT_PARAMETERS, $fp->quicTransportParameters);
        }
        if ($fp->quicCidLength !== null) {
            $curl->setopt(CurlOpt::QUIC_CID_LENGTH, (string) $fp->quicCidLength);
        }
        if ($fp->quicInitialPacketNumber !== null) {
            $curl->setopt(CurlOpt::QUIC_INITIAL_PACKET_NUMBER, $fp->quicInitialPacketNumber);
        }

        // WebSocket
        if ($fp->wsHeaderOrder !== '') {
            $curl->setopt(CurlOpt::WS_HTTPHEADER_ORDER, $fp->wsHeaderOrder);
        }
        if ($fp->wsDisableSessionTicket) {
            $curl->setopt(CurlOpt::WS_SSL_DISABLE_TICKET, 1);
        }
        if ($fp->wsTlsCertCompression !== null) {
            $curl->setopt(CurlOpt::WS_SSL_CERT_COMPRESSION, implode(',', $fp->wsTlsCertCompression));
        }

        // Default headers never override headers the request set.
        if ($defaultHeaders && $fp->headers) {
            $lines = [];
            foreach ($fp->headers as $key => $value) {
                $name = strtolower((string) $key);
                if (isset($existingHeaderNames[$name])) {
                    continue;
                }
                if ($name === 'host' && $value === '') {
                    // An empty Host means "the request's host"; an empty Host
                    // line would stop libcurl from sending its own.
                    continue;
                }
                $existingHeaderNames[$name] = true;
                $lines[] = "{$key}: {$value}";
            }
            if ($lines) {
                $curl->setopt(CurlOpt::HTTPHEADER, $lines);
            }
        }
        if ($defaultHeaders && $fp->http3Headers) {
            $curl->setopt(CurlOpt::HTTP3_HTTPHEADER, self::headerLines($fp->http3Headers));
        }
        if ($defaultHeaders && $fp->wsHeaders) {
            $curl->setopt(CurlOpt::WS_HTTPHEADER, self::headerLines($fp->wsHeaders));
        }
    }

    /** "1.2", "tlsv1_3", ... => CurlSslVersion */
    public static function normalizeTlsVersion(string $version): int
    {
        return match (strtolower(trim($version))) {
            '1', 'tlsv1' => CurlSslVersion::TLSv1,
            '1.0', 'tlsv1_0' => CurlSslVersion::TLSv1_0,
            '1.1', 'tlsv1_1' => CurlSslVersion::TLSv1_1,
            '1.2', 'tlsv1_2' => CurlSslVersion::TLSv1_2,
            '1.3', 'tlsv1_3' => CurlSslVersion::TLSv1_3,
            default => throw new ImpersonateError("Unsupported TLS version: {$version}"),
        };
    }

    /** @param array<string, mixed> $fp */
    public static function extraFp(array $fp): ExtraFingerprints
    {
        return new ExtraFingerprints(...$fp);
    }

    private static function stripPaddingExtension(string $order): string
    {
        return implode('-', array_filter(explode('-', $order), static fn (string $p) => $p !== '21'));
    }

    private static function normalizeSupportedGroup(string $group): string
    {
        return $group === 'X25519Kyber768' ? 'X25519Kyber768Draft00' : $group;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        return $lines;
    }
}
