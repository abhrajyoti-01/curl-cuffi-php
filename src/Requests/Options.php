<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/**
 * Names of the options Session and Requests accept, as in Python curl_cffi.
 * Both snake_case (Python spelling) and camelCase are accepted.
 *
 * @internal
 */
final class Options
{
    /** Options of a single request (Session::request() and Requests::request()). */
    public const REQUEST = [
        'params', 'data', 'content', 'json', 'headers', 'cookies', 'files', 'auth', 'timeout',
        'allow_redirects', 'max_redirects', 'proxies', 'proxy', 'proxy_auth', 'verify', 'referer',
        'accept_encoding', 'content_callback', 'impersonate', 'ja3', 'akamai', 'perk', 'extra_fp',
        'default_headers', 'default_encoding', 'quote', 'http_version', 'interface', 'dns', 'doh_url',
        'cert', 'stream', 'max_recv_speed', 'multipart', 'discard_cookies',
    ];

    /** Options of a Session. */
    public const SESSION = [
        'headers', 'cookies', 'auth', 'proxies', 'proxy', 'proxy_auth', 'base_url', 'params', 'verify',
        'timeout', 'trust_env', 'allow_redirects', 'max_redirects', 'retry', 'impersonate', 'ja3',
        'akamai', 'perk', 'extra_fp', 'default_headers', 'default_encoding', 'curl_options', 'curl_infos',
        'http_version', 'debug', 'interface', 'dns', 'doh_url', 'cert', 'response_class',
        'discard_cookies', 'raise_for_status',
    ];

    /** Options of a WebSocket handshake: those of a GET request, plus curl_options. */
    public const WS_HANDSHAKE = [
        'params', 'headers', 'cookies', 'auth', 'timeout', 'allow_redirects', 'max_redirects', 'proxies',
        'proxy', 'proxy_auth', 'verify', 'referer', 'accept_encoding', 'impersonate', 'ja3', 'akamai', 'perk',
        'extra_fp', 'default_headers', 'quote', 'http_version', 'interface', 'dns', 'doh_url', 'cert',
        'max_recv_speed', 'curl_options',
    ];

    /** Options of new WebSocket(...). */
    public const WEBSOCKET = [
        'curl', 'autoclose', 'skip_utf8_validation', 'debug', 'on_open', 'on_close', 'on_data', 'on_message',
        'on_error', 'ws_retry', 'max_message_size',
    ];

    /** WebSocket::connect(): the handshake options plus stand-ins for session settings. */
    public const WS_CONNECT_STANDALONE = [...self::WS_HANDSHAKE, 'base_url', 'base_params', 'base_proxies', 'base_verify'];

    /** Session::wsConnect(): WebSocket settings plus handshake options. */
    public const WS_CONNECT = [
        ...self::WS_HANDSHAKE, 'on_open', 'on_close', 'on_data', 'on_message', 'on_error', 'autoclose',
        'skip_utf8_validation', 'ws_retry', 'max_message_size',
    ];

    /** AsyncSession::wsConnect(). */
    public const ASYNC_WS_CONNECT = [
        ...self::WS_HANDSHAKE, 'autoclose', 'skip_utf8_validation', 'ws_retry', 'max_message_size',
    ];

    /**
     * Normalise keys to snake_case and reject unknown ones.
     *
     * @param array<mixed>  $options
     * @param list<string>  $allowed
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $options, array $allowed, string $where): array
    {
        $out = [];
        foreach ($options as $key => $value) {
            if (!is_string($key)) {
                throw new \TypeError("{$where}: options must be passed by name, e.g. headers: [...]");
            }
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            if (!in_array($snake, $allowed, true)) {
                throw new \TypeError("{$where}: unknown option '{$key}'");
            }
            $out[$snake] = $value;
        }

        return $out;
    }
}
