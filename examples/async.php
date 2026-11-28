<?php

// php -d ffi.enable=1 examples/async.php
require __DIR__ . '/../vendor/autoload.php';

use CurlCffi\Requests\AsyncSession;
use CurlCffi\Requests\Response;

$s = new AsyncSession(impersonate: 'chrome', max_clients: 5);

// Like: await asyncio.gather(*[s.get(url) for url in urls])
$urls = [
    'peet' => 'https://tls.peet.ws/api/clean',
    'httpbin' => 'https://httpbin.org/get',
    'example' => 'https://example.com/',
];
$start = microtime(true);
$responses = $s->gather(array_map(fn (string $u) => $s->get($u), $urls), returnExceptions: true);
foreach ($responses as $name => $r) {
    echo str_pad($name, 8), $r instanceof Response ? $r->statusCode : get_class($r), "\n";
}
printf("%d requests in %.2fs\n", count($urls), microtime(true) - $start);

// Coroutines: each awaits its own steps while the others keep running.
$fetchTwice = function (string $url) use ($s): string {
    $first = $s->get($url)->await();
    $s->sleep(0.2)->await();
    $second = $s->get($url)->await();

    return "{$first->statusCode} then {$second->statusCode}";
};
print_r($s->run(fn () => $s->gather([
    fn () => $fetchTwice('https://httpbin.org/status/200'),
    fn () => $fetchTwice('https://httpbin.org/status/204'),
])));
