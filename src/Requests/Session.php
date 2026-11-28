<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Curl\Curl;
use CurlCffi\Requests\Exceptions\RequestException;

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
