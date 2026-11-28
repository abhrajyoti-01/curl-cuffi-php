<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlMulti;
use CurlCffi\Requests\Exceptions\RequestException;

/**
 * A transfer driven step by step through curl_multi, so the body of a
 * `stream: true` response can be read chunk by chunk without threads.
 *
 * @internal
 */
final class StreamTransfer
{
    private const POLL_MS = 1000;

    /** @var \SplQueue<string> */
    private \SplQueue $chunks;
    private CurlMulti $multi;
    private bool $done = false;
    private bool $closed = false;
    private bool $gotBody = false;
    private ?\Throwable $error = null;
    /** Set by close(): makes the write callback abort the transfer. */
    private bool $quit = false;

    /** @var \Closure(CurlError): \Throwable */
    private \Closure $errorFactory;

    /** Freed together with the transfer, e.g. a CurlMime form. */
    private ?\CurlCffi\Curl\CurlMime $owned = null;

    /** @param \Closure(CurlError): \Throwable $errorFactory turns a transfer error into the exception to throw */
    public function __construct(private readonly Curl $curl, \Closure $errorFactory)
    {
        $this->chunks = new \SplQueue();
        $this->multi = new CurlMulti();
        $this->errorFactory = $errorFactory;
    }

    /** Keep $mime alive while the transfer runs and free it on close(). */
    public function own(?\CurlCffi\Curl\CurlMime $mime): void
    {
        $this->owned = $mime;
    }

    /** The WRITEFUNCTION to install on the handle before start(). */
    public function writer(): \Closure
    {
        return function (string $chunk): int {
            if ($this->quit) {
                return 0; // CURL_WRITEFUNC_ERROR: stop the transfer
            }
            $this->gotBody = true;
            if ($chunk !== '') {
                $this->chunks->enqueue($chunk);
            }

            return strlen($chunk);
        };
    }

    /** Start the transfer and run it until the first body bytes arrive or it ends. */
    public function start(): void
    {
        $this->multi->add($this->curl);
        while (!$this->gotBody && !$this->done) {
            $this->step();
        }
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** Error that ended the transfer, if any (already converted). */
    public function error(): ?\Throwable
    {
        return $this->error;
    }

    /** Next body chunk, or null when the body is complete. Throws if the transfer failed. */
    public function next(): ?string
    {
        while ($this->chunks->isEmpty() && !$this->done) {
            $this->step();
        }
        if (!$this->chunks->isEmpty()) {
            return $this->chunks->dequeue();
        }
        if ($this->error !== null && !$this->closed) {
            $error = $this->error;
            $this->error = null;
            throw $error;
        }

        return null;
    }

    /** Stop the transfer (if still running) and free the handle. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->quit = true;
        $this->multi->remove($this->curl);
        $this->multi->close();
        $this->curl->close();
        $this->owned?->close();
        $this->owned = null;
        $this->chunks = new \SplQueue();
    }

    private function step(): void
    {
        $queued = $this->chunks->count();
        $hadBody = $this->gotBody;
        $this->multi->perform();
        foreach ($this->multi->infoRead() as [, $code]) {
            $this->finish($code);
        }
        // Wait for network activity only when this round produced nothing new.
        if (!$this->done && $this->chunks->count() === $queued && $this->gotBody === $hadBody) {
            $this->multi->poll(self::POLL_MS);
        }
    }

    private function finish(int $code): void
    {
        $this->done = true;
        $this->multi->remove($this->curl);
        try {
            $this->curl->afterPerform($code);
        } catch (CurlError $e) {
            $this->error = $e instanceof RequestException ? $e : ($this->errorFactory)($e);
        } catch (\Throwable $e) {
            $this->error = $e;
        }
    }
}
