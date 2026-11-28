<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Curl\Curl;
use CurlCffi\Requests\Exceptions\RequestException;
use CurlCffi\Requests\WebSocket\WebSocket;

/**
 * A session keeps headers, cookies, connections and settings across
 * requests, like curl_cffi.requests.Session.
 *
 *     $s = new Session(impersonate: 'chrome', timeout: 10);
 *     $r = $s->get('https://example.com', params: ['q' => 'php']);
 *     $r = $s->post('https://example.com/api', json: ['a' => 1]);
 *
 * Options are passed by name, using the Python names (snake_case) or
 * camelCase. See Options::SESSION and Options::REQUEST for the full list.
 *
 * @method Response head(string $url, mixed ...$options)
 * @method Response get(string $url, mixed ...$options)
 * @method Response post(string $url, mixed ...$options)
 * @method Response put(string $url, mixed ...$options)
 * @method Response patch(string $url, mixed ...$options)
 * @method Response delete(string $url, mixed ...$options)
 * @method Response options(string $url, mixed ...$options)
 * @method Response trace(string $url, mixed ...$options)
 * @method Response query(string $url, mixed ...$options)
 */
class Session extends BaseSession
{
    private ?Curl $curl = null;

    /**
     * Takes the Session options (Options::SESSION), plus `curl`: a Curl
     * handle to use instead of a new one, as in Python's Session(curl=...).
     */
    public function __construct(mixed ...$options)
    {
        $curl = $options['curl'] ?? null;
        unset($options['curl']);
        if ($curl !== null && !$curl instanceof Curl) {
            throw new \TypeError('`curl` must be a ' . Curl::class);
        }
        parent::__construct(...$options);
        $this->curl = $curl;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The reusable easy handle behind this session. */
    public function curl(): Curl
    {
        $this->checkClosed();

        return $this->curl ??= new Curl();
    }

    /**
     * curl_easy_upkeep() on the session's handle: keeps its idle connection
     * alive, e.g. with an HTTP/2 PING. Returns a curl result code.
     */
    public function upkeep(): int
    {
        return $this->curl()->upkeep();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->curl?->close();
        $this->curl = null;
    }

    /** @param array<mixed> $arguments */
    public function __call(string $name, array $arguments): Response
    {
        $method = strtoupper($name);
        if (!in_array($method, ['HEAD', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'QUERY'], true)) {
            throw new \BadMethodCallException("Undefined method Session::{$name}()");
        }
        $url = $arguments['url'] ?? $arguments[0] ?? throw new \ArgumentCountError("{$name}() needs a URL");
        unset($arguments['url'], $arguments[0]);

        return $this->request($method, (string) $url, ...$arguments);
    }

    /** Send a request with a streamed body; read it with iterContent()/iterLines(), then close(). */
    /**
     * Connect a WebSocket with this session's settings (headers, cookies,
     * impersonate, proxies, ...), like Python's Session.ws_connect().
     *
     * WebSocket options: on_message, on_error, on_open, on_close, on_data,
     * autoclose, skip_utf8_validation, ws_retry, max_message_size. The rest
     * are request options for the handshake.
     */
    public function wsConnect(string $url, mixed ...$options): WebSocket
    {
        $o = Options::normalize($options, Options::WS_CONNECT, 'wsConnect');
        $this->checkClosed();
        $wsOptions = array_intersect_key($o, array_flip(Options::WEBSOCKET));
        $handshake = array_diff_key($o, $wsOptions);

        $curl = new Curl(debug: $this->debug);
        $ws = new WebSocket(...['curl' => $curl, 'debug' => $this->debug] + $wsOptions);
        try {
            $this->wsHandshake($curl, $url, $handshake);
        } catch (\Throwable $e) {
            $ws->terminate();

            throw $e;
        }

        return $ws;
    }

    public function stream(string $method, string $url, mixed ...$options): Response
    {
        $options['stream'] = true;

        return $this->request($method, $url, ...$options);
    }

    /** Send a request, retrying transport errors according to the session's retry strategy. */
    public function request(string $method, string $url, mixed ...$options): Response
    {
        $o = Options::normalize($options, Options::REQUEST, 'request');
        $this->checkClosed();

        for ($attempt = 0; ; $attempt++) {
            try {
                // A streamed response keeps its handle busy, so it gets its own.
                $handle = empty($o['stream']) ? $this->curl() : new Curl();

                return $this->performSync(strtoupper($method), $url, $o, $handle);
            } catch (RequestException $e) {
                if ($attempt >= $this->retry->count) {
                    throw $e;
                }
                $delay = $this->retry->delayFor($attempt + 1);
                if ($delay > 0) {
                    usleep((int) round($delay * 1_000_000));
                }
            }
        }
    }
}
