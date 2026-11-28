<?php
// WebSocket test server (RFC 6455): php tests/fixtures/ws_server.php PORT
//
// Echoes every text and binary message, reassembling fragments. Text
// commands trigger other behaviour:
//   "headers"            reply with the handshake request headers as JSON
//   "close" / "close:CODE:REASON"   send a close frame
//   "big:N"              reply with N bytes of "a" as one message in three fragments
//   "burst:N"            reply with N text messages "m0".."m{N-1}"
//   "ping"               send a ping, then "pinged"; "pongs" replies with pongs received
//   "drop"               drop the TCP connection without a close frame
//   "bad-utf8"           reply with a text frame that is not UTF-8
//   "slow:SECONDS"       reply "late" after SECONDS
// The handshake answer sets the cookie ws_cookie=from_ws.

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);

/**
 * @var array<int, array{conn: resource, buf: string, open: bool, headers: array<string, string>,
 *     msg: string, opcode: int, pongs: int, later: list<array{0: float, 1: string}>}>
 */
$clients = [];

function ws_frame(int $opcode, string $payload, bool $fin = true): string
{
    $len = strlen($payload);
    $head = chr(($fin ? 0x80 : 0) | $opcode);
    if ($len < 126) {
        $head .= chr($len);
    } elseif ($len < 65536) {
        $head .= chr(126) . pack('n', $len);
    } else {
        $head .= chr(127) . pack('J', $len);
    }

    return $head . $payload;
}

function ws_write($conn, string $data): void
{
    stream_set_blocking($conn, true);
    $off = 0;
    while ($off < strlen($data)) {
        $n = @fwrite($conn, substr($data, $off));
        if ($n === false || $n === 0) {
            break;
        }
        $off += $n;
    }
    stream_set_blocking($conn, false);
}

/** @return array{0: int, 1: bool, 2: string, 3: int}|null [opcode, fin, payload, frame length] */
function ws_parse(string $buf): ?array
{
    if (strlen($buf) < 2) {
        return null;
    }
    $b0 = ord($buf[0]);
    $b1 = ord($buf[1]);
    $len = $b1 & 0x7F;
    $pos = 2;
    if ($len === 126) {
        if (strlen($buf) < 4) {
            return null;
        }
        $len = unpack('n', substr($buf, 2, 2))[1];
        $pos = 4;
    } elseif ($len === 127) {
        if (strlen($buf) < 10) {
            return null;
        }
        $len = unpack('J', substr($buf, 2, 8))[1];
        $pos = 10;
    }
    $masked = ($b1 & 0x80) !== 0;
    $mask = '';
    if ($masked) {
        if (strlen($buf) < $pos + 4) {
            return null;
        }
        $mask = substr($buf, $pos, 4);
        $pos += 4;
    }
    if (strlen($buf) < $pos + $len) {
        return null;
    }
    $payload = substr($buf, $pos, $len);
    if ($masked && $len > 0) {
        $payload ^= str_repeat($mask, intdiv($len, 4) + 1);
        $payload = substr($payload, 0, $len);
    }

    return [$b0 & 0x0F, ($b0 & 0x80) !== 0, $payload, $pos + $len];
}

function ws_handle(int $id, int $opcode, string $msg): void
{
    global $clients;
    $c = &$clients[$id];
    $conn = $c['conn'];
    if ($opcode === 2) {
        ws_write($conn, ws_frame(2, $msg));

        return;
    }
    if ($msg === 'headers') {
        ws_write($conn, ws_frame(1, json_encode($c['headers'])));
    } elseif ($msg === 'close' || str_starts_with($msg, 'close:')) {
        [, $code, $reason] = array_pad(explode(':', $msg, 3), 3, '');
        ws_write($conn, ws_frame(8, pack('n', $code === '' ? 1000 : (int) $code) . ($msg === 'close' ? 'bye' : $reason)));
    } elseif (str_starts_with($msg, 'big:')) {
        $n = (int) substr($msg, 4);
        $third = intdiv($n, 3);
        ws_write($conn, ws_frame(1, str_repeat('a', $third), false)
            . ws_frame(0, str_repeat('a', $third), false)
            . ws_frame(0, str_repeat('a', $n - 2 * $third), true));
    } elseif (str_starts_with($msg, 'burst:')) {
        $out = '';
        for ($i = 0, $n = (int) substr($msg, 6); $i < $n; $i++) {
            $out .= ws_frame(1, "m{$i}");
        }
        ws_write($conn, $out);
    } elseif ($msg === 'ping') {
        ws_write($conn, ws_frame(9, 'srv') . ws_frame(1, 'pinged'));
    } elseif ($msg === 'pongs') {
        ws_write($conn, ws_frame(1, (string) $c['pongs']));
    } elseif ($msg === 'drop') {
        fclose($conn);
        unset($clients[$id]);
    } elseif ($msg === 'bad-utf8') {
        ws_write($conn, ws_frame(1, "\xff\xfe"));
    } elseif (str_starts_with($msg, 'slow:')) {
        $c['later'][] = [microtime(true) + (float) substr($msg, 5), 'late'];
    } else {
        ws_write($conn, ws_frame(1, $msg));
    }
}

while (true) {
    $read = [$server];
    $timeout = 1.0;
    $now = microtime(true);
    foreach ($clients as $c) {
        $read[] = $c['conn'];
        foreach ($c['later'] as [$at]) {
            $timeout = min($timeout, max(0.0, $at - $now));
        }
    }
    $write = $except = null;
    $sec = (int) $timeout;
    if (@stream_select($read, $write, $except, $sec, (int) (($timeout - $sec) * 1_000_000)) === false) {
        continue;
    }

    $now = microtime(true);
    foreach ($clients as $id => $c) {
        foreach ($c['later'] as $k => [$at, $text]) {
            if ($at <= $now) {
                ws_write($c['conn'], ws_frame(1, $text));
                unset($clients[$id]['later'][$k]);
            }
        }
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            while ($conn = @stream_socket_accept($server, 0)) {
                stream_set_blocking($conn, false);
                $clients[(int) $conn] = ['conn' => $conn, 'buf' => '', 'open' => false, 'headers' => [],
                    'msg' => '', 'opcode' => 0, 'pongs' => 0, 'later' => []];
            }
            continue;
        }
        $id = (int) $sock;
        if (!isset($clients[$id])) {
            continue;
        }
        $chunk = @fread($sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($sock)) {
                fclose($sock);
                unset($clients[$id]);
            }
            continue;
        }
        $clients[$id]['buf'] .= $chunk;

        if (!$clients[$id]['open']) {
            $end = strpos($clients[$id]['buf'], "\r\n\r\n");
            if ($end === false) {
                continue;
            }
            $lines = explode("\r\n", substr($clients[$id]['buf'], 0, $end));
            $clients[$id]['buf'] = (string) substr($clients[$id]['buf'], $end + 4);
            array_shift($lines);
            foreach ($lines as $line) {
                [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
                $clients[$id]['headers'][trim($k)] = trim($v);
            }
            $key = '';
            foreach ($clients[$id]['headers'] as $k => $v) {
                if (strtolower($k) === 'sec-websocket-key') {
                    $key = $v;
                }
            }
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            ws_write($sock, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: {$accept}\r\nSet-Cookie: ws_cookie=from_ws; Path=/\r\n\r\n");
            $clients[$id]['open'] = true;
        }

        while (isset($clients[$id]) && ($frame = ws_parse($clients[$id]['buf'])) !== null) {
            [$opcode, $fin, $payload, $used] = $frame;
            $clients[$id]['buf'] = (string) substr($clients[$id]['buf'], $used);
            if ($opcode === 8) {
                // Echo the close frame, then hang up.
                ws_write($sock, ws_frame(8, $payload));
                fclose($sock);
                unset($clients[$id]);
                break;
            }
            if ($opcode === 9) {
                ws_write($sock, ws_frame(10, $payload));
                continue;
            }
            if ($opcode === 10) {
                $clients[$id]['pongs']++;
                continue;
            }
            if ($opcode !== 0) {
                $clients[$id]['opcode'] = $opcode;
                $clients[$id]['msg'] = '';
            }
            $clients[$id]['msg'] .= $payload;
            if ($fin) {
                ws_handle($id, $clients[$id]['opcode'], $clients[$id]['msg']);
                if (isset($clients[$id])) {
                    $clients[$id]['msg'] = '';
                }
            }
        }
    }
}
