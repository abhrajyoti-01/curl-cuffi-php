<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/**
 * One-off requests, like the module-level functions of curl_cffi.requests:
 *
 *     use CurlCffi\Requests\Requests;
 *
 *     $r = Requests::get('https://example.com', impersonate: 'chrome');
 *     echo $r->statusCode, $r->text;
 *
 * Each call uses a fresh Session, so nothing (cookies, connections) is
 * shared between calls; use a Session for that.
 *
 * @method static Response head(string $url, mixed ...$options)
 * @method static Response get(string $url, mixed ...$options)
 * @method static Response post(string $url, mixed ...$options)
 * @method static Response put(string $url, mixed ...$options)
 * @method static Response patch(string $url, mixed ...$options)
 * @method static Response delete(string $url, mixed ...$options)
 * @method static Response options(string $url, mixed ...$options)
 * @method static Response trace(string $url, mixed ...$options)
 * @method static Response query(string $url, mixed ...$options)
 */
final class Requests
{
    /**
     * Send one request. Accepts every request option, plus the session
     * options curl_options and debug, as in Python.
     */
    public static function request(string $method, string $url, mixed ...$options): Response
    {
        $sessionOptions = [];
        foreach (['curl_options', 'curlOptions', 'debug'] as $key) {
            if (array_key_exists($key, $options)) {
                $sessionOptions[$key] = $options[$key];
                unset($options[$key]);
            }
        }
        $session = new Session(...$sessionOptions);
        try {
            $response = $session->request($method, $url, ...$options);
        } finally {
            // A streamed response owns its own handle, so closing the session is safe.
            $session->close();
        }

        return $response;
    }

    /** @param array<mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): Response
    {
        $method = strtoupper($name);
        if (!in_array($method, ['HEAD', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'QUERY'], true)) {
            throw new \BadMethodCallException("Undefined method Requests::{$name}()");
        }
        $url = $arguments['url'] ?? $arguments[0] ?? throw new \ArgumentCountError("{$name}() needs a URL");
        unset($arguments['url'], $arguments[0]);

        return self::request($method, (string) $url, ...$arguments);
    }
}
