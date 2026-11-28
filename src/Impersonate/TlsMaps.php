<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

use CurlCffi\Curl\Const\CurlSslVersion;

/**
 * Lookup tables for ja3 strings, the same as curl_cffi.requests.impersonate
 * (TLS_VERSION_MAP, TLS_CIPHER_NAME_MAP, TLS_EXTENSION_NAME_MAP, TLS_EC_CURVES_MAP).
 */
final class TlsMaps
{
    /** TLS version in a ja3 string (0x0303 = 771) => CurlSslVersion */
    public const TLS_VERSION_MAP = [
        0x0301 => CurlSslVersion::TLSv1_0,
        0x0302 => CurlSslVersion::TLSv1_1,
        0x0303 => CurlSslVersion::TLSv1_2,
        0x0304 => CurlSslVersion::TLSv1_3,
    ];

    /** Cipher suite id => BoringSSL name, from the IANA registry */
    public const TLS_CIPHER_NAME_MAP = [
        0x000A => 'TLS_RSA_WITH_3DES_EDE_CBC_SHA',
        0x002F => 'TLS_RSA_WITH_AES_128_CBC_SHA',
        0x0033 => 'TLS_DHE_RSA_WITH_AES_128_CBC_SHA',
        0x0035 => 'TLS_RSA_WITH_AES_256_CBC_SHA',
        0x0039 => 'TLS_DHE_RSA_WITH_AES_256_CBC_SHA',
        0x003C => 'TLS_RSA_WITH_AES_128_CBC_SHA256',
        0x003D => 'TLS_RSA_WITH_AES_256_CBC_SHA256',
        0x0067 => 'TLS_DHE_RSA_WITH_AES_128_CBC_SHA256',
        0x006B => 'TLS_DHE_RSA_WITH_AES_256_CBC_SHA256',
        0x008C => 'TLS_PSK_WITH_AES_128_CBC_SHA',
        0x008D => 'TLS_PSK_WITH_AES_256_CBC_SHA',
        0x009C => 'TLS_RSA_WITH_AES_128_GCM_SHA256',
        0x009D => 'TLS_RSA_WITH_AES_256_GCM_SHA384',
        0x009E => 'TLS_DHE_RSA_WITH_AES_128_GCM_SHA256',
        0x009F => 'TLS_DHE_RSA_WITH_AES_256_GCM_SHA384',
        0x1301 => 'TLS_AES_128_GCM_SHA256',
        0x1302 => 'TLS_AES_256_GCM_SHA384',
        0x1303 => 'TLS_CHACHA20_POLY1305_SHA256',
        0xC008 => 'TLS_ECDHE_ECDSA_WITH_3DES_EDE_CBC_SHA',
        0xC009 => 'TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA',
        0xC00A => 'TLS_ECDHE_ECDSA_WITH_AES_256_CBC_SHA',
        0xC012 => 'TLS_ECDHE_RSA_WITH_3DES_EDE_CBC_SHA',
        0xC013 => 'TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA',
        0xC014 => 'TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA',
        0xC023 => 'TLS_ECDHE_ECDSA_WITH_AES_128_CBC_SHA256',
        0xC024 => 'TLS_ECDHE_ECDSA_WITH_AES_256_CBC_SHA384',
        0xC027 => 'TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA256',
        0xC028 => 'TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA384',
        0xC02B => 'TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256',
        0xC02C => 'TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384',
        0xC02F => 'TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256',
        0xC030 => 'TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384',
        0xC035 => 'TLS_ECDHE_PSK_WITH_AES_128_CBC_SHA',
        0xC036 => 'TLS_ECDHE_PSK_WITH_AES_256_CBC_SHA',
        0xCCA8 => 'TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256',
        0xCCA9 => 'TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256',
        0xCCAC => 'TLS_ECDHE_PSK_WITH_CHACHA20_POLY1305_SHA256',
    ];

    /** TLS extension id => name, from the IANA registry */
    public const TLS_EXTENSION_NAME_MAP = [
        0 => 'server_name',
        1 => 'max_fragment_length',
        2 => 'client_certificate_url',
        3 => 'trusted_ca_keys',
        4 => 'truncated_hmac',
        5 => 'status_request',
        6 => 'user_mapping',
        7 => 'client_authz',
        8 => 'server_authz',
        9 => 'cert_type',
        10 => 'supported_groups',
        11 => 'ec_point_formats',
        12 => 'srp',
        13 => 'signature_algorithms',
        14 => 'use_srtp',
        15 => 'heartbeat',
        16 => 'application_layer_protocol_negotiation',
        17 => 'status_request_v2',
        18 => 'signed_certificate_timestamp',
        19 => 'client_certificate_type',
        20 => 'server_certificate_type',
        21 => 'padding',
        22 => 'encrypt_then_mac',
        23 => 'extended_master_secret',
        24 => 'token_binding',
        25 => 'cached_info',
        26 => 'tls_lts',
        27 => 'compress_certificate',
        28 => 'record_size_limit',
        29 => 'pwd_protect',
        30 => 'pwd_clear',
        31 => 'password_salt',
        32 => 'ticket_pinning',
        33 => 'tls_cert_with_extern_psk',
        34 => 'delegated_credential',
        35 => 'session_ticket',
        36 => 'TLMSP',
        37 => 'TLMSP_proxying',
        38 => 'TLMSP_delegate',
        39 => 'supported_ekt_ciphers',
        41 => 'pre_shared_key',
        42 => 'early_data',
        43 => 'supported_versions',
        44 => 'cookie',
        45 => 'psk_key_exchange_modes',
        47 => 'certificate_authorities',
        48 => 'oid_filters',
        49 => 'post_handshake_auth',
        50 => 'signature_algorithms_cert',
        51 => 'key_share',
        52 => 'transparency_info',
        54 => 'connection_id',
        55 => 'external_id_hash',
        56 => 'external_session_id',
        57 => 'quic_transport_parameters',
        58 => 'ticket_request',
        59 => 'dnssec_chain',
        60 => 'sequence_number_encryption_algorithms',
        61 => 'rrc',
        17513 => 'application_settings',
        17613 => 'application_settings new',
        64768 => 'ech_outer_extensions',
        65037 => 'encrypted_client_hello',
        65281 => 'renegotiation_info',
    ];

    /** Supported group (curve) id => name */
    public const TLS_EC_CURVES_MAP = [
        19 => 'P-192',
        21 => 'P-224',
        23 => 'P-256',
        24 => 'P-384',
        25 => 'P-521',
        29 => 'X25519',
        256 => 'ffdhe2048',
        257 => 'ffdhe3072',
        4588 => 'X25519MLKEM768',
        25497 => 'X25519Kyber768Draft00',
    ];
}
