<?php
// Echo server for integration tests: php -S 127.0.0.1:PORT tests/fixtures/server.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);
    exit;
}
if (preg_match('#^/redirect/(\d+)$#', $path, $m)) {
    $n = (int) $m[1];
    header('Location: ' . ($n > 1 ? '/redirect/' . ($n - 1) : '/echo'), true, 302);
    exit;
}
if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo "status {$m[1]}";
    exit;
}
if ($path === '/cookies/set') {
    foreach ($_GET as $name => $value) {
        setcookie($name, $value, ['path' => '/']);
    }
    header('Location: /cookies', true, 302);
    exit;
}
if ($path === '/cookies/delete') {
    foreach ($_GET as $name => $value) {
        setcookie($name, '', ['expires' => 1, 'path' => '/']);
    }
    header('Location: /cookies', true, 302);
    exit;
}
if ($path === '/cookies') {
    header('Content-Type: application/json');
    echo json_encode(['cookies' => (object) $_COOKIE]);
    exit;
}
if ($path === '/stream') {
    $n = (int) ($_GET['n'] ?? 5);
    header('Content-Type: text/plain');
    for ($i = 0; $i < $n; $i++) {
        echo "line {$i}\n";
        @ob_flush();
        flush();
        usleep(20_000);
    }
    exit;
}
if ($path === '/delay') {
    usleep((int) (((float) ($_GET['s'] ?? 1)) * 1_000_000));
    echo 'done';
    exit;
}
if ($path === '/latin1') {
    header('Content-Type: text/plain; charset=iso-8859-1');
    echo "caf\xe9";
    exit;
}
if ($path === '/upload') {
    header('Content-Type: application/json');
    $files = [];
    foreach ($_FILES as $field => $f) {
        $files[$field] = [
            'name' => $f['name'],
            'type' => $f['type'],
            'content' => file_get_contents($f['tmp_name']),
        ];
    }
    echo json_encode(['post' => (object) $_POST, 'files' => (object) $files], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

header('Content-Type: application/json');
header('X-Test: yes');
setcookie('session', 'abc123');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_GET,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => file_get_contents('php://input'),
], JSON_INVALID_UTF8_SUBSTITUTE);
