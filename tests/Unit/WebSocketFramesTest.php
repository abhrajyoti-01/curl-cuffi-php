<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Requests\Exceptions\WebSocketClosed;
use CurlCffi\Requests\Exceptions\WebSocketError;
use CurlCffi\Requests\WebSocket\BaseWebSocket;
use CurlCffi\Requests\WebSocket\WebSocket;
use CurlCffi\Requests\WebSocket\WebSocketRetryStrategy;
use CurlCffi\Requests\WebSocket\WsCloseCode;
use CurlCffi\Curl\CurlError;
use PHPUnit\Framework\TestCase;

/** Close frames, limits and options, which need no connection. */
final class WebSocketFramesTest extends TestCase
{
    public function testCloseFrames(): void
    {
        $ws = new FrameProbe();
        self::assertSame("\x03\xe8bye", $ws->pack(1000, 'bye'));
        self::assertSame([1000, 'bye'], $ws->unpack("\x03\xe8bye"));
        self::assertSame([4001, 'café'], $ws->unpack(pack('n', 4001) . 'café'));
        self::assertSame([WsCloseCode::UNKNOWN, ''], $ws->unpack(''), 'an empty close frame has no code');

        foreach ([
            "\x03" => WsCloseCode::PROTOCOL_ERROR,                 // 1 byte
            pack('n', 1005) => WsCloseCode::PROTOCOL_ERROR,        // reserved, not for the wire
            pack('n', 999) => WsCloseCode::PROTOCOL_ERROR,
            pack('n', 1000) . "\xff" => WsCloseCode::INVALID_DATA, // reason not UTF-8
        ] as $frame => $code) {
            try {
                $ws->unpack((string) $frame);
                self::fail('Expected WebSocketError for ' . bin2hex((string) $frame));
            } catch (WebSocketError $e) {
                self::assertSame($code, $e->getCode());
            }
        }

        self::assertSame(1000, $ws->validate(1000));
        self::assertSame(4999, $ws->validate(4999));
        self::assertSame(WsCloseCode::INTERNAL_ERROR, $ws->validate(1006), 'locally generated 1006 is not sent');
        self::assertSame(WsCloseCode::OK, $ws->closeState(''), 'answer an empty close with 1000');
        self::assertSame(WsCloseCode::UNKNOWN, $ws->closeCode());
    }

    public function testTransientErrorsAndRetryDelay(): void
    {
        $ws = new FrameProbe(wsRetry: new WebSocketRetryStrategy(retry: true, delay: 1.0));
        self::assertTrue($ws->transient(new CurlError('again', CurlECode::AGAIN)));
        self::assertTrue($ws->transient(new CurlError('recv failure: Resource temporarily unavailable', CurlECode::RECV_ERROR)));
        self::assertFalse($ws->transient(new CurlError('recv failure: connection reset', CurlECode::RECV_ERROR)));
        self::assertFalse($ws->transient(new CurlError('timeout', CurlECode::OPERATION_TIMEDOUT)));

        foreach ([1 => 1.0, 2 => 2.0, 3 => 4.0] as $attempt => $base) {
            $d = $ws->retryDelay($attempt);
            self::assertGreaterThanOrEqual($base * 0.9, $d);
            self::assertLessThanOrEqual($base * 1.1, $d);
        }
    }

    public function testOptionsAndClosedState(): void
    {
        $ws = new WebSocket(autoclose: false, skipUtf8Validation: true, max_message_size: 10);
        self::assertFalse($ws->autoclose);
        self::assertTrue($ws->skip_utf8_validation);
        self::assertSame('<WebSocket closed=False fd=-1 close_code=None>', (string) $ws);

        $ws->terminate();
        $this->expectException(WebSocketClosed::class);
        $ws->recv();
    }

    public function testUnknownOption(): void
    {
        $this->expectException(\TypeError::class);
        new WebSocket(on_mesage: fn () => null);
    }

    public function testControlFrameLimit(): void
    {
        $this->expectException(WebSocketError::class);
        (new FrameProbe())->control(str_repeat('x', BaseWebSocket::MAX_CONTROL_FRAME_SIZE + 1));
    }
}

/** Exposes BaseWebSocket's helpers to the test. */
final class FrameProbe extends BaseWebSocket
{
    public function pack(int $code, string $reason): string
    {
        return $this->packCloseFrame($code, $reason);
    }

    /** @return array{0: int, 1: string} */
    public function unpack(string $frame): array
    {
        return $this->unpackCloseFrame($frame);
    }

    public function validate(int $code): int
    {
        return $this->validateCloseCode($code);
    }

    public function closeState(string $frame): int
    {
        return $this->setCloseState($frame);
    }

    public function transient(CurlError $e): bool
    {
        return $this->isTransientError($e);
    }

    public function retryDelay(int $attempt): float
    {
        return $this->getRetryDelay($attempt);
    }

    public function control(string $payload): string
    {
        return $this->prepareControlPayload($payload);
    }
}
