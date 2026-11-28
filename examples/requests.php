<?php

// php -d ffi.enable=1 examples/requests.php
require __DIR__ . '/../vendor/autoload.php';

use CurlCffi\Requests\Requests;
use CurlCffi\Requests\Session;

// What a fingerprinting site sees: same JA4 / HTTP2 fingerprint as Chrome.
$r = Requests::get('https://tls.peet.ws/api/clean', impersonate: 'chrome');
print_r($r->json());

$s = new Session(impersonate: 'chrome');
$r = $s->get('https://httpbin.org/cookies/set', params: ['hello' => 'world']);
echo 'Cookie stored in the session: ', $s->cookies['hello'], "\n";
echo 'Redirects followed: ', count($r->history), "\n";
