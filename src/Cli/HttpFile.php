<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * Parser for .http files in the "HTTP Request in Editor" format, like
 * curl_cffi.cli.run.parse_http_file:
 *
 *     ### (separates requests)
 *     POST http://example.com/api
 *     Content-Type: application/json
 *
 *     {"key": "value"}
 *     ###
 *     GET http://example.com
 *
 * Lines starting with # or // are comments. A body line "< path" reads
 * the body from a file (relative to the working directory), and a line
 * starting with "> " or "<> " (a response handler) ends the request.
 *
 * @see https://github.com/JetBrains/http-request-in-editor-spec/blob/master/spec.md
 */
final class HttpFile
{
    /**
     * @param (\Closure(string): void)|null $warn gets messages about bodies that
     *        reference a missing file
     *
     * @return list<HttpFileRequest>
     */
    public static function parse(string $text, ?\Closure $warn = null): array
    {
        $blocks = [];
        $current = [];
        foreach (self::splitLines($text) as $line) {
            if (str_starts_with(self::strip($line), '###')) {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $line;
            }
        }
        if ($current !== []) {
            $blocks[] = $current;
        }

        $requests = [];
        foreach ($blocks as $lines) {
            $request = self::parseBlock($lines, $warn);
            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /** @param list<string> $lines */
    private static function parseBlock(array $lines, ?\Closure $warn): ?HttpFileRequest
    {
        $state = 'request';
        $method = 'GET';
        $url = '';
        $headers = [];
        $body = [];

        foreach ($lines as $line) {
            $stripped = self::strip($line);
            if (str_starts_with($stripped, '#') || str_starts_with($stripped, '//')) {
                continue;
            }
            if ($state === 'request') {
                if ($stripped === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $stripped);
                $first = strtoupper($parts[0]);
                if (in_array($first, Items::SUPPORTED_METHODS, true) || $first === 'CONNECT') {
                    $method = $first;
                    $url = $parts[1] ?? '';
                } else {
                    $url = $parts[0];   // GET implied
                }
                $state = 'headers';
            } elseif ($state === 'headers') {
                if ($stripped === '') {
                    $state = 'body';
                } elseif (str_contains($stripped, ':')) {
                    [$name, $value] = explode(':', $stripped, 2);
                    $headers[self::strip($name)] = self::strip($value);
                } else {
                    $state = 'body';
                    $body[] = $line;
                }
            } else {
                if (str_starts_with($stripped, '> ') || str_starts_with($stripped, '<> ')) {
                    break;   // response handlers are not run
                }
                if (str_starts_with($stripped, '< ')) {
                    $path = self::strip(substr($stripped, 2));
                    $contents = is_file($path) ? @file_get_contents($path) : false;
                    if ($contents === false) {
                        if ($warn !== null) {
                            $warn("Error: referenced file not found: {$path}");
                        }
                    } else {
                        $body[] = $contents;
                    }
                } else {
                    $body[] = $line;
                }
            }
        }

        if ($url === '') {
            return null;
        }

        return new HttpFileRequest($method, $url, $headers, self::strip(implode("\n", $body)));
    }

    /**
     * Lines split like Python's str.splitlines(): on \n, \r\n, \r and the
     * other line boundaries, without a trailing empty line.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|[\n\r\x0b\x0c\x1c\x1d\x1e]|\xc2\x85|\xe2\x80[\xa8\xa9]/', $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** Python's str.strip() for the ASCII whitespace that occurs in these files. */
    private static function strip(string $text): string
    {
        return trim($text, " \t\n\r\x0b\x0c\x1c\x1d\x1e\x1f");
    }
}
