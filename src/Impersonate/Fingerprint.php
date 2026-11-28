<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

/**
 * A complete client fingerprint (TLS, HTTP/2, HTTP/3, headers, WebSocket),
 * like curl_cffi.Fingerprint. Pass it as `impersonate:` to send exactly this
 * fingerprint, or get a named one from FingerprintManager.
 *
 *     $fp = FingerprintManager::getFingerprint('chrome131');
 *     $fp = new Fingerprint(tls_version: '1.3', tls_ciphers: [...], http2_settings: '1:65536;...');
 *     Requests::get($url, impersonate: $fp);
 *
 * Every field also answers to its Python name ($fp->tls_ciphers).
 */
final class Fingerprint
{
    use DataFields;

    public string $client = '';
    public string $clientVersion = '';
    public string $os = '';
    public string $osVersion = '';

    /** Default HTTP version of this fingerprint; a request picks its own with http_version. */
    public string $httpVersion = 'v2';

    public string $tlsVersion = '1.2';
    /** @var list<string> */
    public array $tlsCiphers = [];
    public bool $tlsAlpn = true;
    public bool $tlsAlps = false;
    /** @var list<string> */
    public array $tlsCertCompression = [];
    /** @var list<string> */
    public array $tlsSignatureHashes = [];
    public int $tlsKeySharesLimit = 2;
    /** @var list<string> */
    public array $tlsSupportedGroups = [];
    public bool $tlsSessionTicket = false;
    public string $tlsExtensionOrder = '';
    /** @var list<string> */
    public array $tlsDelegatedCredentials = [];
    public ?int $tlsRecordSizeLimit = null;
    public bool $tlsGrease = false;
    public bool $tlsUseNewAlpsCodepoint = false;
    public bool $tlsSignedCertTimestamps = false;
    public ?string $tlsEch = null;
    public bool $tlsPermuteExtensions = false;
    /** @var list<string>|null */
    public ?array $tlsTrustAnchors = null;

    /** @var array<string, string> */
    public array $headers = [];
    public string $headerOrder = '';
    public bool $splitCookies = false;
    public string $formBoundary = '';

    public string $http2Settings = '';
    public int $http2WindowUpdate = 0;
    public string $http2PseudoHeadersOrder = '';
    public ?int $http2StreamWeight = null;
    public ?int $http2StreamExclusive = null;
    public bool $http2NoPriority = false;

    public string $http3Settings = '';
    public string $http3PseudoHeadersOrder = '';
    public string $http3TlsExtensionOrder = '';
    /** @var array<string, string> */
    public array $http3Headers = [];
    public string $http3HeaderOrder = '';
    /** @var list<string> */
    public array $http3TlsSupportedGroups = [];
    public string $quicTransportParameters = '';
    public int|string|null $quicCidLength = null;
    public ?int $quicInitialPacketNumber = null;

    /** @var array<string, string> */
    public array $wsHeaders = [];
    public string $wsHeaderOrder = '';
    public bool $wsDisableSessionTicket = false;
    /** @var list<string>|null */
    public ?array $wsTlsCertCompression = null;

    public string $headerLang = '';
}
