<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

use CurlCffi\Async\Promise;

/**
 * One queued AsyncWebSocket receive or send.
 *
 * @internal
 */
final class WsOperation
{
    public bool $done = false;
    public ?int $timer = null;
    public int $retries = 0;

    // send state
    public int $offset = 0;
    public int $frameEnd = 0;
    public int $currentFlags = 0;
    public int $zeroWrites = 0;

    /** @param Promise<mixed> $promise */
    public function __construct(
        public readonly Promise $promise,
        public readonly ?float $deadline,
        public readonly string $payload = '',
        public readonly int $flags = 0,
    ) {
        $this->currentFlags = $flags;
    }
}
