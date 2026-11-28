<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/** One request from an .http file, like curl_cffi.cli.run.HttpFileRequest. */
final class HttpFileRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method = 'GET',
        public string $url = '',
        public array $headers = [],
        public string $body = '',
    ) {
    }
}
