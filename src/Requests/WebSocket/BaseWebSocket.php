<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Requests\Exceptions\WebSocketError;

/**
 * Shared by WebSocket and AsyncWebSocket: state, close-frame handling and
 * limits, as in Python's BaseWebSocket.
 *
 * @property-read ?int    $close_code   Python spelling of closeCode()
 * @property-read ?string $close_reason Python spelling of closeReason()
 */
abstract class BaseWebSocket
{
    public const MAX_CURL_FRAME_SIZE = 65536;
    public const MAX_CONTROL_FRAME_SIZE = 125;
    public const MAX_CLOSE_REASON_SIZE = self::MAX_CONTROL_FRAME_SIZE - 2;
    public const CLOSE_NOTIFY_SECS = 0.5;
    /** perform() cannot be interrupted, so an unlimited handshake timeout is clamped to this. */
    public const MAX_HANDSHAKE_SECS = 30.0;

    protected const INVALID_UTF8_MSG = 'Invalid UTF-8 in text frame';
    protected const DEFINED_CLOSE_CODES = [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014];
    protected const MAX_ZERO_WRITES = 3;
    private const TRANSIENT_PATTERNS = [
        'errno 11', 'errno 35', 'errno 10035', 'resource temporarily unavailable', 'call would block',
    ];

    public bool $closed = false;
    public bool $autoclose;
    public bool $skipUtf8Validation;
    public bool $debug;
    public WebSocketRetryStrategy $wsRetry;

    protected int $maxMessageSize;
    protected ?int $closeCode = null;
    protected ?string $closeReason = null;
    protected int $sockFd = -1;
    protected ?Curl $curl;

    public function __construct(
        ?Curl $curl = null,
        bool $autoclose = true,
        bool $skipUtf8Validation = false,
        bool $debug = false,
        ?WebSocketRetryStrategy $wsRetry = null,
        int $maxMessageSize = 4 * 1024 * 1024,
    ) {
        $this->curl = $curl;
        $this->autoclose = $autoclose;
        $this->skipUtf8Validation = $skipUtf8Validation;
        $this->debug = $debug;
        $this->wsRetry = $wsRetry ?? new WebSocketRetryStrategy();
        $this->maxMessageSize = $maxMessageSize;
    }

    /** The Curl handle of this connection. */
    public function curl(): Curl
    {
        return $this->curl ??= new Curl(debug: $this->debug);
    }

    /** The close code, once the connection is closed. */
    public function closeCode(): ?int
    {
        return $this->closeCode;
    }

    /** The close reason, once the connection is closed. */
    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'close_code' => $this->closeCode,
            'close_reason' => $this->closeReason,
            'skip_utf8_validation' => $this->skipUtf8Validation,
            'ws_retry' => $this->wsRetry,
            default => throw new \Error('Undefined property: ' . static::class . '::$' . $name),
        };
    }

    public function __toString(): string
    {
        return sprintf(
            '<%s closed=%s fd=%d close_code=%s>',
            (new \ReflectionClass($this))->getShortName(),
            $this->closed ? 'True' : 'False',
            $this->sockFd,
            $this->closeCode === null ? 'None' : (string) $this->closeCode,
        );
    }

    /** Close the connection without a close frame. */
    public function terminate(): void
    {
        $this->closed = true;
        $this->sockFd = -1;
        $this->curl?->close();
    }

    protected function getSockFd(): int
    {
        if ($this->sockFd !== -1) {
            return $this->sockFd;
        }
        $fd = $this->curl()->getinfo(CurlInfo::ACTIVESOCKET);
        if (!is_int($fd) || $fd === -1) {
            throw new WebSocketError('Invalid active socket', CurlECode::NO_CONNECTION_AVAILABLE);
        }

        return $this->sockFd = $fd;
    }

    protected function prepareControlPayload(string $payload): string
    {
        if (strlen($payload) > self::MAX_CONTROL_FRAME_SIZE) {
            throw new WebSocketError('Control frame has invalid length: ' . strlen($payload), CurlECode::TOO_LARGE);
        }

        return $payload;
    }

    /** RFC 6455 section 7.4 close code range check. */
    protected function isValidCloseCode(int $code): bool
    {
        return in_array($code, self::DEFINED_CLOSE_CODES, true) || ($code >= 3000 && $code < 5000);
    }

    /** A locally generated close code that may be sent. */
    protected function validateCloseCode(int $code): int
    {
        return $this->isValidCloseCode($code) ? $code : WsCloseCode::INTERNAL_ERROR;
    }

    protected function packCloseFrame(int $code, string $reason): string
    {
        return pack('n', $code) . $reason;
    }

    /** @return array{0: int, 1: string} close code and reason */
    protected function unpackCloseFrame(string $frame): array
    {
        if ($frame === '') {
            return [WsCloseCode::UNKNOWN, ''];
        }
        if (strlen($frame) === 1) {
            throw new WebSocketError('Close frame body cannot be 1 byte (RFC 6455 section 5.5.1)', WsCloseCode::PROTOCOL_ERROR);
        }
        $code = unpack('n', $frame)[1];
        $reason = substr($frame, 2);
        if (!self::isUtf8($reason)) {
            throw new WebSocketError('Invalid close message', WsCloseCode::INVALID_DATA);
        }
        if (!$this->isValidCloseCode($code)) {
            throw new WebSocketError("Invalid close status code received on wire: {$code}", WsCloseCode::PROTOCOL_ERROR);
        }

        return [$code, $reason];
    }

    /** Record the peer's close frame; returns the code to answer with. */
    protected function setCloseState(string $frame): int
    {
        try {
            [$this->closeCode, $this->closeReason] = $this->unpackCloseFrame($frame);
        } catch (WebSocketError $e) {
            $this->closeCode = $e->getCode();
            $this->closeReason = '';
        }

        return $this->closeCode === WsCloseCode::UNKNOWN ? WsCloseCode::OK : $this->closeCode;
    }

    /** Whether $e only means "the socket would block" (EAGAIN). */
    protected function isTransientError(CurlError $e): bool
    {
        $code = $e->getCode();
        if ($code === CurlECode::AGAIN) {
            return true;
        }
        if ($code === CurlECode::RECV_ERROR || $code === CurlECode::SEND_ERROR) {
            // With CONNECT_ONLY, BoringSSL can report a blocked socket as a
            // receive/send error with the errno in the message.
            $message = strtolower($e->getMessage());
            foreach (self::TRANSIENT_PATTERNS as $pattern) {
                if (str_contains($message, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** delay * 2^(attempt - 1), ±10% */
    protected function getRetryDelay(int $attempt): float
    {
        $delay = $this->wsRetry->delay * (2 ** ($attempt - 1));
        $jitter = $delay * 0.1;

        return max(0.0, $delay + (mt_rand() / mt_getrandmax() * 2 - 1) * $jitter);
    }

    protected static function isUtf8(string $data): bool
    {
        return preg_match('//u', $data) === 1;
    }
}
