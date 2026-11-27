<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

/** Runs `php -S` with tests/fixtures/server.php for the duration of a test class. */
final class LocalServer
{
    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;

    public static function url(string $path = '/'): string
    {
        if (self::$process === null) {
            self::start();
        }

        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private static function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $router = dirname(__DIR__) . '/fixtures/server.php';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$process = proc_open($cmd, [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);

        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);
                register_shutdown_function(static function (): void {
                    if (self::$process !== null) {
                        proc_terminate(self::$process);
                    }
                });

                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Test server did not start');
    }
}
