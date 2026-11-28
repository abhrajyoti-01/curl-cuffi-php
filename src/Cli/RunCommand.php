<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

use CurlCffi\Requests\Session;

/**
 * `curl-cffi run FILE`: send every request of an .http (or .rest) file or
 * a HAR archive, like curl_cffi.cli.run. With --session (the default) the
 * requests share one Session, so cookies and connections carry over.
 *
 * @internal
 */
final class RunCommand
{
    /** HAR headers that curl sets itself or that are HTTP/2 pseudo-headers. */
    private const SKIP_HAR_HEADERS = ['content-length', 'transfer-encoding', ':method', ':path', ':scheme', ':authority'];

    /**
     * @param array<string, mixed> $args parsed command line
     *
     * @return int 0, or 1 when the file cannot be run or any request failed
     */
    public static function handle(Console $io, array $args): int
    {
        $path = $args['file'];
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            throw new CliError("file not found: {$path}");
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['har', 'http', 'rest'], true)) {
            throw new CliError("unsupported file format: {$path}");
        }

        $session = ($args['session'] ?? true) ? new Session(impersonate: $args['impersonate'] ?? 'chrome') : null;
        try {
            $failed = $extension === 'har'
                ? self::runHar($io, $args, $text, $session)
                : self::runHttp($io, $args, $text, $session);
        } finally {
            $session?->close();
        }

        if ($failed > 0) {
            $io->err("\n{$failed} request(s) failed.");

            return 1;
        }

        return 0;
    }

    /** @param array<string, mixed> $args */
    private static function runHttp(Console $io, array $args, string $text, ?Session $session): int
    {
        $requests = HttpFile::parse($text, static fn (string $message) => $io->err($message));
        if ($requests === []) {
            throw new CliError('no requests found in file.');
        }

        $failed = 0;
        foreach ($requests as $i => $request) {
            if (empty($args['quiet'])) {
                $io->err('--- [' . ($i + 1) . "] {$request->method} {$request->url}");
            }
            $rc = RequestCommand::execute(
                $io,
                $args,
                $request->method,
                Items::processUrl($request->url),
                headers: $request->headers,
                data: $request->body === '' ? null : $request->body,
                session: $session,
            );
            if ($rc !== 0) {
                $failed++;
            }
        }

        return $failed;
    }

    /** @param array<string, mixed> $args */
    private static function runHar(Console $io, array $args, string $text, ?Session $session): int
    {
        try {
            $har = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CliError("invalid HAR JSON: {$e->getMessage()}");
        }
        $entries = is_array($har) && is_array($har['log'] ?? null) ? ($har['log']['entries'] ?? []) : [];
        if (!is_array($entries) || $entries === []) {
            throw new CliError('no entries found in HAR file.');
        }

        $failed = 0;
        foreach (array_values($entries) as $i => $entry) {
            $request = is_array($entry) && is_array($entry['request'] ?? null) ? $entry['request'] : [];
            $method = strtoupper((string) ($request['method'] ?? 'GET'));
            $url = (string) ($request['url'] ?? '');
            $n = $i + 1;
            if ($url === '') {
                $io->err("--- [{$n}] skipping entry with no URL");
                continue;
            }
            if (empty($args['quiet'])) {
                $io->err("--- [{$n}] {$method} {$url}");
            }

            $headers = [];
            foreach (is_array($request['headers'] ?? null) ? $request['headers'] : [] as $h) {
                $name = (string) ($h['name'] ?? '');
                if (!in_array(strtolower($name), self::SKIP_HAR_HEADERS, true)) {
                    $headers[$name] = (string) ($h['value'] ?? '');
                }
            }
            $postData = $request['postData'] ?? null;
            $data = is_array($postData) ? (string) ($postData['text'] ?? '') : null;

            $rc = RequestCommand::execute($io, $args, $method, $url, headers: $headers, data: $data, session: $session);
            if ($rc !== 0) {
                $failed++;
            }
        }

        return $failed;
    }
}
