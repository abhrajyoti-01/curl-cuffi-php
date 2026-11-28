<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Async\Promise;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Exceptions\ConnectionError;
use CurlCffi\Requests\Exceptions\HTTPError;
use CurlCffi\Requests\Exceptions\SessionClosed;
use CurlCffi\Requests\Exceptions\Timeout;
use CurlCffi\Requests\Response;
use CurlCffi\Requests\RetryStrategy;
use PHPUnit\Framework\TestCase;

final class AsyncSessionTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    private static function url(string $path): string
    {
        return LocalServer::url($path);
    }

    public function testAwaitOneRequest(): void
    {
        $s = new AsyncSession();
        $p = $s->get(self::url('/echo'), params: ['a' => 1], headers: ['X-Foo' => 'bar']);
        self::assertInstanceOf(Promise::class, $p);
        self::assertTrue($p->isPending(), 'nothing runs until the loop does');

        $r = $p->await();
        self::assertInstanceOf(Response::class, $r);
        self::assertSame(200, $r->statusCode);
        self::assertSame(['a' => '1'], $r->json()['query']);
        self::assertSame('bar', $r->json()['headers']['X-Foo']);
        self::assertSame('GET', $r->request?->method);
        self::assertSame($r, $p->await(), 'awaiting again returns the same response');

        $r = $s->post(self::url('/echo'), json: ['x' => 1])->await();
        self::assertSame('POST', $r->json()['method']);
        self::assertSame('{"x":1}', $r->json()['body']);
    }

    public function testRequestsRunConcurrently(): void
    {
        $s = new AsyncSession();
        $start = microtime(true);
        $rs = $s->gather(array_map(fn () => $s->get(self::url('/delay?s=0.5')), range(1, 5)));
        $elapsed = microtime(true) - $start;

        self::assertCount(5, $rs);
        foreach ($rs as $r) {
            self::assertSame('done', $r->text);
        }
        self::assertLessThan(2.0, $elapsed, 'five 0.5s requests should overlap, not take 2.5s');
    }

    public function testMaxClientsLimitsConcurrency(): void
    {
        $s = new AsyncSession(max_clients: 2);
        self::assertSame(2, $s->maxClients);
        $start = microtime(true);
        $rs = $s->gather(array_map(fn () => $s->get(self::url('/delay?s=0.4')), range(1, 4)));
        $elapsed = microtime(true) - $start;

        self::assertCount(4, $rs);
        self::assertGreaterThan(0.75, $elapsed, 'four requests, two at a time, take two rounds');

        $this->expectException(\InvalidArgumentException::class);
        new AsyncSession(maxClients: 0);
    }

    public function testGatherKeepsKeysAndCanReturnExceptions(): void
    {
        $s = new AsyncSession();
        $rs = $s->gather([
            'ok' => $s->get(self::url('/echo?n=1')),
            'down' => $s->get('http://127.0.0.1:1/'),
            'plain' => 42,
        ], returnExceptions: true);

        self::assertSame(['ok', 'down', 'plain'], array_keys($rs));
        self::assertSame(200, $rs['ok']->statusCode);
        self::assertInstanceOf(ConnectionError::class, $rs['down']);
        self::assertSame(42, $rs['plain']);

        $this->expectException(ConnectionError::class);
        $s->gather([$s->get(self::url('/echo')), $s->get('http://127.0.0.1:1/')]);
    }

    public function testCoroutines(): void
    {
        $s = new AsyncSession();
        $task = function (string $name) use ($s): string {
            $first = $s->get(self::url('/delay?s=0.5'))->await();
            $s->sleep(0.05)->await();
            $second = $s->get(self::url('/echo'), params: ['name' => $name])->await();

            return $first->text . ':' . $second->json()['query']['name'];
        };

        $start = microtime(true);
        $results = $s->run(function () use ($s, $task): array {
            return $s->gather(['a' => fn () => $task('a'), 'b' => $s->spawn($task, 'b')]);
        });
        $elapsed = microtime(true) - $start;

        self::assertSame(['a' => 'done:a', 'b' => 'done:b'], $results);
        self::assertLessThan(0.9, $elapsed, 'the two coroutines wait at the same time (one after the other takes 1.1s)');
    }

    public function testExceptionsInsideCoroutines(): void
    {
        $s = new AsyncSession();
        $caught = $s->run(function () use ($s): string {
            try {
                $s->get(self::url('/delay?s=2'), timeout: 0.2)->await();

                return 'no error';
            } catch (Timeout $e) {
                return 'timeout ' . $e->getCode();
            }
        });
        self::assertSame('timeout 28', $caught);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $s->run(function () use ($s): void {
            $s->sleep(0.01)->await();

            throw new \RuntimeException('boom');
        });
    }

    public function testThenChains(): void
    {
        $s = new AsyncSession();
        $code = $s->get(self::url('/status/201'))
            ->then(fn (Response $r) => $r->statusCode)
            ->then(fn (int $c) => $c + 1)
            ->await();
        self::assertSame(202, $code);

        $message = $s->get('http://127.0.0.1:1/')
            ->then(fn () => 'not reached')
            ->catch(fn (\Throwable $e) => get_class($e))
            ->await();
        self::assertSame(ConnectionError::class, $message);
    }

    public function testRaiseForStatus(): void
    {
        $s = new AsyncSession(raise_for_status: true);
        self::assertSame(200, $s->get(self::url('/echo'))->await()->statusCode);

        try {
            $s->get(self::url('/status/404'))->await();
            self::fail('Expected HTTPError');
        } catch (HTTPError $e) {
            self::assertSame(404, $e->response?->statusCode);
        }
    }

    public function testRetry(): void
    {
        $key = bin2hex(random_bytes(6));
        $s = new AsyncSession(retry: new RetryStrategy(count: 1, delay: 0.05), timeout: 0.5);
        $r = $s->get(self::url("/flaky?key={$key}&fails=1"))->await();
        self::assertSame(['calls' => 2], $r->json(), 'the first call timed out, the retry succeeded');

        $s = new AsyncSession(retry: 2);
        $start = microtime(true);
        $rs = $s->gather([$s->get('http://127.0.0.1:1/'), $s->get(self::url('/echo'))], returnExceptions: true);
        self::assertInstanceOf(ConnectionError::class, $rs[0]);
        self::assertSame(200, $rs[1]->statusCode);
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testCookiesAreShared(): void
    {
        $s = new AsyncSession();
        $s->get(self::url('/cookies/set?a=1&b=2'))->await();
        self::assertSame('1', $s->cookies['a']);

        $rs = $s->gather([$s->get(self::url('/cookies')), $s->get(self::url('/cookies'))]);
        foreach ($rs as $r) {
            self::assertEqualsCanonicalizing(['a' => '1', 'b' => '2'], $r->json()['cookies']);
        }
    }

    public function testStreaming(): void
    {
        $s = new AsyncSession();
        $r = $s->stream('GET', self::url('/stream?n=3'))->await();
        self::assertSame(200, $r->statusCode);
        self::assertSame(['line 0', 'line 1', 'line 2'], iterator_to_array($r->iterLines(), false));

        $r = $s->get(self::url('/stream?n=2'), stream: true)->await();
        self::assertSame("line 0\nline 1\n", $r->read());
    }

    public function testHandlesAreReused(): void
    {
        $s = new AsyncSession(max_clients: 3);
        for ($round = 0; $round < 3; $round++) {
            $rs = $s->gather(array_map(fn ($i) => $s->get(self::url("/echo?i={$i}")), range(1, 6)));
            self::assertSame(['1', '2', '3', '4', '5', '6'], array_map(fn (Response $r) => $r->json()['query']['i'], $rs));
        }
        $pool = (fn () => count($this->idle))->call($s);
        self::assertSame(3, $pool, 'never more handles than max_clients');
    }

    public function testClosedSessionAndBadCalls(): void
    {
        $s = new AsyncSession();
        try {
            $s->get(self::url('/echo'), nope: 1);
            self::fail('Expected TypeError');
        } catch (\TypeError $e) {
            self::assertStringContainsString("unknown option 'nope'", $e->getMessage());
        }
        try {
            $s->fetch(self::url('/echo'));
            self::fail('Expected BadMethodCallException');
        } catch (\BadMethodCallException) {
        }
        $s->close();
        $this->expectException(SessionClosed::class);
        $s->get(self::url('/echo'));
    }

    public function testCloseRejectsUnfinishedRequests(): void
    {
        $s = new AsyncSession(max_clients: 1);
        $running = $s->get(self::url('/delay?s=2'));
        $queued = $s->get(self::url('/echo'));
        $s->loop()->tick();
        $start = microtime(true);
        $s->close();

        foreach ([$running, $queued] as $p) {
            try {
                $p->await();
                self::fail('Expected SessionClosed');
            } catch (SessionClosed $e) {
                self::assertStringContainsString('closed before the request finished', $e->getMessage());
            }
        }
        self::assertLessThan(0.5, microtime(true) - $start);
    }
}
