<?php

// php -d ffi.enable=1 examples/websocket.php
require __DIR__ . '/../vendor/autoload.php';

use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Session;
use CurlCffi\Requests\WebSocket\WebSocket;

$url = 'wss://echo.websocket.org';

// Sync: send, receive, close. The handshake looks like Chrome's.
$ws = (new Session(impersonate: 'chrome'))->wsConnect($url);
$ws->recvStr(timeout: 10);                 // echo.websocket.org greets first
$ws->sendStr('hello from PHP');
echo 'echo: ', $ws->recvStr(timeout: 10), "\n";
$ws->close();

// Callbacks
$count = 0;
$ws = new WebSocket(
    on_open: fn (WebSocket $ws) => $ws->sendStr('ping 1'),
    on_message: function (WebSocket $ws, string $message) use (&$count): void {
        echo "got: {$message}\n";
        if (++$count >= 2) {
            $ws->close();
        }
    },
);
$ws->runForever($url, impersonate: 'chrome');

// Async: two sockets at once
$s = new AsyncSession(impersonate: 'chrome');
print_r($s->run(fn () => $s->gather(array_map(fn (int $i) => function () use ($s, $url, $i): string {
    $ws = $s->wsConnect($url)->await();
    $ws->recvStr(timeout: 10)->await();
    $ws->sendStr("async {$i}")->await();
    $reply = $ws->recvStr(timeout: 10)->await();
    $ws->close()->await();

    return $reply;
}, [1, 2]))));
