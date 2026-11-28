<?php

declare(strict_types=1);

namespace CurlCffi\Async;

use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlMulti;

/**
 * A small event loop around one curl_multi handle: runs transfers, timers,
 * deferred callbacks and Fiber coroutines. Each AsyncSession has one.
 *
 * No extension or library is needed; PHP Fibers (8.1+) provide the
 * coroutines that Python gets from asyncio.
 */
final class Loop
{
    private const MAX_POLL_MS = 1000;

    private CurlMulti $multi;

    /** @var \SplQueue<\Closure(): void> */
    private \SplQueue $ready;

    /** @var array<int, array{0: float, 1: \Closure(): void}> id => [due time, callback] */
    private array $timers = [];
    private int $timerId = 0;

    /** @var array<int, array{0: Curl, 1: \Closure(int): void}> handle id => [curl, onDone] */
    private array $transfers = [];

    /** @var \WeakMap<\Fiber, true> coroutines started by this loop */
    private \WeakMap $fibers;

    public function __construct()
    {
        $this->multi = new CurlMulti();
        $this->ready = new \SplQueue();
        $this->fibers = new \WeakMap();
    }

    /**
     * Start $curl's transfer; $onDone gets the CURLcode when it finishes.
     *
     * @param \Closure(int): void $onDone
     */
    public function addTransfer(Curl $curl, \Closure $onDone): void
    {
        $this->multi->add($curl);
        $this->transfers[spl_object_id($curl)] = [$curl, $onDone];
    }

    /** Stop a running transfer without calling its callback. */
    public function cancelTransfer(Curl $curl): void
    {
        $id = spl_object_id($curl);
        if (isset($this->transfers[$id])) {
            $this->multi->remove($curl);
            unset($this->transfers[$id]);
        }
    }

    public function activeTransfers(): int
    {
        return count($this->transfers);
    }

    /** Run $fn on the next tick. */
    public function defer(\Closure $fn): void
    {
        $this->ready->enqueue($fn);
    }

    /** Run $fn after $seconds. Returns an id for cancelTimer(). */
    public function delay(float $seconds, \Closure $fn): int
    {
        $id = ++$this->timerId;
        $this->timers[$id] = [microtime(true) + max(0.0, $seconds), $fn];

        return $id;
    }

    public function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /** A promise that resolves after $seconds, like asyncio.sleep(). */
    public function sleep(float $seconds): Promise
    {
        $p = new Promise($this);
        $this->delay($seconds, static fn () => $p->resolve(null));

        return $p;
    }

    /**
     * Run $fn as a coroutine. It starts right away and runs until its first
     * await(); the promise settles with its return value or exception.
     *
     * @template T
     *
     * @param callable(mixed ...): T $fn
     *
     * @return Promise<T>
     */
    public function spawn(callable $fn, mixed ...$args): Promise
    {
        $promise = new Promise($this);
        $fiber = new \Fiber(static function () use ($fn, $args, $promise): void {
            try {
                $promise->resolve($fn(...$args));
            } catch (\Throwable $e) {
                $promise->reject($e);
            }
        });
        $this->fibers[$fiber] = true;
        $fiber->start();

        return $promise;
    }

    public function ownsFiber(\Fiber $fiber): bool
    {
        return isset($this->fibers[$fiber]);
    }

    /**
     * Do one round of work: deferred callbacks, due timers, transfer progress.
     * Blocks briefly in curl_multi_poll() when there is nothing else to do.
     *
     * @return bool false when there is nothing left to wait for
     */
    public function tick(): bool
    {
        $progress = $this->runReady();
        $progress = $this->runTimers() || $progress;

        if ($this->transfers !== []) {
            $this->multi->perform();
            foreach ($this->multi->infoRead() as [$curl, $code]) {
                $id = spl_object_id($curl);
                if (!isset($this->transfers[$id])) {
                    continue;
                }
                [, $onDone] = $this->transfers[$id];
                unset($this->transfers[$id]);
                $this->multi->remove($curl);
                $onDone($code);
                $progress = true;
            }
            $progress = $this->runReady() || $progress;
            if (!$progress && $this->transfers !== []) {
                $this->multi->poll($this->pollTimeoutMs());
            }

            return true;
        }

        if (!$this->ready->isEmpty()) {
            return true;
        }
        if ($this->timers !== []) {
            if (!$progress) {
                $wait = $this->pollTimeoutMs();
                if ($wait > 0) {
                    usleep($wait * 1000);
                }
            }

            return true;
        }

        return $progress;
    }

    /** Run the loop until nothing is left to do. */
    public function run(): void
    {
        while ($this->tick()) {
        }
    }

    public function close(): void
    {
        foreach ($this->transfers as [$curl]) {
            $this->multi->remove($curl);
        }
        $this->transfers = [];
        $this->timers = [];
        $this->ready = new \SplQueue();
        $this->multi->close();
    }

    private function runReady(): bool
    {
        if ($this->ready->isEmpty()) {
            return false;
        }
        // Only what is queued now; callbacks queued while running wait for the next tick.
        $count = $this->ready->count();
        for ($i = 0; $i < $count && !$this->ready->isEmpty(); $i++) {
            ($this->ready->dequeue())();
        }

        return true;
    }

    private function runTimers(): bool
    {
        if ($this->timers === []) {
            return false;
        }
        $now = microtime(true);
        $due = [];
        foreach ($this->timers as $id => [$at, $fn]) {
            if ($at <= $now) {
                $due[$id] = [$at, $fn];
            }
        }
        if ($due === []) {
            return false;
        }
        uasort($due, static fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($due as $id => [, $fn]) {
            unset($this->timers[$id]);
            $fn();
        }

        return true;
    }

    private function pollTimeoutMs(): int
    {
        $timeout = self::MAX_POLL_MS;
        if ($this->timers !== []) {
            $next = min(array_map(static fn ($t) => $t[0], $this->timers));
            $timeout = (int) max(0, min($timeout, ceil(($next - microtime(true)) * 1000)));
        }

        return $timeout;
    }
}
