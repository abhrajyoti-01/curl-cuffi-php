<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

use CurlCffi\Async\Loop;
use CurlCffi\Async\Promise;
use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlWsFlag;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlMulti;
use CurlCffi\Curl\CurlWsFrame;
use CurlCffi\Requests\Exceptions\WebSocketClosed;
use CurlCffi\Requests\Exceptions\WebSocketError;
use CurlCffi\Requests\Exceptions\WebSocketTimeout;
use CurlCffi\Requests\Options;

/**
 * WebSocket on an AsyncSession's loop, like curl_cffi's AsyncWebSocket.
 * Get one from AsyncSession::wsConnect(). Every method returns a Promise,
 * so many sockets and requests run side by side:
 *
 *     $s->run(function () use ($s) {
 *         $ws = $s->wsConnect('wss://example.com/ws')->await();
 *         $ws->sendStr('hello')->await();
 *         [$data, $flags] = $ws->recv(timeout: 5)->await();
 *         foreach ($ws as $message) { ... }      // until the server closes
 *         $ws->close()->await();
 *     });
 *
 * Receives and sends each run one at a time, in the order they were asked
 * for; a receive and a send can overlap. Data is read when a receive is
 * waiting (Python reads in a background task), and libcurl answers pings
 * while reading.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class AsyncWebSocket extends BaseWebSocket implements \IteratorAggregate
{
    private Loop $loop;

    /** @var \SplQueue<WsOperation> */
    private \SplQueue $recvQueue;
    private ?WsOperation $currentRecv = null;
    private ?int $readWatch = null;
    /** @var list<string> */
    private array $recvChunks = [];
    private int $recvMsgSize = 0;

    /** @var \SplQueue<WsOperation> */
    private \SplQueue $sendQueue;
    private ?WsOperation $currentSend = null;
    private ?int $writeWatch = null;

    /**
     * Options: autoclose, skip_utf8_validation, debug, ws_retry,
     * max_message_size. Use AsyncSession::wsConnect() rather than this.
     */
    public function __construct(Loop $loop, Curl $curl, mixed ...$options)
    {
        $o = Options::normalize($options, ['autoclose', 'skip_utf8_validation', 'debug', 'ws_retry', 'max_message_size'], 'AsyncWebSocket');
        parent::__construct(
            $curl,
            (bool) ($o['autoclose'] ?? true),
            (bool) ($o['skip_utf8_validation'] ?? false),
            (bool) ($o['debug'] ?? false),
            $o['ws_retry'] ?? null,
            (int) ($o['max_message_size'] ?? 4 * 1024 * 1024),
        );
        $this->loop = $loop;
        $this->recvQueue = new \SplQueue();
        $this->sendQueue = new \SplQueue();
    }

    public function loop(): Loop
    {
        return $this->loop;
    }

    /** Whether the connection is still usable. */
    public function isAlive(): bool
    {
        return !$this->closed;
    }

    /** Sends waiting to go out, the current one included. */
    public function sendQueueSize(): int
    {
        return $this->sendQueue->count() + ($this->currentSend !== null ? 1 : 0);
    }

    /**
     * Messages until the connection closes. Use it inside a coroutine
     * (AsyncSession::run() or spawn()) so other work keeps running.
     *
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket has been closed', $this->closeCode ?? WsCloseCode::OK);
        }
        while (true) {
            try {
                [$message, $flags] = $this->recv()->await();
            } catch (WebSocketClosed) {
                return;
            }
            if ($flags & CurlWsFlag::CLOSE) {
                return;
            }
            yield $message;
        }
    }

    /**
     * The next complete message, within $timeout seconds (null: no limit).
     *
     * @return Promise<array{0: string, 1: int}> payload and CurlWsFlag flags
     */
    public function recv(?float $timeout = null): Promise
    {
        $promise = new Promise($this->loop);
        if ($this->closed) {
            $promise->reject(new WebSocketClosed('WebSocket is closed', $this->closeCode ?? WsCloseCode::OK));

            return $promise;
        }
        $this->recvQueue->enqueue(new WsOperation($promise, $timeout === null ? null : microtime(true) + $timeout));
        $this->pumpRecv();

        return $promise;
    }

    /** @return Promise<string> a text message, checked to be UTF-8 unless skip_utf8_validation */
    public function recvStr(?float $timeout = null): Promise
    {
        return $this->recv($timeout)->then(function (array $result): string {
            [$data, $flags] = $result;
            if ($flags & CurlWsFlag::CLOSE) {
                throw new WebSocketClosed("Received close frame: {$this->closeCode} {$this->closeReason}", $this->closeCode ?? WsCloseCode::OK);
            }
            if (!($flags & CurlWsFlag::TEXT)) {
                throw new WebSocketError('Not a valid text frame', WsCloseCode::INVALID_DATA);
            }
            if (!self::isUtf8($data)) {
                if (!$this->skipUtf8Validation) {
                    $this->close(WsCloseCode::INVALID_DATA, self::INVALID_UTF8_MSG);
                }

                throw new WebSocketError(self::INVALID_UTF8_MSG, WsCloseCode::INVALID_DATA);
            }

            return $data;
        });
    }

    /** @return Promise<mixed> a JSON message, decoded by $loads or json_decode(..., true) */
    public function recvJson(?callable $loads = null, ?float $timeout = null): Promise
    {
        return $this->recvStr($timeout)->then(
            static fn (string $data) => $loads !== null ? $loads($data) : json_decode($data, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * One raw chunk from libcurl, without waiting; rejected with CurlError
     * (CurlECode::AGAIN) when nothing is there. Prefer recv().
     *
     * @return Promise<array{0: string, 1: CurlWsFrame}>
     */
    public function recvFragment(): Promise
    {
        $promise = new Promise($this->loop);
        try {
            if ($this->closed) {
                throw new WebSocketClosed('WebSocket is already closed', $this->closeCode ?? WsCloseCode::OK);
            }
            [$chunk, $frame] = $this->curl()->wsRecv();
            if ($frame->flags & CurlWsFlag::CLOSE) {
                $this->handleCloseFrame($chunk);
            }
            $promise->resolve([$chunk, $frame]);
        } catch (\Throwable $e) {
            $promise->reject($e);
        }

        return $promise;
    }

    /**
     * Send a message; large ones go out as several frames. Resolves to the
     * bytes sent once libcurl has taken all of them.
     *
     * @return Promise<int>
     */
    public function send(string $payload, int $flags = CurlWsFlag::BINARY, ?float $timeout = null): Promise
    {
        $promise = new Promise($this->loop);
        if ($this->closed) {
            $promise->reject(new WebSocketClosed('WebSocket is already closed', $this->closeCode ?? WsCloseCode::OK));

            return $promise;
        }
        $this->sendQueue->enqueue(new WsOperation($promise, $timeout === null ? null : microtime(true) + $timeout, $payload, $flags));
        $this->pumpSend();

        return $promise;
    }

    /** @return Promise<int> */
    public function sendBinary(string $payload, ?float $timeout = null): Promise
    {
        return $this->send($payload, CurlWsFlag::BINARY, $timeout);
    }

    /** @return Promise<int> alias of sendBinary() */
    public function sendBytes(string $payload, ?float $timeout = null): Promise
    {
        return $this->send($payload, CurlWsFlag::BINARY, $timeout);
    }

    /** @return Promise<int> */
    public function sendStr(string $payload, ?float $timeout = null): Promise
    {
        return $this->send($payload, CurlWsFlag::TEXT, $timeout);
    }

    /** @return Promise<int> $payload as JSON text; $dumps defaults to compact json_encode */
    public function sendJson(mixed $payload, ?callable $dumps = null, ?float $timeout = null): Promise
    {
        try {
            $text = $dumps !== null ? (string) $dumps($payload) : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return Promise::rejected($e, $this->loop);
        }

        return $this->send($text, CurlWsFlag::TEXT, $timeout);
    }

    /** @return Promise<int> */
    public function ping(string $payload = '', ?float $timeout = null): Promise
    {
        try {
            return $this->send($this->prepareControlPayload($payload), CurlWsFlag::PING, $timeout);
        } catch (\Throwable $e) {
            return Promise::rejected($e, $this->loop);
        }
    }

    /** @return Promise<int> an unsolicited pong; libcurl answers pings by itself */
    public function pong(string $payload = '', ?float $timeout = null): Promise
    {
        try {
            return $this->send($this->prepareControlPayload($payload), CurlWsFlag::PONG, $timeout);
        } catch (\Throwable $e) {
            return Promise::rejected($e, $this->loop);
        }
    }

    /** @return Promise<null> resolves when every queued send has gone out */
    public function flush(?float $timeout = null): Promise
    {
        $promise = new Promise($this->loop);
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $check = function () use (&$check, $promise, $deadline): void {
            if ($this->sendQueueSize() === 0 || $this->closed) {
                $promise->resolve(null);
            } elseif ($deadline !== null && microtime(true) >= $deadline) {
                $promise->reject(new WebSocketTimeout('Timed out waiting for the send queue to drain', CurlECode::OPERATION_TIMEDOUT));
            } else {
                $this->loop->delay(0.005, $check);
            }
        };
        $check();

        return $promise;
    }

    /**
     * Send a close frame after the queued messages, then shut down. The
     * reason is cut to 123 bytes.
     *
     * @return Promise<null>
     */
    public function close(int $code = WsCloseCode::OK, string $message = '', float $timeout = 3.0): Promise
    {
        $promise = new Promise($this->loop);
        if ($this->closed) {
            $promise->resolve(null);

            return $promise;
        }
        if (!self::isUtf8($message)) {
            $promise->reject(new WebSocketError('Close reason must be valid UTF-8 (RFC 6455 section 5.5.1)', WsCloseCode::INVALID_DATA));

            return $promise;
        }
        if (strlen($message) > self::MAX_CLOSE_REASON_SIZE) {
            $message = substr($message, 0, self::MAX_CLOSE_REASON_SIZE);
            while ($message !== '' && !self::isUtf8($message)) {
                $message = substr($message, 0, -1);
            }
        }
        $code = $this->validateCloseCode($code);
        if ($this->closeCode === null) {
            $this->closeCode = $code;
            $this->closeReason = $message;
        }
        $done = function () use ($promise): void {
            $this->terminate();
            $promise->resolve(null);
        };
        $this->send($this->packCloseFrame($code, $message), CurlWsFlag::CLOSE, $timeout)->then($done, $done);

        return $promise;
    }

    /** Close at once, without a close frame; waiting receives and sends are rejected. */
    public function terminate(): void
    {
        if ($this->closed && $this->curl === null) {
            return;
        }
        $this->closed = true;
        foreach ([$this->readWatch, $this->writeWatch] as $watch) {
            if ($watch !== null) {
                $this->loop->unwatchSocket($watch);
            }
        }
        $this->readWatch = $this->writeWatch = null;

        $error = new WebSocketClosed('WebSocket is closed', $this->closeCode ?? WsCloseCode::OK);
        $pending = array_filter([$this->currentRecv, $this->currentSend]);
        while (!$this->recvQueue->isEmpty()) {
            $pending[] = $this->recvQueue->dequeue();
        }
        while (!$this->sendQueue->isEmpty()) {
            $pending[] = $this->sendQueue->dequeue();
        }
        $this->currentRecv = $this->currentSend = null;
        $this->recvChunks = [];
        $this->recvMsgSize = 0;
        parent::terminate();
        $this->curl = null;

        foreach ($pending as $op) {
            $this->settle($op, $error);
        }
    }

    // ---- receiving ----

    private function pumpRecv(): void
    {
        if ($this->currentRecv !== null || $this->recvQueue->isEmpty() || $this->closed) {
            return;
        }
        $this->currentRecv = $this->recvQueue->dequeue();
        $this->doRecv($this->currentRecv);
    }

    private function doRecv(WsOperation $op): void
    {
        if ($op->done) {
            return;
        }
        $dataMask = CurlWsFlag::BINARY | CurlWsFlag::TEXT | CurlWsFlag::CONT;
        while (true) {
            try {
                [$chunk, $frame] = $this->curl()->wsRecv();
                $op->retries = 0;
                $flags = $frame->flags;
                if ($flags & $dataMask) {
                    $this->recvMsgSize += strlen($chunk);
                    if ($this->recvMsgSize > $this->maxMessageSize) {
                        $size = $this->recvMsgSize;
                        $this->recvChunks = [];
                        $this->recvMsgSize = 0;
                        $this->finishRecv($op, new WebSocketError("Message too large: {$size} bytes.", CurlECode::TOO_LARGE));
                        $this->close(WsCloseCode::MESSAGE_TOO_BIG, 'Message too large');

                        return;
                    }
                    $this->recvChunks[] = $chunk;
                    if ($frame->bytesleft === 0 && !($flags & CurlWsFlag::CONT)) {
                        $message = implode('', $this->recvChunks);
                        $this->recvChunks = [];
                        $this->recvMsgSize = 0;
                        $this->finishRecv($op, null, [$message, $flags]);

                        return;
                    }
                } elseif ($flags & CurlWsFlag::CLOSE) {
                    // Record the close code before anyone sees the result.
                    $code = $this->setCloseState($chunk);
                    $this->finishRecv($op, null, [$chunk, $flags]);
                    if ($this->autoclose && !$this->closed) {
                        $this->close($code);
                    }

                    return;
                }
            } catch (CurlError $e) {
                if (!($e instanceof WebSocketError) && $e->getCode() === CurlECode::GOT_NOTHING) {
                    $this->closeCode ??= WsCloseCode::ABNORMAL_CLOSURE;
                    $this->finishRecv($op, new WebSocketClosed('Server EOF', WsCloseCode::ABNORMAL_CLOSURE, $e));
                    $this->terminate();

                    return;
                }
                if (!($e instanceof WebSocketError) && $this->isTransientError($e)) {
                    $this->waitToRecv($op);

                    return;
                }
                if ($this->wsRetry->retry && in_array($e->getCode(), $this->wsRetry->codes, true) && $op->retries < $this->wsRetry->count) {
                    $op->retries++;
                    $this->loop->delay($this->getRetryDelay($op->retries), fn () => $this->doRecv($op));

                    return;
                }
                $this->finishRecv($op, $e);

                return;
            }
            if ($op->deadline !== null && microtime(true) >= $op->deadline) {
                $this->finishRecv($op, new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT));

                return;
            }
        }
    }

    private function waitToRecv(WsOperation $op): void
    {
        if ($op->deadline !== null) {
            $remaining = $op->deadline - microtime(true);
            if ($remaining <= 0) {
                $this->finishRecv($op, new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT));

                return;
            }
            $op->timer ??= $this->loop->delay($remaining, function () use ($op): void {
                $op->timer = null;
                $this->finishRecv($op, new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT));
            });
        }
        try {
            $fd = $this->getSockFd();
        } catch (WebSocketError $e) {
            $this->finishRecv($op, $e);

            return;
        }
        $this->readWatch = $this->loop->watchSocket($fd, CurlMulti::WAIT_POLLIN, function () use ($op): void {
            $this->readWatch = null;
            $this->doRecv($op);
        });
    }

    /** @param array{0: string, 1: int}|null $result */
    private function finishRecv(WsOperation $op, ?\Throwable $error, ?array $result = null): void
    {
        if ($op->done) {
            return;
        }
        if ($this->readWatch !== null) {
            $this->loop->unwatchSocket($this->readWatch);
            $this->readWatch = null;
        }
        if ($this->currentRecv === $op) {
            $this->currentRecv = null;
        }
        $this->settle($op, $error, $result);
        $this->loop->defer(fn () => $this->pumpRecv());
    }

    private function handleCloseFrame(string $frame): void
    {
        $code = $this->setCloseState($frame);
        if ($this->autoclose && !$this->closed) {
            $this->close($code);
        }
    }

    // ---- sending ----

    private function pumpSend(): void
    {
        if ($this->currentSend !== null || $this->sendQueue->isEmpty() || $this->closed) {
            return;
        }
        $this->currentSend = $this->sendQueue->dequeue();
        $this->doSend($this->currentSend);
    }

    private function doSend(WsOperation $op): void
    {
        if ($op->done) {
            return;
        }
        $total = strlen($op->payload);
        $baseFlags = $op->flags & ~CurlWsFlag::CONT;
        while ($op->offset < $total || ($op->offset === 0 && $total === 0)) {
            // libcurl needs a resumed write to be exactly the rest of the open frame.
            if ($op->offset === $op->frameEnd) {
                if ($total - $op->offset > self::MAX_CURL_FRAME_SIZE) {
                    $op->frameEnd = $op->offset + self::MAX_CURL_FRAME_SIZE;
                    $op->currentFlags = $baseFlags | CurlWsFlag::CONT;
                } else {
                    $op->frameEnd = $total;
                    $op->currentFlags = $op->flags;
                }
            }
            try {
                $sent = $this->curl()->wsSend(substr($op->payload, $op->offset, $op->frameEnd - $op->offset), $op->currentFlags);
                if ($sent === 0 && $op->frameEnd - $op->offset === 0) {
                    $this->finishSend($op, null, $op->offset);

                    return;
                }
                if ($sent === 0) {
                    if (++$op->zeroWrites >= self::MAX_ZERO_WRITES) {
                        $this->failSend($op, new WebSocketError("Writer stalled ({$op->zeroWrites} attempts).", CurlECode::WRITE_ERROR));

                        return;
                    }
                    $this->waitToSend($op);

                    return;
                }
                $op->zeroWrites = 0;
                $op->offset += $sent;
            } catch (CurlError $e) {
                if ($this->isTransientError($e)) {
                    $this->waitToSend($op);

                    return;
                }
                $this->failSend($op, $e);

                return;
            }
            if ($op->deadline !== null && $op->offset < $total && microtime(true) >= $op->deadline) {
                $this->failSend($op, new WebSocketTimeout('Socket write timeout', CurlECode::OPERATION_TIMEDOUT));

                return;
            }
        }
        $this->finishSend($op, null, $op->offset);
    }

    private function waitToSend(WsOperation $op): void
    {
        if ($op->deadline !== null) {
            $remaining = $op->deadline - microtime(true);
            if ($remaining <= 0) {
                $this->failSend($op, new WebSocketTimeout('Socket write timeout', CurlECode::OPERATION_TIMEDOUT));

                return;
            }
            $op->timer ??= $this->loop->delay($remaining, function () use ($op): void {
                $op->timer = null;
                $this->failSend($op, new WebSocketTimeout('Socket write timeout', CurlECode::OPERATION_TIMEDOUT));
            });
        }
        try {
            $fd = $this->getSockFd();
        } catch (WebSocketError $e) {
            $this->failSend($op, $e);

            return;
        }
        $this->writeWatch = $this->loop->watchSocket($fd, CurlMulti::WAIT_POLLOUT, function () use ($op): void {
            $this->writeWatch = null;
            $this->doSend($op);
        });
    }

    /** A half-sent frame can't be replaced by a later send, so a failed send ends the connection. */
    private function failSend(WsOperation $op, \Throwable $error): void
    {
        $this->finishSend($op, $error);
        $this->terminate();
    }

    private function finishSend(WsOperation $op, ?\Throwable $error, ?int $sent = null): void
    {
        if ($op->done) {
            return;
        }
        if ($this->writeWatch !== null) {
            $this->loop->unwatchSocket($this->writeWatch);
            $this->writeWatch = null;
        }
        if ($this->currentSend === $op) {
            $this->currentSend = null;
        }
        $this->settle($op, $error, $sent);
        $this->loop->defer(fn () => $this->pumpSend());
    }

    private function settle(WsOperation $op, ?\Throwable $error, mixed $result = null): void
    {
        if ($op->done) {
            return;
        }
        $op->done = true;
        if ($op->timer !== null) {
            $this->loop->cancelTimer($op->timer);
            $op->timer = null;
        }
        $error !== null ? $op->promise->reject($error) : $op->promise->resolve($result);
    }
}
