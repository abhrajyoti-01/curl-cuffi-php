<?php
// Echo server for integration tests: php -S 127.0.0.1:PORT tests/fixtures/server.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);
    exit;
}
if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo "status {$m[1]}";
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
