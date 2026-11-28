<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

use CurlCffi\Curl\CurlMime;
use CurlCffi\Requests\Requests;
use CurlCffi\Requests\Response;
use CurlCffi\Requests\Session;
use CurlCffi\Requests\Url;

/**
 * The HTTP verb subcommands (get, post, ...), like curl_cffi.cli.request.
 *
 * @internal
 */
final class RequestCommand
{
    /**
     * Build the request from the URL and items, send it and print it.
     *
     * @param array<string, mixed> $args parsed command line
     *
     * @return int 0, or 1 on an error or a status of 400 or more
     */
    public static function handle(Console $io, array $args, string $method): int
    {
        $url = Items::processUrl($args['url']);
        $parsed = Items::parse($args['items']);

        $data = null;
        $json = null;
        $multipart = !empty($args['multipart']) || $parsed->files !== [];
        if (!empty($args['form']) || $multipart) {
            if ($parsed->dataFields !== []) {
                $data = [];
                foreach ($parsed->dataFields as [$k, $v]) {
                    $data[$k] = $v;
                }
            }
        } elseif ($parsed->dataFields !== [] || $parsed->jsonFields !== []) {
            // an object even for keys like "0", which a PHP array would turn into a list
            $json = new \stdClass();
            foreach ([...$parsed->dataFields, ...$parsed->jsonFields] as [$k, $v]) {
                $json->{$k} = $v;
            }
        }
        foreach ($parsed->files as [, $path]) {
            if (!is_file($path) || !is_readable($path)) {
                throw new CliError("file not found: {$path}");
            }
        }

        $headers = $parsed->headers;
        foreach ($parsed->headersToRemove as $name) {
            $headers[$name] = null;
        }

        $auth = null;
        if (($args['auth'] ?? null) !== null && $args['auth'] !== '') {
            $parts = explode(':', $args['auth'], 2);
            $auth = [$parts[0], $parts[1] ?? ''];
        }

        return self::execute(
            $io,
            $args,
            $method,
            $url,
            headers: $headers,
            params: $parsed->queryParams,
            data: $data,
            json: $json,
            files: $parsed->files,
            multipart: $multipart,
            cookies: $parsed->cookies,
            auth: $auth,
        );
    }

    /**
     * Send one request with the command line's settings and print the
     * result. Request errors are printed, not thrown.
     *
     * @param array<string, mixed>              $args
     * @param array<string, ?string>            $headers
     * @param list<array{0: string, 1: string}> $params
     * @param array<string, string>|string|null $data
     * @param list<array{0: string, 1: string}> $files   [field, path] pairs
     * @param array<string, string>             $cookies
     * @param array{0: string, 1: string}|null  $auth
     *
     * @return int 0, or 1 on an error or a status of 400 or more
     */
    public static function execute(
        Console $io,
        array $args,
        string $method,
        string $url,
        array $headers = [],
        array $params = [],
        array|string|null $data = null,
        ?\stdClass $json = null,
        array $files = [],
        bool $multipart = false,
        array $cookies = [],
        ?array $auth = null,
        ?Session $session = null,
    ): int {
        $options = [
            'headers' => $headers,
            'params' => $params,
            'cookies' => $cookies,
            'auth' => $auth,
            'timeout' => $args['timeout'] ?? null,
            'verify' => $args['verify'] ?? true,
            'proxy' => $args['proxy'] ?? null,
            'allow_redirects' => $args['follow'] ?? true,
            'max_redirects' => $args['max_redirects'] ?? 30,
            'impersonate' => $args['impersonate'] ?? 'chrome',
            'http_version' => $args['http_version'] ?? null,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $mime = null;
        if ($multipart && (is_array($data) || $files !== [])) {
            $mime = new CurlMime();
            foreach (is_array($data) ? $data : [] as $k => $v) {
                $mime->addpart(name: (string) $k, data: $v);
            }
            foreach ($files as [$field, $path]) {
                $mime->addpart(name: $field, filename: basename($path), localPath: $path);
            }
            $options['multipart'] = $mime;
        } elseif ($data !== null) {
            $options['data'] = $data;
        }

        try {
            $response = $session !== null
                ? $session->request($method, $url, ...$options)
                : Requests::request($method, $url, ...$options);
        } catch (\Throwable $e) {
            $io->err('Error: ' . $e->getMessage());

            return 1;
        } finally {
            $mime?->close();
        }

        if (!empty($args['download'])) {
            if (empty($args['quiet'])) {
                Output::print($io, $response, $method, $url, $headers, null, 'h');
            }
            Output::download($io, $response, $url, $args['output'] ?? null, !empty($args['quiet']));
        } else {
            $requestBody = match (true) {
                $json !== null => Output::prettyJson($json),
                is_array($data) => Url::encodeForm($data),
                default => $data,
            };
            Output::print($io, $response, $method, $url, $headers, $requestBody, Output::printSpec($args, $io->tty));
        }

        return self::exitCode($response);
    }

    private static function exitCode(Response $response): int
    {
        return $response->statusCode >= 400 ? 1 : 0;
    }
}
