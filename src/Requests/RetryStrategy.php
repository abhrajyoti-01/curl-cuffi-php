<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/**
 * How a Session retries failed requests, like curl_cffi.requests.RetryStrategy.
 * Only transport errors (RequestException) are retried, not HTTP status codes.
 */
final class RetryStrategy
{
    public function __construct(
        public readonly int $count,
        /** Seconds to wait before a retry. */
        public readonly float $delay = 0.0,
        /** Up to this many random extra seconds are added to each delay. */
        public readonly float $jitter = 0.0,
        /** "linear" (delay * attempt) or "exponential" (delay * 2^(attempt-1)). */
        public readonly string $backoff = 'linear',
    ) {
        if ($count < 0) {
            throw new \InvalidArgumentException('retry.count must be >= 0');
        }
        if ($delay < 0) {
            throw new \InvalidArgumentException('retry.delay must be >= 0');
        }
        if ($jitter < 0) {
            throw new \InvalidArgumentException('retry.jitter must be >= 0');
        }
        if (!in_array($backoff, ['linear', 'exponential'], true)) {
            throw new \InvalidArgumentException("retry.backoff must be 'linear' or 'exponential'");
        }
    }

    public static function normalize(self|int|null $retry): self
    {
        if ($retry instanceof self) {
            return $retry;
        }

        return new self($retry ?? 0);
    }

    /** Seconds to sleep before retry number $attempt (1-based). */
    public function delayFor(int $attempt): float
    {
        $delay = $this->backoff === 'exponential'
            ? $this->delay * (2 ** ($attempt - 1))
            : $this->delay * $attempt;
        if ($this->jitter > 0) {
            $delay += mt_rand() / mt_getrandmax() * $this->jitter;
        }

        return $delay;
    }
}
