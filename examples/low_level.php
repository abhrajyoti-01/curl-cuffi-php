<?php

// php -d ffi.enable=1 examples/low_level.php
require __DIR__ . '/../vendor/autoload.php';

use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Curl;

$c = new Curl();
$c->impersonate('chrome'); // newest Chrome target; or 'safari', 'firefox', 'chrome131', ...
$c->setopt(CurlOpt::URL, 'https://tls.peet.ws/api/clean');

$body = '';
$c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body): void {
    $body .= $chunk;
});
$c->perform();

echo 'HTTP ', $c->getinfo(CurlInfo::RESPONSE_CODE), "\n";
echo $body, "\n";
