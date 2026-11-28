<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

use CurlCffi\Curl\Const\CurlECode;

/**
 * Retry policy for failed WebSocket receives, like Python's
 * WebSocketRetryStrategy. Each retry waits delay * 2^(attempt - 1), ±10%.
 */
final class WebSocketRetryStrategy
{
    /**
     * @param list<int> $codes CurlECode values to retry on
     */
    public function __construct(
        public bool $retry = false,
        public float $delay = 0.3,
        public int $count = 3,
        public array $codes = [CurlECode::RECV_ERROR],
    ) {
    }
}
