<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/** The request that produced a Response, like curl_cffi.requests.Request. */
final class Request
{
    public function __construct(
        public readonly string $url,
        public readonly Headers $headers,
        public readonly string $method,
        public readonly ?string $body = null,
    ) {
    }
}
