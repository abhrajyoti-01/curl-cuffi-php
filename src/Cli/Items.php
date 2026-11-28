<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * URL shortcuts and request items of the curl-cffi command, like
 * curl_cffi.cli.parse.
 */
final class Items
{
    /** Methods the command has a subcommand for, like SUPPORTED_METHODS. */
    public const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE', 'PATCH', 'QUERY'];

    /**
     * ":8080/x" means http://localhost:8080/x. With no scheme, https is
     * assumed, or http when an explicit port other than 443 is given.
     */
    public static function processUrl(string $url): string
    {
        if (str_starts_with($url, ':')) {
            return "http://localhost{$url}";
        }
        if (!str_contains($url, '://')) {
            $host = explode('/', $url, 2)[0];
            if (str_contains($host, ':')) {
                $port = substr($host, strrpos($host, ':') + 1);
                $scheme = $port === '443' ? 'https' : 'http';
            } else {
                $scheme = 'https';
            }

            return "{$scheme}://{$url}";
        }

        return $url;
    }

    /**
     * Sort request items, first match wins:
     *
     *     +key=value   cookie
     *     field:=json  raw JSON field
     *     param==value query parameter
     *     field=value  data field
     *     @file        file upload
     *     Header:Value header ("Header:" removes it)
     *
     * @param list<string> $items
     *
     * @throws CliError for an item that fits none, or invalid JSON
     */
    public static function parse(array $items): ParsedItems
    {
        $result = new ParsedItems();
        foreach ($items as $item) {
            if (str_starts_with($item, '+') && str_contains(substr($item, 1), '=')) {
                [$key, $value] = explode('=', substr($item, 1), 2);
                $result->cookies[$key] = $value;
            } elseif (str_contains($item, ':=')) {
                [$key, $value] = explode(':=', $item, 2);
                try {
                    $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new CliError("invalid JSON in '{$item}': {$e->getMessage()}");
                }
                $result->jsonFields[] = [$key, $decoded];
            } elseif (str_contains($item, '==')) {
                [$key, $value] = explode('==', $item, 2);
                $result->queryParams[] = [$key, $value];
            } elseif (str_contains($item, '=')) {
                [$key, $value] = explode('=', $item, 2);
                $result->dataFields[] = [$key, $value];
            } elseif (str_starts_with($item, '@')) {
                $result->files[] = ['file', substr($item, 1)];
            } elseif (str_contains($item, ':')) {
                [$key, $value] = explode(':', $item, 2);
                if ($value !== '') {
                    $result->headers[$key] = $value;
                } else {
                    $result->headersToRemove[] = $key;
                }
            } else {
                throw new CliError("unknown request item '{$item}'");
            }
        }

        return $result;
    }
}
