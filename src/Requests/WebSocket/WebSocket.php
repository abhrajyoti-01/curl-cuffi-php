<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlWsFlag;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlMulti;
use CurlCffi\Curl\CurlWsFrame;
use CurlCffi\Requests\Exceptions\WebSocketClosed;
use CurlCffi\Requests\Exceptions\WebSocketError;
use CurlCffi\Requests\Exceptions\WebSocketTimeout;
use CurlCffi\Requests\Options;
use CurlCffi\Requests\Session;
use CurlCffi\Warnings;

/**
 * Synchronous WebSocket client, like curl_cffi.requests.WebSocket. It uses
 * the same handshake as a request, so impersonate, ja3, proxies, cookies and
 * headers all apply, and the TLS fingerprint is the browser's.
 *
 *     $ws = $session->wsConnect('wss://example.com/ws', impersonate: 'chrome');
 *     $ws->sendStr('hello');
 *     [$data, $flags] = $ws->recv();
 *     foreach ($ws as $message) { ... }       // until the server closes
 *     $ws->close();
 *
 *     // callbacks, like Python's run_forever()
 *     (new WebSocket(on_message: fn (WebSocket $ws, string $msg) => print($msg)))
 *         ->runForever('wss://example.com/ws');
 *
 * libcurl answers pings by itself. Messages are reassembled from fragments
 * and limited to max_message_size bytes.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class WebSocket extends BaseWebSocket implements \IteratorAggregate
{
    /** Cleared by close() or a close frame to stop runForever(). */
    public bool $keepRunning = false;

    /** @var array<string, callable> event => callback */
    private array $emitters = [];
    private ?CurlMulti $poller = null;
    /** @var list<string> */
    private array $recvChunks = [];
    private int $recvMsgSize = 0;

    /**
     * Options use Python's names (snake_case or camelCase): autoclose,
     * skip_utf8_validation, debug, on_open, on_close, on_data, on_message,
     * on_error, ws_retry, max_message_size, and curl (a Curl handle to use).
     *
     * Callbacks: on_open(ws), on_close(ws, int $code, string $reason),
     * on_data(ws, string $chunk, CurlWsFrame $frame), on_message(ws, string $message),
     * on_error(ws, \Throwable $e).
     */
    public function __construct(mixed ...$options)
    {
        $o = Options::normalize($options, Options::WEBSOCKET, 'WebSocket');
        parent::__construct(
            $o['curl'] ?? null,
            (bool) ($o['autoclose'] ?? true),
            (bool) ($o['skip_utf8_validation'] ?? false),
            (bool) ($o['debug'] ?? false),
            $o['ws_retry'] ?? null,
            (int) ($o['max_message_size'] ?? 4 * 1024 * 1024),
        );
        foreach (['open', 'close', 'data', 'message', 'error'] as $event) {
            if (isset($o["on_{$event}"])) {
                $this->emitters[$event] = $o["on_{$event}"];
            }
        }
    }

    public function __destruct()
    {
        if (!$this->closed && $this->curl !== null) {
            $this->terminate();
        }
    }

    /**
     * Connect to $url. Options are those of a request (params, headers,
     * cookies, auth, timeout, proxy, verify, impersonate, ja3, akamai,
     * extra_fp, ...) plus curl_options; base_url, base_params, base_proxies
     * and base_verify play the role of session settings.
     */
    public function connect(string $url, mixed ...$options): self
    {
        $o = Options::normalize($options, Options::WS_CONNECT_STANDALONE, 'connect');
        $session = new Session(
            base_url: $o['base_url'] ?? null,
            params: $o['base_params'] ?? null,
            proxies: $o['base_proxies'] ?? [],
            verify: $o['base_verify'] ?? true,
            debug: $this->debug,
        );
        unset($o['base_url'], $o['base_params'], $o['base_proxies'], $o['base_verify']);

        $this->sockFd = -1;
        $this->poller = null;
        try {
            $session->wsHandshake($this->curl(), $url, $o);
        } catch (\Throwable $e) {
            // Don't leak the handle when the connection fails.
            $this->terminate();
            throw $e;
        } finally {
            $session->close();
        }
        $this->closed = false;

        return $this;
    }

    /** @return \Generator<int, string> messages until the connection closes */
    public function getIterator(): \Generator
    {
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket is closed', $this->closeCode ?? WsCloseCode::OK);
        }
        while (true) {
            try {
                [$message, $flags] = $this->recv();
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
     * Receive the next complete message, waiting up to $timeout seconds
     * (null: no limit).
     *
     * @return array{0: string, 1: int} payload and CurlWsFlag flags
     */
    public function recv(?float $timeout = null): array
    {
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket is closed', $this->closeCode ?? WsCloseCode::OK);
        }
        $this->getSockFd();
        $curl = $this->curl();
        $retries = 0;
        $msgSize = $this->recvMsgSize;
        $completed = false;
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $dataMask = CurlWsFlag::BINARY | CurlWsFlag::TEXT | CurlWsFlag::CONT;

        try {
            while (true) {
                try {
                    [$chunk, $frame] = $curl->wsRecv();
                    $retries = 0;
                    $flags = $frame->flags;

                    if ($flags & $dataMask) {
                        $msgSize += strlen($chunk);
                        if ($msgSize > $this->maxMessageSize) {
                            $this->recvChunks = [];
                            $this->close(WsCloseCode::MESSAGE_TOO_BIG, 'Message too large');

                            throw new WebSocketError("Message too large: {$msgSize} bytes.", CurlECode::TOO_LARGE);
                        }
                        $this->recvChunks[] = $chunk;
                        if ($frame->bytesleft === 0 && !($flags & CurlWsFlag::CONT)) {
                            $completed = true;

                            return [implode('', $this->recvChunks), $flags];
                        }
                    } elseif ($flags & CurlWsFlag::CLOSE) {
                        $this->handleCloseFrame($chunk);

                        return [$chunk, $flags];
                    }
                } catch (CurlError $e) {
                    if ($e instanceof WebSocketError) {
                        throw $e;
                    }
                    if ($e->getCode() === CurlECode::GOT_NOTHING) {
                        $this->terminate();

                        throw new WebSocketClosed('Server EOF', WsCloseCode::ABNORMAL_CLOSURE, $e);
                    }
                    if ($this->isTransientError($e)) {
                        if ($deadline !== null) {
                            $remaining = $deadline - microtime(true);
                            if ($remaining <= 0 || !$this->wait(CurlMulti::WAIT_POLLIN, $remaining)) {
                                throw new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT);
                            }
                        } else {
                            $this->wait(CurlMulti::WAIT_POLLIN, null);
                        }
                        continue;
                    }
                    if ($this->wsRetry->retry && in_array($e->getCode(), $this->wsRetry->codes, true) && $retries < $this->wsRetry->count) {
                        $retries++;
                        $sleep = $this->getRetryDelay($retries);
                        if ($deadline !== null) {
                            $remaining = $deadline - microtime(true);
                            if ($remaining <= 0) {
                                throw new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT);
                            }
                            $sleep = min($sleep, $remaining);
                        }
                        usleep((int) ($sleep * 1_000_000));
                        continue;
                    }

                    throw $e;
                }

                // A frame was read but the message is not complete (or it was a ping/pong).
                if ($deadline !== null && microtime(true) >= $deadline) {
                    throw new WebSocketTimeout('WebSocket receive operation timed out', CurlECode::OPERATION_TIMEDOUT);
                }
            }
        } finally {
            if ($this->closed || $completed) {
                $this->recvChunks = [];
                $this->recvMsgSize = 0;
            } else {
                $this->recvMsgSize = $msgSize;
            }
        }
    }

    /** Receive a text message (checked to be UTF-8 unless skip_utf8_validation). */
    public function recvStr(?float $timeout = null): string
    {
        [$data, $flags] = $this->recv($timeout);
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
    }

    /**
     * Receive a JSON text message. $loads defaults to json_decode(..., true)
     * and throws \JsonException on bad JSON.
     */
    public function recvJson(?callable $loads = null, ?float $timeout = null): mixed
    {
        $data = $this->recvStr($timeout);

        return $loads !== null ? $loads($data) : json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * One raw chunk from libcurl, without waiting: throws CurlError
     * (CurlECode::AGAIN) when nothing is there. Prefer recv().
     *
     * @return array{0: string, 1: CurlWsFrame}
     */
    public function recvFragment(): array
    {
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket is already closed', $this->closeCode ?? WsCloseCode::OK);
        }
        [$chunk, $frame] = $this->curl()->wsRecv();
        if ($frame->flags & CurlWsFlag::CLOSE) {
            $this->handleCloseFrame($chunk);
        }

        return [$chunk, $frame];
    }

    /**
     * Send a message; large ones go out as several frames. Returns the bytes
     * sent. $flags is a CurlWsFlag (BINARY by default, TEXT, PING, ...).
     */
    public function send(string $payload, int $flags = CurlWsFlag::BINARY, ?float $timeout = null): int
    {
        if ($flags & CurlWsFlag::CLOSE) {
            $this->keepRunning = false;
        }
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket is already closed', $this->closeCode ?? WsCloseCode::OK);
        }
        $curl = $this->curl();
        $total = strlen($payload);
        $offset = 0;
        $frameEnd = 0;
        $currentFlags = $flags;

        // Fast path: one frame, one call.
        if ($total <= self::MAX_CURL_FRAME_SIZE) {
            try {
                $offset = $curl->wsSend($payload, $flags);
                if ($offset === $total) {
                    return $offset;
                }
            } catch (CurlError $e) {
                if (!$this->isTransientError($e)) {
                    throw $e;
                }
            }
            $frameEnd = $total;
        }

        $baseFlags = $flags & ~CurlWsFlag::CONT;
        $zeroWrites = 0;
        $this->getSockFd();
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        try {
            while ($offset < $total || ($offset === 0 && $total === 0)) {
                // libcurl needs a resumed write to be exactly the rest of the open frame.
                if ($offset === $frameEnd) {
                    if ($total - $offset > self::MAX_CURL_FRAME_SIZE) {
                        $frameEnd = $offset + self::MAX_CURL_FRAME_SIZE;
                        $currentFlags = $baseFlags | CurlWsFlag::CONT;
                    } else {
                        $frameEnd = $total;
                        $currentFlags = $flags;
                    }
                }
                try {
                    $sent = $curl->wsSend(substr($payload, $offset, $frameEnd - $offset), $currentFlags);
                    if ($sent === 0) {
                        if ($frameEnd - $offset === 0) {
                            return $offset;
                        }
                        if (++$zeroWrites >= self::MAX_ZERO_WRITES) {
                            throw new WebSocketError("Writer stalled ({$zeroWrites} attempts).", CurlECode::WRITE_ERROR);
                        }

                        throw new CurlError('0 bytes sent', CurlECode::AGAIN);
                    }
                    $zeroWrites = 0;
                    $offset += $sent;
                } catch (CurlError $e) {
                    if (!($e instanceof WebSocketError) && $this->isTransientError($e)) {
                        if ($deadline !== null) {
                            $remaining = $deadline - microtime(true);
                            if ($remaining <= 0 || !$this->wait(CurlMulti::WAIT_POLLOUT, $remaining)) {
                                throw new WebSocketTimeout('Socket write timeout', CurlECode::OPERATION_TIMEDOUT);
                            }
                        } else {
                            $this->wait(CurlMulti::WAIT_POLLOUT, null);
                        }
                        continue;
                    }

                    throw $e;
                }
                if ($deadline !== null && $offset < $total && microtime(true) >= $deadline) {
                    throw new WebSocketTimeout('Socket write timeout', CurlECode::OPERATION_TIMEDOUT);
                }
            }
        } catch (\Throwable $e) {
            // A half-sent frame can't be replaced by a later send.
            $this->terminate();

            throw $e;
        }

        return $offset;
    }

    public function sendBinary(string $payload, ?float $timeout = null): int
    {
        return $this->send($payload, CurlWsFlag::BINARY, $timeout);
    }

    /** Alias of sendBinary(). */
    public function sendBytes(string $payload, ?float $timeout = null): int
    {
        return $this->send($payload, CurlWsFlag::BINARY, $timeout);
    }

    public function sendStr(string $payload, ?float $timeout = null): int
    {
        return $this->send($payload, CurlWsFlag::TEXT, $timeout);
    }

    /** Send $payload as JSON text; $dumps defaults to compact json_encode. */
    public function sendJson(mixed $payload, ?callable $dumps = null, ?float $timeout = null): int
    {
        $text = $dumps !== null ? (string) $dumps($payload) : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->send($text, CurlWsFlag::TEXT, $timeout);
    }

    public function ping(string $payload = '', ?float $timeout = null): int
    {
        return $this->send($this->prepareControlPayload($payload), CurlWsFlag::PING, $timeout);
    }

    /** An unsolicited pong; libcurl answers the server's pings by itself. */
    public function pong(string $payload = '', ?float $timeout = null): int
    {
        return $this->send($this->prepareControlPayload($payload), CurlWsFlag::PONG, $timeout);
    }

    /**
     * Read messages and call the callbacks until the connection closes.
     * With $url, connect first (options as in connect()). The connection is
     * always closed when this returns.
     */
    public function runForever(string $url = '', mixed ...$options): void
    {
        if ($this->closed) {
            throw new WebSocketClosed('WebSocket connection is closed.', $this->closeCode ?? WsCloseCode::OK);
        }
        if ($url !== '') {
            $this->connect($url, ...$options);
        }
        $this->getSockFd();
        $this->emit('open');

        $chunks = [];
        $msgSize = 0;
        $this->keepRunning = true;
        $retries = 0;
        $curl = $this->curl();
        $dataMask = CurlWsFlag::BINARY | CurlWsFlag::TEXT | CurlWsFlag::CONT;
        $leaveOpen = false;

        try {
            while ($this->keepRunning) {
                try {
                    [$chunk, $frame] = $curl->wsRecv();
                    $retries = 0;
                    $flags = $frame->flags;
                    $this->emit('data', $chunk, $frame);

                    if ($flags & $dataMask) {
                        $msgSize += strlen($chunk);
                        if ($msgSize > $this->maxMessageSize) {
                            $chunks = [];

                            throw new WebSocketError(
                                "Message too large: {$msgSize} bytes (limit {$this->maxMessageSize} bytes).",
                                WsCloseCode::MESSAGE_TOO_BIG
                            );
                        }
                        $chunks[] = $chunk;
                        if (!($frame->bytesleft === 0 && !($flags & CurlWsFlag::CONT))) {
                            continue;
                        }
                        if (isset($this->emitters['message']) && ($flags & (CurlWsFlag::BINARY | CurlWsFlag::TEXT))) {
                            $message = implode('', $chunks);
                            if (($flags & CurlWsFlag::TEXT) && !$this->skipUtf8Validation && !self::isUtf8($message)) {
                                throw new WebSocketError(self::INVALID_UTF8_MSG, WsCloseCode::INVALID_DATA);
                            }
                            $this->emit('message', $message);
                        }
                        $chunks = [];
                        $msgSize = 0;
                        continue;
                    }
                    if ($flags & CurlWsFlag::CLOSE) {
                        $this->handleCloseFrame($chunk);
                        $this->keepRunning = false;
                        $this->emit('close', $this->closeCode ?? 0, $this->closeReason ?? '');
                        $leaveOpen = !$this->autoclose;
                        break;
                    }
                    // ping / pong: libcurl has handled it
                } catch (CurlError $e) {
                    $code = $e->getCode();
                    if (!($e instanceof WebSocketError) && $code === CurlECode::GOT_NOTHING) {
                        $this->keepRunning = false;
                        $this->closeCode = WsCloseCode::ABNORMAL_CLOSURE;
                        $this->closeReason = 'Connection closed unexpectedly by server (EOF)';
                        $closed = new WebSocketClosed($this->closeReason, $this->closeCode, $e);
                        $this->emit('error', $closed);
                        $this->terminate();
                        $this->emit('close', $this->closeCode, $this->closeReason);

                        throw $closed;
                    }
                    if (!($e instanceof WebSocketError) && $this->isTransientError($e)) {
                        $this->wait(CurlMulti::WAIT_POLLIN, 0.5);
                        continue;
                    }
                    if ($this->keepRunning && $this->wsRetry->retry && in_array($code, $this->wsRetry->codes, true) && $retries < $this->wsRetry->count) {
                        $retries++;
                        usleep((int) ($this->getRetryDelay($retries) * 1_000_000));
                        continue;
                    }
                    $this->emit('error', $e);
                    if (!$this->closed) {
                        $closeCode = $e instanceof WebSocketError ? $code : WsCloseCode::INTERNAL_ERROR;
                        $closeReason = $e instanceof WebSocketError ? $e->getMessage() : '';
                        $this->close($closeCode, $closeReason);
                        $this->emit('close', $this->closeCode ?? $closeCode, $this->closeReason ?? '');
                    }

                    throw $e;
                }
            }
        } finally {
            $this->keepRunning = false;
            if (!($this->closed || $leaveOpen)) {
                $this->terminate();
            }
        }
    }

    /**
     * Send a close frame and shut the connection down. The reason is cut to
     * 123 bytes, the most a close frame can carry.
     */
    public function close(int $code = WsCloseCode::OK, string $message = '', float $timeout = 3.0): void
    {
        if ($this->closed) {
            return;
        }
        if (!self::isUtf8($message)) {
            throw new WebSocketError('Close reason must be valid UTF-8 (RFC 6455 section 5.5.1)', WsCloseCode::INVALID_DATA);
        }
        if (strlen($message) > self::MAX_CLOSE_REASON_SIZE) {
            $message = self::truncateUtf8($message, self::MAX_CLOSE_REASON_SIZE);
        }
        $code = $this->validateCloseCode($code);
        if ($this->closeCode === null) {
            $this->closeCode = $code;
            $this->closeReason = $message;
        }
        try {
            $this->send($this->packCloseFrame($code, $message), CurlWsFlag::CLOSE, $timeout);
        } catch (CurlError) {
        }
        $this->terminate();
    }

    /** Close without a close frame and stop runForever(). */
    public function terminate(): void
    {
        $this->keepRunning = false;
        $this->poller?->close();
        $this->poller = null;
        parent::terminate();
    }

    private function handleCloseFrame(string $message): void
    {
        try {
            [$this->closeCode, $this->closeReason] = $this->unpackCloseFrame($message);
        } catch (WebSocketError $e) {
            $this->closeCode = $e->getCode();
            $this->closeReason = '';
            $this->close($e->getCode());

            throw $e;
        }
        $code = $this->closeCode === WsCloseCode::UNKNOWN ? WsCloseCode::OK : $this->closeCode;
        if ($this->autoclose && !$this->closed) {
            $this->close($code);
        }
    }

    /** Call a callback; its exceptions go to on_error, or become a warning. */
    private function emit(string $event, mixed ...$args): void
    {
        $callback = $this->emitters[$event] ?? null;
        if ($callback === null) {
            return;
        }
        try {
            $callback($this, ...$args);
        } catch (\Throwable $e) {
            $onError = $this->emitters['error'] ?? null;
            if ($onError !== null && $event !== 'error') {
                try {
                    $onError($this, $e);
                } catch (\Throwable $inner) {
                    Warnings::warn("WebSocket 'on_error' callback failed: {$inner->getMessage()}");
                }
            } else {
                Warnings::warn("WebSocket callback '{$event}' failed: {$e->getMessage()}");
            }
        }
    }

    /** Wait until the socket is readable/writable; with no timeout, up to a second at a time. */
    private function wait(int $events, ?float $timeout): bool
    {
        $fd = $this->getSockFd();
        $this->poller ??= new CurlMulti();
        $ms = $timeout === null ? 1000 : (int) ceil(max(0.0, $timeout) * 1000);
        $revents = $this->poller->poll($ms, [[$fd, $events]]);

        return ($revents[0] & $events) !== 0;
    }

    private static function truncateUtf8(string $s, int $bytes): string
    {
        $s = substr($s, 0, $bytes);
        while ($s !== '' && !self::isUtf8($s)) {
            $s = substr($s, 0, -1);
        }

        return $s;
    }
}
