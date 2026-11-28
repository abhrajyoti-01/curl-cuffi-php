<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

/**
 * Fingerprint details that a ja3 or akamai string cannot express, like
 * curl_cffi.requests.ExtraFingerprints. Pass it, or an array with the same
 * keys, as `extra_fp:`.
 *
 *     Requests::get($url, ja3: $ja3, akamai: $akamai, extra_fp: [
 *         'tls_signature_algorithms' => ['ecdsa_secp256r1_sha256', 'rsa_pss_rsae_sha256'],
 *         'tls_grease' => true,
 *     ]);
 */
final class ExtraFingerprints
{
    use DataFields;

    /** A CurlSslVersion value, e.g. CurlSslVersion::TLSv1_2. */
    public ?int $tlsMinVersion = null;
    public ?bool $tlsGrease = null;
    public ?bool $tlsPermuteExtensions = null;
    /** "zlib" or "brotli" */
    public ?string $tlsCertCompression = null;
    /** @var list<string>|null */
    public ?array $tlsSignatureAlgorithms = null;
    public string $tlsDelegatedCredential = '';
    public int $tlsRecordSizeLimit = 0;
    public ?int $http2StreamWeight = null;
    public ?int $http2StreamExclusive = null;
    public bool $http2NoPriority = false;
    public ?string $headerOrder = null;
    public ?bool $splitCookies = null;
    public ?string $formBoundary = null;
    public ?string $http3SigHashAlgs = null;
    public ?string $http3TlsExtensionOrder = null;
    public ?int $quicInitialPacketNumber = null;
}
