<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * Request items from the command line, sorted by kind, like
 * curl_cffi.cli.ParsedItems.
 */
final class ParsedItems
{
    /**
     * @param array<string, string>             $headers         Header:Value
     * @param list<string>                      $headersToRemove Header: (no value)
     * @param list<array{0: string, 1: string}> $queryParams     param==value
     * @param list<array{0: string, 1: string}> $dataFields      field=value
     * @param list<array{0: string, 1: mixed}>  $jsonFields      field:=json (objects decoded as \stdClass)
     * @param list<array{0: string, 1: string}> $files           @file, as ['file', path]
     * @param array<string, string>             $cookies         +key=value
     */
    public function __construct(
        public array $headers = [],
        public array $headersToRemove = [],
        public array $queryParams = [],
        public array $dataFields = [],
        public array $jsonFields = [],
        public array $files = [],
        public array $cookies = [],
    ) {
    }
}
