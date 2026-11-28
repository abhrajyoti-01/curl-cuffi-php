<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

/**
 * Local servers for the integration tests, started on first use and stopped
 * at shutdown:
 *  - url(): `php -S` with tests/fixtures/server.php (echo, cookies, redirects, ...)
 *  - delayUrl() / flakyUrl(): tests/fixtures/delay_server.php, which serves
 *    any number of requests at the same time, for timing and retry tests
 *  - wsUrl(): tests/fixtures/ws_server.php, a WebSocket echo server
 */
final class LocalServer
{
    /** @var array<string, int> server name => port */
    private static array $ports = [];

    /** @var list<resource> */
    private static array $processes = [];

    public static function url(string $path = '/'): string
    {
        $fixture = dirname(__DIR__) . '/fixtures/server.php';
        // Several workers where supported (not on Windows). One worker can
        // still serve two connections one after the other, so timing and
        // retry tests use delayUrl() and flakyUrl() instead.
        $port = self::$ports['echo'] ??= self::start(
            static fn (int $port) => [PHP_BINARY, '-S', '127.0.0.1:' . $port, $fixture],
            ['PHP_CLI_SERVER_WORKERS' => '8'],
        );

        return 'http://127.0.0.1:' . $port . $path;
    }

    /** URL of `/delay?s=$seconds` on a server that answers all requests concurrently. */
    public static function delayUrl(float $seconds): string
    {
        $fixture = dirname(__DIR__) . '/fixtures/delay_server.php';
        $port = self::$ports['delay'] ??= self::start(static fn (int $port) => [PHP_BINARY, $fixture, (string) $port]);

        return 'http://127.0.0.1:' . $port . '/delay?s=' . $seconds;
    }

    /** URL that hangs for 3 seconds on the first $fails calls with $key, then answers {"calls": n}. */
    public static function flakyUrl(string $key, int $fails = 1): string
    {
        $fixture = dirname(__DIR__) . '/fixtures/delay_server.php';
        $port = self::$ports['delay'] ??= self::start(static fn (int $port) => [PHP_BINARY, $fixture, (string) $port]);

        return 'http://127.0.0.1:' . $port . '/flaky?key=' . rawurlencode($key) . '&fails=' . $fails;
    }

    /** ws:// URL on tests/fixtures/ws_server.php, an RFC 6455 echo server. */
    public static function wsUrl(string $path = '/ws'): string
    {
        $fixture = dirname(__DIR__) . '/fixtures/ws_server.php';
        $port = self::$ports['ws'] ??= self::start(static fn (int $port) => [PHP_BINARY, $fixture, (string) $port]);

        return 'ws://127.0.0.1:' . $port . $path;
    }

    /**
     * @param \Closure(int): list<string> $command
     * @param array<string, string>       $env
     */
    private static function start(\Closure $command, array $env = []): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command($port), [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, getenv() + $env);
        if ($process === false) {
            throw new \RuntimeException('Could not start test server');
        }
        if (self::$processes === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$processes as $p) {
                    proc_terminate($p);
                }
            });
        }
        self::$processes[] = $process;

        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);

                return $port;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Test server did not start');
    }
}
