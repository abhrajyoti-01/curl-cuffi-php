<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Async\Loop;
use CurlCffi\Async\Promise;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlMime;
use CurlCffi\Requests\Exceptions\RequestException;
use CurlCffi\Requests\Exceptions\SessionClosed;

/**
 * Concurrent requests on one curl_multi loop, like
 * curl_cffi.requests.AsyncSession. Methods return a Promise instead of a
 * Response.
 *
 *     $s = new AsyncSession(impersonate: 'chrome', max_clients: 20);
 *
 *     // Python: r = await s.get(url)
 *     $r = $s->get($url)->await();
 *
 *     // Python: rs = await asyncio.gather(*[s.get(u) for u in urls])
 *     $rs = $s->gather(array_map(fn ($u) => $s->get($u), $urls));
 *
 *     // Python: async def task(): ...  (coroutines, with PHP Fibers)
 *     $results = $s->run(function () use ($s) {
 *         $login = $s->post($loginUrl, data: [...])->await();
 *         return $s->get($dataUrl)->await()->json();
 *     });
 *
 * At most max_clients transfers run at once (default 10, as in Python);
 * the rest wait in a queue.
 *
 * @method Promise<Response> head(string $url, mixed ...$options)
 * @method Promise<Response> get(string $url, mixed ...$options)
 * @method Promise<Response> post(string $url, mixed ...$options)
 * @method Promise<Response> put(string $url, mixed ...$options)
 * @method Promise<Response> patch(string $url, mixed ...$options)
 * @method Promise<Response> delete(string $url, mixed ...$options)
 * @method Promise<Response> options(string $url, mixed ...$options)
 * @method Promise<Response> trace(string $url, mixed ...$options)
 * @method Promise<Response> query(string $url, mixed ...$options)
 */
class AsyncSession extends BaseSession
{
    /** Maximum transfers running at the same time. */
    public int $maxClients;

    private Loop $loop;

    /** @var list<Curl> idle handles kept for reuse */
    private array $idle = [];
    private int $inUse = 0;

    /** @var \SplQueue<\Closure(): void> requests waiting for a free handle */
    private \SplQueue $waiting;

    /** @var \WeakMap<Promise<Response>, true> requests not settled yet */
    private \WeakMap $pending;

    public function __construct(mixed ...$options)
    {
        $maxClients = 10;
        foreach (['max_clients', 'maxClients'] as $key) {
            if (array_key_exists($key, $options)) {
                $maxClients = (int) $options[$key];
                unset($options[$key]);
            }
        }
        if ($maxClients < 1) {
            throw new \InvalidArgumentException('max_clients must be >= 1');
        }
        parent::__construct(...$options);
        $this->maxClients = $maxClients;
        $this->loop = new Loop();
        $this->waiting = new \SplQueue();
        $this->pending = new \WeakMap();
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The loop that runs this session's transfers and coroutines. */
    public function loop(): Loop
    {
        return $this->loop;
    }

    /**
     * Start a request. The returned promise resolves to a Response, or is
     * rejected with a RequestException (or HTTPError with raise_for_status).
     *
     * @return Promise<Response>
     */
    public function request(string $method, string $url, mixed ...$options): Promise
    {
        $o = Options::normalize($options, Options::REQUEST, 'request');
        $this->checkClosed();
        $method = strtoupper($method);
        $promise = new Promise($this->loop);

        if (!empty($o['stream'])) {
            // The body of a streamed response is read synchronously with
            // iterContent()/iterLines(); the request itself starts here.
            try {
                $promise->resolve($this->performSync($method, $url, $o, new Curl()));
            } catch (\Throwable $e) {
                $promise->reject($e);
            }

            return $promise;
        }

        $this->pending[$promise] = true;
        $promise->onSettle(function () use ($promise): void {
            unset($this->pending[$promise]);
        });
        $this->submit(fn () => $this->start($method, $url, $o, $promise, 0));

        return $promise;
    }

    /** @param array<mixed> $arguments */
    public function __call(string $name, array $arguments): Promise
    {
        $method = strtoupper($name);
        if (!in_array($method, ['HEAD', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'QUERY'], true)) {
            throw new \BadMethodCallException("Undefined method AsyncSession::{$name}()");
        }
        $url = $arguments['url'] ?? $arguments[0] ?? throw new \ArgumentCountError("{$name}() needs a URL");
        unset($arguments['url'], $arguments[0]);

        return $this->request($method, (string) $url, ...$arguments);
    }

    /** @return Promise<Response> a promise for a streamed response */
    public function stream(string $method, string $url, mixed ...$options): Promise
    {
        $options['stream'] = true;

        return $this->request($method, $url, ...$options);
    }

    /**
     * Wait for several promises and return their results with keys
     * preserved, like asyncio.gather(). A Closure is started as a coroutine
     * first; any other value is returned as it is. With $returnExceptions,
     * failures are returned in place instead of thrown.
     *
     * @param array<array-key, Promise<mixed>|\Closure|mixed> $items
     *
     * @return array<array-key, mixed>
     */
    public function gather(array $items, bool $returnExceptions = false): array
    {
        $promises = [];
        foreach ($items as $key => $item) {
            $promises[$key] = $item instanceof \Closure ? $this->spawn($item) : $item;
        }

        return ($returnExceptions ? Promise::allSettled($promises, $this->loop) : Promise::all($promises, $this->loop))->await();
    }

    /**
     * Start $fn as a coroutine on this session's loop. Inside it,
     * `->await()` suspends only this coroutine.
     *
     * @template T
     *
     * @param callable(mixed ...): T $fn
     *
     * @return Promise<T>
     */
    public function spawn(callable $fn, mixed ...$args): Promise
    {
        return $this->loop->spawn($fn, ...$args);
    }

    /**
     * Run $fn as a coroutine and wait for its result, like asyncio.run().
     *
     * @template T
     *
     * @param callable(mixed ...): T $fn
     *
     * @return T
     */
    public function run(callable $fn, mixed ...$args): mixed
    {
        return $this->spawn($fn, ...$args)->await();
    }

    /** A promise that resolves after $seconds, like asyncio.sleep(). */
    public function sleep(float $seconds): Promise
    {
        return $this->loop->sleep($seconds);
    }

    /** Stop all running requests (their promises are rejected with SessionClosed) and free the handles. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->loop->close();
        $unfinished = [];
        foreach ($this->pending as $promise => $_) {
            $unfinished[] = $promise;
        }
        foreach ($unfinished as $promise) {
            $promise->reject(new SessionClosed('Session was closed before the request finished.'));
        }
        foreach ($this->idle as $curl) {
            $curl->close();
        }
        $this->idle = [];
        $this->waiting = new \SplQueue();
    }

    /** @param \Closure(): void $job */
    private function submit(\Closure $job): void
    {
        if ($this->inUse < $this->maxClients) {
            $job();
        } else {
            $this->waiting->enqueue($job);
        }
    }

    /**
     * @param array<string, mixed>  $o
     * @param Promise<Response>     $promise
     */
    private function start(string $method, string $url, array $o, Promise $promise, int $attempt): void
    {
        $c = array_pop($this->idle) ?? new Curl();
        $this->inUse++;

        try {
            [$request, $buffers, $ownedMime, $injected] = $this->setCurlOptions($c, $method, $url, $o, false);
            $this->loop->addTransfer($c, function (int $code) use ($c, $request, $buffers, $ownedMime, $injected, $method, $url, $o, $promise, $attempt): void {
                $this->finish($c, $code, $request, $buffers, $ownedMime, $injected, $method, $url, $o, $promise, $attempt);
            });
        } catch (\Throwable $e) {
            $this->release($c);
            $promise->reject($e);
        }
    }

    /**
     * @param array<string, mixed> $o
     * @param list<Cookie>         $injected
     * @param Promise<Response>    $promise
     */
    private function finish(
        Curl $c,
        int $code,
        Request $request,
        TransferBuffers $buffers,
        ?CurlMime $ownedMime,
        array $injected,
        string $method,
        string $url,
        array $o,
        Promise $promise,
        int $attempt,
    ): void {
        $defaultEncoding = $o['default_encoding'] ?? $this->defaultEncoding;
        $discardCookies = ($o['discard_cookies'] ?? false) || $this->discardCookies;
        $error = null;
        $rsp = null;
        try {
            try {
                $c->afterPerform($code);
                $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
                $rsp->request = $request;
            } catch (CurlError $e) {
                $partial = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
                $partial->request = $request;
                $error = $e instanceof RequestException ? $e : RequestException::fromCurlError($e, $partial);
            } catch (\Throwable $e) {
                $error = $e;
            }
        } finally {
            $ownedMime?->close();
            $c->reset();
            $this->release($c);
        }

        if ($error instanceof RequestException && $attempt < $this->retry->count) {
            $retry = fn () => $this->submit(fn () => $this->start($method, $url, $o, $promise, $attempt + 1));
            $delay = $this->retry->delayFor($attempt + 1);
            $delay > 0 ? $this->loop->delay($delay, $retry) : $this->loop->defer($retry);

            return;
        }
        if ($error !== null) {
            $promise->reject($error);

            return;
        }
        if ($this->raiseForStatus) {
            try {
                $rsp->raiseForStatus();
            } catch (\Throwable $e) {
                $promise->reject($e);

                return;
            }
        }
        $promise->resolve($rsp);
    }

    private function release(Curl $c): void
    {
        $this->inUse--;
        if ($this->closed) {
            $c->close();

            return;
        }
        $this->idle[] = $c;
        if (!$this->waiting->isEmpty()) {
            $next = $this->waiting->dequeue();
            $this->loop->defer($next);
        }
    }
}
