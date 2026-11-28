<?php

declare(strict_types=1);

namespace CurlCffi\Async;

/**
 * The eventual result of an async operation, the PHP stand-in for a Python
 * awaitable.
 *
 *     $r = $session->get($url)->await();          // like: r = await s.get(url)
 *     $p->then(fn (Response $r) => ..., fn (\Throwable $e) => ...);
 *
 * await() inside a coroutine started by the same loop (AsyncSession::run()
 * or spawn()) suspends only that coroutine, so others keep running. Anywhere
 * else it runs the loop until the promise settles.
 *
 * @template T
 */
final class Promise
{
    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private int $state = self::PENDING;
    private mixed $value = null;
    private ?\Throwable $reason = null;

    /** @var list<\Closure(): void> */
    private array $onSettle = [];

    public function __construct(private readonly ?Loop $loop = null)
    {
    }

    /** @param T $value */
    public function resolve(mixed $value = null): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }
        if ($value instanceof self) {
            $value->onSettle(function () use ($value): void {
                $value->isFulfilled() ? $this->resolve($value->value) : $this->reject($value->reason);
            });

            return;
        }
        $this->state = self::FULFILLED;
        $this->value = $value;
        $this->settle();
    }

    public function reject(\Throwable $reason): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }
        $this->state = self::REJECTED;
        $this->reason = $reason;
        $this->settle();
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    /**
     * Wait for the result: returns the value or throws the rejection reason.
     *
     * @return T
     */
    public function await(): mixed
    {
        if ($this->state === self::PENDING) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null && $this->loop?->ownsFiber($fiber)) {
                $loop = $this->loop;
                $this->onSettle(static function () use ($loop, $fiber): void {
                    $loop->defer(static fn () => $fiber->resume());
                });
                \Fiber::suspend();
            } else {
                $loop = $this->loop ?? throw new \LogicException('This promise has no loop to wait on.');
                while ($this->state === self::PENDING) {
                    if (!$loop->tick()) {
                        throw new \LogicException('Deadlock: the promise is pending but the loop has nothing left to run.');
                    }
                }
            }
        }
        if ($this->state === self::REJECTED) {
            throw $this->reason;
        }

        return $this->value;
    }

    /**
     * Alias of await(), for Guzzle-style code.
     *
     * @return T
     */
    public function wait(): mixed
    {
        return $this->await();
    }

    /**
     * Chain callbacks. Returns a promise for the callback's result.
     *
     * @param (callable(T): mixed)|null           $onFulfilled
     * @param (callable(\Throwable): mixed)|null  $onRejected
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $next = new self($this->loop);
        $this->onSettle(function () use ($next, $onFulfilled, $onRejected): void {
            try {
                if ($this->state === self::FULFILLED) {
                    $next->resolve($onFulfilled !== null ? $onFulfilled($this->value) : $this->value);
                } elseif ($onRejected !== null) {
                    $next->resolve($onRejected($this->reason));
                } else {
                    $next->reject($this->reason);
                }
            } catch (\Throwable $e) {
                $next->reject($e);
            }
        });

        return $next;
    }

    /** @param callable(\Throwable): mixed $onRejected */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /** @param callable(): void $onFinally runs either way; the result passes through */
    public function finally(callable $onFinally): self
    {
        return $this->then(
            static function ($value) use ($onFinally) {
                $onFinally();

                return $value;
            },
            static function (\Throwable $e) use ($onFinally) {
                $onFinally();

                throw $e;
            }
        );
    }

    /**
     * A promise for all values, keys preserved, rejected by the first failure,
     * like asyncio.gather().
     *
     * @param array<array-key, Promise<mixed>|mixed> $items
     *
     * @return Promise<array<array-key, mixed>>
     */
    public static function all(array $items, ?Loop $loop = null): self
    {
        $loop ??= self::findLoop($items);
        $result = new self($loop);
        $values = [];
        $left = count($items);
        if ($left === 0) {
            $result->resolve([]);

            return $result;
        }
        foreach ($items as $key => $item) {
            $values[$key] = null;
            if (!$item instanceof self) {
                $values[$key] = $item;
                if (--$left === 0) {
                    $result->resolve($values);
                }
                continue;
            }
            $item->onSettle(static function () use ($item, $key, &$values, &$left, $result): void {
                if ($item->isRejected()) {
                    $result->reject($item->reason);

                    return;
                }
                $values[$key] = $item->value;
                if (--$left === 0) {
                    $result->resolve($values);
                }
            });
        }

        return $result;
    }

    /**
     * A promise for every outcome, never rejected: each entry is the value or
     * the Throwable, like asyncio.gather(..., return_exceptions=True).
     *
     * @param array<array-key, Promise<mixed>|mixed> $items
     *
     * @return Promise<array<array-key, mixed>>
     */
    public static function allSettled(array $items, ?Loop $loop = null): self
    {
        $wrapped = [];
        foreach ($items as $key => $item) {
            $wrapped[$key] = $item instanceof self
                ? $item->then(null, static fn (\Throwable $e) => $e)
                : $item;
        }

        return self::all($wrapped, $loop ?? self::findLoop($items));
    }

    /** @return Promise<mixed> */
    public static function resolved(mixed $value = null, ?Loop $loop = null): self
    {
        $p = new self($loop);
        $p->resolve($value);

        return $p;
    }

    /** @return Promise<never> */
    public static function rejected(\Throwable $reason, ?Loop $loop = null): self
    {
        $p = new self($loop);
        $p->reject($reason);

        return $p;
    }

    /** @internal run $fn once settled (immediately if already settled) */
    public function onSettle(\Closure $fn): void
    {
        if ($this->state === self::PENDING) {
            $this->onSettle[] = $fn;
        } else {
            $fn();
        }
    }

    public function loop(): ?Loop
    {
        return $this->loop;
    }

    private function settle(): void
    {
        $callbacks = $this->onSettle;
        $this->onSettle = [];
        foreach ($callbacks as $fn) {
            $fn();
        }
    }

    /** @param array<mixed> $items */
    private static function findLoop(array $items): ?Loop
    {
        foreach ($items as $item) {
            if ($item instanceof self && $item->loop !== null) {
                return $item->loop;
            }
        }

        return null;
    }
}
