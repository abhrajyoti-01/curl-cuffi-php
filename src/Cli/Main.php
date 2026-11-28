<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * The curl-cffi command, like Python's `curl-cffi` (curl_cffi.cli.main):
 *
 *     vendor/bin/curl-cffi get example.com
 *     vendor/bin/curl-cffi post :8080/api name=Ann age:=30 X-Token:abc
 *     vendor/bin/curl-cffi run requests.http
 *     vendor/bin/curl-cffi doctor
 *
 * Exit status: 0 on success, 1 when a request fails or answers 400 or
 * more, 2 for a command line that does not parse.
 */
final class Main
{
    public const PROG = 'curl-cffi';

    /** Fingerprint and diagnostic subcommands, after the HTTP verbs. */
    public const TOOLS = ['doctor', 'update', 'list', 'config', 'run'];

    /**
     * Run the command and return its exit status.
     *
     * @param list<string>|null $argv defaults to $_SERVER['argv']
     */
    public static function main(?array $argv = null, ?Console $io = null): int
    {
        $argv ??= $_SERVER['argv'] ?? [];
        $io ??= new Console();
        $prog = self::progName((string) ($argv[0] ?? ''));
        $args = array_values(array_slice($argv, 1));

        $showHelp = false;
        $unknown = [];
        $command = null;
        $rest = [];
        foreach ($args as $i => $arg) {
            if ($arg !== '' && $arg[0] === '-' && $arg !== '-') {
                if ($arg === '-h' || preg_match('/^--h(e(lp?)?)?$/', $arg)) {
                    $showHelp = true;
                } else {
                    $unknown[] = $arg;
                }
                continue;
            }
            $command = $arg;
            $rest = array_slice($args, $i + 1);
            break;
        }

        $parsers = self::parsers();
        $parser = null;
        try {
            if ($command !== null && !isset($parsers[$command])) {
                throw new UsageError("argument command: invalid choice: '{$command}' (choose from "
                    . implode(', ', array_keys($parsers)) . ')');
            }
            if ($unknown !== []) {
                throw new UsageError('unrecognized arguments: ' . implode(' ', $unknown));
            }
            if ($command === null || $showHelp) {
                fwrite($io->stdout, self::help($prog));

                return 0;
            }

            $parser = $parsers[$command];
            $values = $parser->parse($rest);

            return match ($command) {
                'doctor' => Doctor::handle($io),
                'update' => ProCommands::update($io),
                'list' => ProCommands::list($io, (bool) $values['json']),
                'config' => ProCommands::config($io, $values['api_key']),
                'run' => RunCommand::handle($io, $values),
                default => RequestCommand::handle($io, $values, strtoupper($command)),
            };
        } catch (HelpRequested $e) {
            fwrite($io->stdout, $e->getMessage());

            return 0;
        } catch (UsageError $e) {
            $usage = $parser?->formatUsage() ?? 'usage: ' . self::PROG . " [-h] <command> [options]\n";
            fwrite($io->stderr, $usage . ($parser->prog ?? self::PROG) . ': error: ' . $e->getMessage() . "\n");

            return 2;
        } catch (\Throwable $e) {
            // CliError, or anything else (a library that does not load, a file that cannot be read)
            $io->err('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Parsers of every subcommand: delete, DELETE, get, GET, ... (each verb
     * in lower and upper case), then doctor, update, list, config and run.
     *
     * @return array<string, ArgumentParser>
     */
    public static function parsers(): array
    {
        $parsers = [];
        $methods = Items::SUPPORTED_METHODS;
        sort($methods);
        foreach ($methods as $method) {
            foreach ([strtolower($method), $method] as $name) {
                $p = new ArgumentParser(self::PROG . " {$name}", "Make an HTTP {$method} request.");
                $p->addPositional('url', 'URL', help: 'Request URL');
                $p->addPositional('items', 'ITEM', '*', 'Request items: Header:Value, param==value, field=value, field:=json, @file, +cookie=value');
                self::addCommonFlags($p);
                $parsers[$name] = $p;
            }
        }

        $parsers['doctor'] = new ArgumentParser(self::PROG . ' doctor', 'Dump diagnostic information for debugging.');
        $parsers['update'] = new ArgumentParser(self::PROG . ' update', 'Update fingerprints from the impersonate API.');
        $p = new ArgumentParser(self::PROG . ' list', 'List local and native fingerprints.');
        $p->addOption(['--json'], 'store_true', help: 'Output fingerprints as JSON.');
        $parsers['list'] = $p;
        $p = new ArgumentParser(self::PROG . ' config', 'Set the API key for impersonate.pro.');
        $p->addOption(['--api-key'], required: true, type: ProCommands::validateApiKey(...), help: 'API key for impersonate.pro');
        $parsers['config'] = $p;

        $p = new ArgumentParser(self::PROG . ' run', self::RUN_DESCRIPTION, rawDescription: true);
        $p->addPositional('file', 'FILE', help: 'Path to .http or .har file');
        $p->addOption(['--session'], 'boolean', default: true, help: 'Use a single session for all requests, sharing cookies and connections (default: enabled)');
        self::addCommonFlags($p);
        $parsers['run'] = $p;

        return $parsers;
    }

    private static function addCommonFlags(ArgumentParser $p): void
    {
        $content = $p->addGroup();
        $p->addOption(['--json', '-j'], 'store_true', dest: 'json_mode', help: '(default) Serialize data items as a JSON object', group: $content);
        $p->addOption(['--form', '-f'], 'store_true', help: 'Serialize data items as form fields', group: $content);
        $p->addOption(['--multipart'], 'store_true', help: 'Force multipart form data', group: $content);

        $output = $p->addGroup();
        $p->addOption(['--headers'], 'store_true', dest: 'headers_only', help: 'Print response headers only', group: $output);
        $p->addOption(['--body'], 'store_true', dest: 'body_only', help: 'Print response body only', group: $output);
        $p->addOption(['--verbose', '-v'], 'store_true', help: 'Print request and response headers and body', group: $output);
        $p->addOption(['--quiet', '-q'], 'store_true', help: 'Suppress all output except errors', group: $output);
        $p->addOption(['--print', '-p'], dest: 'print_spec', help: 'Output control: H(req headers) B(req body) h(resp headers) b(resp body)', group: $output);

        $p->addOption(['--download', '-d'], 'store_true', help: 'Download response body to file');
        $p->addOption(['--output', '-o'], help: 'Output file path');
        $p->addOption(['--auth', '-a'], help: 'user:password');
        $p->addOption(['--verify'], 'boolean', default: true, help: 'SSL certificate verification');
        $p->addOption(['--proxy'], help: 'Proxy URL');
        $p->addOption(['--timeout'], type: 'float', help: 'Request timeout in seconds');
        $p->addOption(['--follow'], 'boolean', default: true, help: 'Follow redirects');
        $p->addOption(['--max-redirects'], type: 'int', default: 30, help: 'Maximum number of redirects');
        $p->addOption(['--impersonate', '-i'], default: 'chrome', help: 'Browser to impersonate');

        $http = $p->addGroup();
        $p->addOption(['--http1.1'], 'store_const', dest: 'http_version', const: 'v1', help: 'Use HTTP/1.1', group: $http);
        $p->addOption(['--http2'], 'store_const', dest: 'http_version', const: 'v2', help: 'Use HTTP/2', group: $http);
        $p->addOption(['--http3'], 'store_const', dest: 'http_version', const: 'v3', help: 'Use HTTP/3', group: $http);
        $p->addOption(['--http3-only'], 'store_const', dest: 'http_version', const: 'v3only', help: 'Use HTTP/3 only (no fallback to HTTP/2 or HTTP/1.1)', group: $http);
    }

    public static function help(string $prog = self::PROG): string
    {
        return <<<TXT
            Usage: {$prog} <command> [options]

            HTTP request CLI with browser impersonation.

            HTTP verbs:
              get, post, put, delete, patch    Make an HTTP request
              head, options, trace, query      (case-insensitive)

            Request items (for HTTP verb commands):
              Header:Value   HTTP header
              param==value   Query parameter
              field=value    Data field
              field:=json    Raw JSON field
              @file          File upload
              +key=value     Cookie

            Tools:
              run FILE                         Run requests from .http or .har file
              doctor                           Dump diagnostic information

            Fingerprints:
              update                           Update local fingerprints cache
              list                             List local and native fingerprints
              config                           Configure API access for Pro fingerprints

            Run '{$prog} <command> --help' for details on a specific command.

            TXT;
    }

    private const RUN_DESCRIPTION = <<<'TXT'
        Run requests from a file.

        Supported formats (auto-detected by extension):
          .http / .rest   HTTP Request in Editor format
          .har            HAR (HTTP Archive) format

        HTTP file format:
          ### (request separator)
          POST http://example.com/api
          Content-Type: application/json

          {"key": "value"}
          ###
          GET http://example.com

        Har file:
          Chrome exported HAR files do not contain cookies and auth headers by default. To include them:

          In DevTools settings: Gear Icon -> Preferences -> Network -> select "Allow to generate HAR with sensitive data"
        TXT;

    private static function progName(string $argv0): string
    {
        $name = basename(str_replace('\\', '/', $argv0));

        return $name === '' || str_ends_with($name, '.php') ? self::PROG : $name;
    }
}
