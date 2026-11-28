<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Requests\Response;

/**
 * What the curl-cffi command prints, like curl_cffi.cli.output.
 *
 * A print spec picks the parts: H request headers, B request body,
 * h response headers, b response body.
 */
final class Output
{
    /**
     * The parts to print. --print wins, then --quiet, --verbose (HhBb),
     * --headers (h), --body (b) and --download (h); otherwise "hb" on a
     * terminal and "b" when piped.
     *
     * @param array<string, mixed> $args parsed command line
     */
    public static function printSpec(array $args, bool $tty): string
    {
        return match (true) {
            ($args['print_spec'] ?? null) !== null && $args['print_spec'] !== '' => $args['print_spec'],
            !empty($args['quiet']) => '',
            !empty($args['verbose']) => 'HhBb',
            !empty($args['headers_only']) => 'h',
            !empty($args['body_only']) => 'b',
            !empty($args['download']) => 'h',
            $tty => 'hb',
            default => 'b',
        };
    }

    /** "1.0", "1.1", "2" or "3" for the status line. */
    public static function httpVersionLabel(Response $response): string
    {
        return match ($response->httpVersion) {
            CurlHttpVersion::V1_0 => '1.0',
            CurlHttpVersion::V2_0, CurlHttpVersion::V2TLS, CurlHttpVersion::V2_PRIOR_KNOWLEDGE => '2',
            CurlHttpVersion::V3, CurlHttpVersion::V3ONLY => '3',
            default => '1.1',
        };
    }

    /** @param array<string, ?string> $requestHeaders */
    public static function print(
        Console $io,
        Response $response,
        string $method,
        string $url,
        array $requestHeaders,
        ?string $requestBody,
        string $spec,
    ): void {
        if (str_contains($spec, 'H')) {
            $lines = ["{$method} {$url}"];
            foreach ($requestHeaders as $k => $v) {
                if ($v !== null) {   // a removed header ("Name:") is not shown, as in Python
                    $lines[] = "{$k}: {$v}";
                }
            }
            self::printHeaders($io, $lines);
            $io->out();
        }
        if (str_contains($spec, 'B') && $requestBody !== null && $requestBody !== '') {
            $io->out(self::looksLikeJson($requestBody) ? self::highlightJson($io, $requestBody) : $requestBody);
            $io->out();
        }
        if (str_contains($spec, 'h')) {
            $status = 'HTTP/' . self::httpVersionLabel($response) . " {$response->statusCode} {$response->reason}";
            $style = $response->statusCode < 300 ? Console::BOLD_GREEN
                : ($response->statusCode < 400 ? Console::BOLD_YELLOW : Console::BOLD_RED);
            $io->out($io->paint($status, $style));
            $lines = [];
            foreach ($response->headers->items() as $k => $v) {
                $lines[] = "{$k}: {$v}";
            }
            self::printHeaders($io, $lines);
            $io->out();
        }
        if (str_contains($spec, 'b')) {
            self::printBody($io, $response);
        }
    }

    /**
     * Python's json.dumps(value, indent=2, ensure_ascii=False).
     */
    public static function prettyJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        // PHP indents by 4 spaces, Python by 2; strings never hold a raw newline.
        return (string) preg_replace_callback('/^(?: {4})+/m', static fn ($m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
    }

    /**
     * A file name safe to write: no directories, no NUL bytes, and only
     * word characters, dots and dashes.
     */
    public static function sanitizeFilename(string $name): string
    {
        $parts = explode('/', str_replace("\0", '', $name));
        $parts = explode('\\', end($parts));
        $name = end($parts);
        $safe = @preg_replace('/[^\w.\-]/u', '_', $name);
        if (!is_string($safe)) {   // not UTF-8: replace byte by byte
            $safe = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', $name);
        }

        return $safe === '' ? 'download' : $safe;
    }

    /**
     * Name for a download: the Content-Disposition filename, else the last
     * part of the URL, else "download"; made safe by sanitizeFilename().
     */
    public static function downloadName(Response $response, string $url): string
    {
        $cd = (string) $response->headers->get('content-disposition', '');
        if (str_contains($cd, 'filename=')) {
            $name = trim(trim(explode('filename=', $cd)[1], '"'), "'");
        } else {
            $parts = explode('/', rtrim($url, '/'));
            $name = end($parts);
            $name = $name === '' ? 'download' : $name;
        }

        return self::sanitizeFilename($name);
    }

    /**
     * Save the body. An -o path is used as given; a name taken from the
     * response or URL is made safe first.
     */
    public static function download(Console $io, Response $response, string $url, ?string $outputPath, bool $quiet): void
    {
        $path = $outputPath ?? self::downloadName($response, $url);
        if (@file_put_contents($path, $response->content) === false) {
            $reason = error_get_last()['message'] ?? 'write failed';
            throw new CliError("cannot write {$path}: {$reason}");
        }
        if (!$quiet) {
            $io->err("Downloaded to {$path}");
        }
    }

    /** @param list<string> $lines */
    private static function printHeaders(Console $io, array $lines): void
    {
        foreach ($lines as $line) {
            if (!$io->color) {
                $io->out($line);
            } elseif (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $io->out($io->paint($key, Console::BOLD_CYAN) . $io->paint(': ', Console::DIM) . $value);
            } else {
                $io->out($io->paint($line, Console::BOLD_GREEN));
            }
        }
    }

    private static function printBody(Console $io, Response $response): void
    {
        $contentType = (string) $response->headers->get('content-type', '');
        if (str_contains($contentType, 'json')) {
            try {
                $io->out(self::highlightJson($io, self::prettyJson($response->json(false))));

                return;
            } catch (\Throwable) {
                // not JSON after all: print it as text
            }
        }
        if (str_starts_with($contentType, 'image/')) {
            $io->err("Binary image data ({$contentType}, " . strlen($response->content) . ' bytes)');

            return;
        }
        $io->out($response->text());
    }

    private static function looksLikeJson(string $text): bool
    {
        return $text !== '' && ($text[0] === '{' || $text[0] === '[');
    }

    /** Color keys, strings, numbers and literals of JSON text when the console has colors. */
    private static function highlightJson(Console $io, string $json): string
    {
        if (!$io->color) {
            return $json;
        }

        return (string) preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/',
            static function (array $m) use ($io): string {
                if (($m[1] ?? '') !== '') {
                    return isset($m[2]) && $m[2] !== ''
                        ? $io->paint($m[1], Console::BOLD_BLUE) . $m[2]
                        : $io->paint($m[1], Console::GREEN);
                }
                if (($m[3] ?? '') !== '') {
                    return $io->paint($m[3], Console::MAGENTA);
                }

                return $io->paint($m[4], Console::CYAN);
            },
            $json,
        );
    }
}
