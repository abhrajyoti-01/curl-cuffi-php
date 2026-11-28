<?php
// Concurrent delay server for async timing tests: php tests/fixtures/delay_server.php PORT
//
// GET /delay?s=X answers "done" after X seconds without holding up any other
// connection. `php -S` cannot promise that: one worker may accept two
// connections at once and serve them one after the other, and on Windows it
// has only one worker.
//
// GET /flaky?key=K&fails=N hangs for 3 seconds on the first N calls with key K,
// then answers {"calls": n} at once, for retry tests.

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);

/** @var array<int, array{conn: resource, buf: string, due: ?float, out: string}> */
$clients = [];
/** @var array<string, int> calls to /flaky per key */
$flaky = [];

while (true) {
    $now = microtime(true);
    $read = [$server];
    $write = [];
    $timeout = 1.0;
    foreach ($clients as $id => $c) {
        if ($c['due'] === null) {
            $read[] = $c['conn'];
        } elseif ($c['due'] <= $now) {
            $write[] = $c['conn'];
        } else {
            $timeout = min($timeout, $c['due'] - $now);
        }
    }
    $except = null;
    $sec = (int) $timeout;
    if (@stream_select($read, $write, $except, $sec, (int) (($timeout - $sec) * 1_000_000)) === false) {
        continue;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            while ($conn = @stream_socket_accept($server, 0)) {
                stream_set_blocking($conn, false);
                $clients[(int) $conn] = ['conn' => $conn, 'buf' => '', 'due' => null, 'out' => ''];
            }
            continue;
        }
        $id = (int) $sock;
        $chunk = fread($sock, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($sock)) {
                fclose($sock);
                unset($clients[$id]);
            }
            continue;
        }
        $clients[$id]['buf'] .= $chunk;
        if (!str_contains($clients[$id]['buf'], "\r\n\r\n")) {
            continue;
        }
        $target = explode(' ', strtok($clients[$id]['buf'], "\r\n"))[1] ?? '/';
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        if (parse_url($target, PHP_URL_PATH) === '/flaky') {
            $key = (string) ($query['key'] ?? 'x');
            $calls = $flaky[$key] = ($flaky[$key] ?? 0) + 1;
            $body = json_encode(['calls' => $calls]);
            $type = 'application/json';
            $delay = $calls <= (int) ($query['fails'] ?? 1) ? 3.0 : 0.0;
        } else {
            $body = 'done';
            $type = 'text/plain';
            $delay = max(0.0, (float) ($query['s'] ?? 1));
        }
        $clients[$id]['due'] = microtime(true) + $delay;
        $clients[$id]['out'] = "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }

    $now = microtime(true);
    foreach ($clients as $id => $c) {
        if ($c['due'] !== null && $c['due'] <= $now) {
            stream_set_blocking($c['conn'], true);
            @fwrite($c['conn'], $c['out']);
            fclose($c['conn']);
            unset($clients[$id]);
        }
    }
}
