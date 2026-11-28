<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlWsFlag;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlWsFrame;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Exceptions\RequestException;
use CurlCffi\Requests\Exceptions\WebSocketClosed;
use CurlCffi\Requests\Exceptions\WebSocketError;
use CurlCffi\Requests\Exceptions\WebSocketTimeout;
use CurlCffi\Requests\Session;
use CurlCffi\Requests\WebSocket\AsyncWebSocket;
use CurlCffi\Requests\WebSocket\WebSocket;
use CurlCffi\Requests\WebSocket\WsCloseCode;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket against tests/fixtures/ws_server.php. Needs a libcurl with
 * ws:// support (libcurl-impersonate has it; many distro builds don't).
 */
final class WebSocketTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
        if (!Library::supportsWebSocket()) {
            self::markTestSkipped('The loaded libcurl has no ws:// support (' . Library::loadedPath() . ')');
        }
    }

    private static function url(): string
    {
        return LocalServer::wsUrl();
    }

    public function testEchoTextBinaryAndJson(): void
    {
        $ws = (new Session())->wsConnect(self::url());
        self::assertFalse($ws->closed);

        self::assertSame(5, $ws->sendStr('hello'));
        [$data, $flags] = $ws->recv(timeout: 5);
        self::assertSame('hello', $data);
        self::assertSame(CurlWsFlag::TEXT, $flags & CurlWsFlag::TEXT);

        $ws->sendBinary("\x00\x01\xff");
        [$data, $flags] = $ws->recv(timeout: 5);
        self::assertSame("\x00\x01\xff", $data);
        self::assertSame(CurlWsFlag::BINARY, $flags & CurlWsFlag::BINARY);

        $ws->sendStr('café');
        self::assertSame('café', $ws->recvStr(timeout: 5));

        $ws->sendJson(['a' => 1, 'b' => [true, null]]);
        self::assertSame(['a' => 1, 'b' => [true, null]], $ws->recvJson(timeout: 5));

        $ws->close();
        self::assertTrue($ws->closed);
        self::assertSame(WsCloseCode::OK, $ws->closeCode());
        self::assertSame(WsCloseCode::OK, $ws->close_code, 'Python spelling');
    }

    public function testHandshakeUsesSessionSettings(): void
    {
        $s = new Session(headers: ['X-App' => 'demo'], cookies: ['sid' => 'abc']);
        $ws = $s->wsConnect(self::url(), headers: ['X-Req' => 'one'], params: ['room' => 1]);
        $ws->sendStr('headers');
        $headers = $ws->recvJson(timeout: 5);
        $ws->close();

        self::assertSame('demo', $headers['X-App']);
        self::assertSame('one', $headers['X-Req']);
        self::assertStringContainsString('sid=abc', $headers['Cookie']);
        self::assertSame('websocket', strtolower($headers['Upgrade']));
        self::assertSame('from_ws', $s->cookies['ws_cookie'], 'cookies from the 101 response reach the session');
    }

    public function testLargeAndFragmentedMessages(): void
    {
        $ws = (new Session())->wsConnect(self::url());

        // The server sends one message in three fragments.
        $ws->sendStr('big:200000');
        [$data] = $ws->recv(timeout: 5);
        self::assertSame(str_repeat('a', 200000), $data);

        // Bigger than one libcurl frame (64 KiB): sent as continuation frames.
        $payload = str_repeat('0123456789', 15000);
        self::assertSame(150000, $ws->sendStr($payload));
        self::assertSame($payload, $ws->recvStr(timeout: 5));
        $ws->close();

        $ws = (new Session())->wsConnect(self::url(), max_message_size: 1000);
        $ws->sendStr('big:5000');
        try {
            $ws->recv(timeout: 5);
            self::fail('Expected WebSocketError');
        } catch (WebSocketError $e) {
            self::assertSame(CurlECode::TOO_LARGE, $e->getCode());
        }
        self::assertTrue($ws->closed);
        self::assertSame(WsCloseCode::MESSAGE_TOO_BIG, $ws->closeCode());
    }

    public function testPingsArePongedAutomatically(): void
    {
        $ws = (new Session())->wsConnect(self::url());
        $ws->sendStr('ping');                       // the server pings, then says "pinged"
        self::assertSame('pinged', $ws->recvStr(timeout: 5));
        $ws->sendStr('pongs');
        self::assertSame('1', $ws->recvStr(timeout: 5), 'libcurl answered the ping');

        self::assertGreaterThanOrEqual(0, $ws->ping('hi'));
        $this->expectException(WebSocketError::class);
        $ws->ping(str_repeat('x', 126));
    }

    public function testServerClose(): void
    {
        $ws = (new Session())->wsConnect(self::url());
        $ws->sendStr('close:4001:going away now');
        [$data, $flags] = $ws->recv(timeout: 5);

        self::assertSame(CurlWsFlag::CLOSE, $flags & CurlWsFlag::CLOSE);
        self::assertSame(4001, $ws->closeCode());
        self::assertSame('going away now', $ws->closeReason());
        self::assertTrue($ws->closed, 'autoclose answered the close frame');

        $this->expectException(WebSocketClosed::class);
        $ws->sendStr('too late');
    }

    public function testIterationAndTimeouts(): void
    {
        $ws = (new Session())->wsConnect(self::url());
        try {
            $ws->recv(timeout: 0.2);
            self::fail('Expected WebSocketTimeout');
        } catch (WebSocketTimeout $e) {
            self::assertSame(CurlECode::OPERATION_TIMEDOUT, $e->getCode());
        }
        $ws->sendStr('slow:0.3');
        self::assertSame('late', $ws->recvStr(timeout: 3));

        $ws->sendStr('burst:3');
        $ws->sendStr('close');
        self::assertSame(['m0', 'm1', 'm2'], iterator_to_array($ws, false));
        self::assertSame(1000, $ws->closeCode());
        self::assertSame('bye', $ws->closeReason());
    }

    public function testRunForeverCallbacks(): void
    {
        $events = [];
        $ws = new WebSocket(
            on_open: function (WebSocket $ws) use (&$events): void {
                $events[] = 'open';
                $ws->sendStr('burst:2');
                $ws->sendBinary("\x01");
                $ws->sendStr('close:4000:done');
            },
            on_message: function (WebSocket $ws, string $message) use (&$events): void {
                $events[] = "message {$message}";
            },
            on_data: function (WebSocket $ws, string $chunk, CurlWsFrame $frame) use (&$events): void {
                if ($frame->flags & CurlWsFlag::BINARY) {
                    $events[] = 'data binary';
                }
            },
            on_close: function (WebSocket $ws, int $code, string $reason) use (&$events): void {
                $events[] = "close {$code} {$reason}";
            },
        );
        $ws->runForever(self::url());

        self::assertSame(['open', 'message m0', 'message m1', 'data binary', "message \x01", 'close 4000 done'], $events);
        self::assertTrue($ws->closed);
    }

    public function testCallbackErrorsGoToOnError(): void
    {
        $errors = [];
        $ws = new WebSocket(
            on_open: function (WebSocket $ws): void {
                $ws->sendStr('burst:1');
                $ws->sendStr('close');
            },
            on_message: function (): void {
                throw new \RuntimeException('handler broke');
            },
            on_error: function (WebSocket $ws, \Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        );
        $ws->runForever(self::url());
        self::assertSame(['handler broke'], $errors, 'the loop keeps running after a callback error');
    }

    public function testInvalidUtf8AndDroppedConnection(): void
    {
        $ws = (new Session())->wsConnect(self::url());
        $ws->sendStr('bad-utf8');
        try {
            $ws->recvStr(timeout: 5);
            self::fail('Expected WebSocketError');
        } catch (WebSocketError $e) {
            self::assertSame(WsCloseCode::INVALID_DATA, $e->getCode());
        }
        self::assertTrue($ws->closed);
        self::assertSame(WsCloseCode::INVALID_DATA, $ws->closeCode());

        $ws = (new Session())->wsConnect(self::url());
        $ws->sendStr('drop');
        try {
            $ws->recv(timeout: 5);
            self::fail('Expected an error after the server dropped the connection');
        } catch (CurlError $e) {
            if ($e instanceof WebSocketClosed) {
                self::assertSame(WsCloseCode::ABNORMAL_CLOSURE, $e->getCode());
                self::assertTrue($ws->closed);
            }
        }
    }

    public function testStandaloneConnectAndErrors(): void
    {
        $ws = (new WebSocket())->connect(self::url(), headers: ['X-Solo' => 'yes']);
        $ws->sendStr('headers');
        self::assertSame('yes', $ws->recvJson(timeout: 5)['X-Solo']);
        $ws->close(WsCloseCode::GOING_AWAY, str_repeat('é', 100));
        self::assertSame(WsCloseCode::GOING_AWAY, $ws->closeCode());
        self::assertLessThanOrEqual(123, strlen((string) $ws->closeReason()), 'reason cut to fit a control frame');

        $this->expectException(RequestException::class);
        (new Session())->wsConnect('ws://127.0.0.1:1/');
    }

    public function testAsyncWebSocket(): void
    {
        $s = new AsyncSession();
        $result = $s->run(function () use ($s): array {
            $ws = $s->wsConnect(self::url(), headers: ['X-Async' => '1'])->await();
            self::assertInstanceOf(AsyncWebSocket::class, $ws);

            $ws->sendStr('hello')->await();
            [$data, $flags] = $ws->recv(timeout: 5)->await();
            self::assertSame('hello', $data);
            self::assertSame(CurlWsFlag::TEXT, $flags & CurlWsFlag::TEXT);

            $ws->sendJson(['x' => 1]);                     // queued, not awaited
            self::assertSame(['x' => 1], $ws->recvJson(timeout: 5)->await());

            $payload = str_repeat('z', 150000);
            $ws->sendStr($payload);
            self::assertSame($payload, $ws->recvStr(timeout: 5)->await());

            try {
                $ws->recv(timeout: 0.2)->await();
                self::fail('Expected WebSocketTimeout');
            } catch (WebSocketTimeout) {
            }

            $ws->sendStr('burst:3');
            $ws->sendStr('close:4002:async bye');
            $messages = iterator_to_array($ws, false);

            return [$messages, $ws->closeCode(), $ws->closeReason(), $ws->closed];
        });
        self::assertSame([['m0', 'm1', 'm2'], 4002, 'async bye', true], $result);
    }

    public function testAsyncSocketsRunConcurrently(): void
    {
        $s = new AsyncSession();
        $wsUrl = self::url();               // start both servers outside the timed part
        $delayUrl = LocalServer::delayUrl(0.5);
        $start = microtime(true);
        $results = $s->run(function () use ($s, $wsUrl, $delayUrl): array {
            $talk = function (string $name) use ($s, $wsUrl): string {
                $ws = $s->wsConnect($wsUrl)->await();
                $ws->sendStr('slow:0.5');
                $late = $ws->recvStr(timeout: 5)->await();
                $ws->close()->await();

                return "{$name}:{$late}";
            };

            return $s->gather([
                'a' => fn () => $talk('a'),
                'b' => fn () => $talk('b'),
                'http' => $s->get($delayUrl),
            ]);
        });
        $elapsed = microtime(true) - $start;

        self::assertSame('a:late', $results['a']);
        self::assertSame('b:late', $results['b']);
        self::assertSame('done', $results['http']->text);
        self::assertLessThan(1.2, $elapsed, 'two sockets and a request wait at the same time');
    }

    public function testAsyncCloseAndTerminate(): void
    {
        $s = new AsyncSession();
        $ws = $s->wsConnect(self::url())->await();
        $pending = $ws->recv();
        $ws->close(WsCloseCode::OK, 'bye')->await();
        self::assertTrue($ws->closed);
        try {
            $pending->await();
            self::fail('Expected WebSocketClosed');
        } catch (WebSocketClosed) {
        }

        $ws = $s->wsConnect(self::url())->await();
        $waiting = $ws->recv();
        $s->close();
        self::assertTrue($ws->closed, 'closing the session closes its sockets');
        self::assertTrue($waiting->isRejected());
    }
}
