<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Integration;

use CurlCffi\Cli\Console;
use CurlCffi\Cli\Main;
use CurlCffi\Ffi\Library;
use CurlCffi\Ffi\LibraryException;
use CurlCffi\Impersonate\FingerprintManager;
use PHPUnit\Framework\TestCase;

/**
 * The curl-cffi command against the local test servers. Most tests run it
 * in-process with captured output; testBinary runs bin/curl-cffi itself.
 */
final class CliTest extends TestCase
{
    private string $dir;
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        try {
            Library::ffi();
        } catch (LibraryException $e) {
            self::markTestSkipped($e->getMessage());
        }
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'curl-cffi-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (['IMPERSONATE_CONFIG_DIR', 'IMPERSONATE_API_KEY', 'IMPERSONATE_API_ROOT', 'NO_COLOR'] as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
        putenv('IMPERSONATE_CONFIG_DIR=' . $this->dir . DIRECTORY_SEPARATOR . 'config');
        FingerprintManager::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
        FingerprintManager::clearCache();
        if (isset($this->dir)) {
            self::removeTree($this->dir);
        }
    }

    public function testVerboseGet(): void
    {
        [$rc, $out, $err] = self::cli('get', LocalServer::url('/echo'), 'q==1', 'X-Token:abc', '+sid=xyz', '-v');
        self::assertSame(0, $rc, $err);
        [$request, $response] = explode("\n\nHTTP/", $out, 2);
        self::assertSame('GET ' . LocalServer::url('/echo') . "\nX-Token: abc", $request);
        self::assertStringStartsWith("1.1 200 OK\n", $response);
        // header names are lowercased, as Python's Headers.items() gives them
        self::assertStringContainsString("\ncontent-type: application/json\nx-test: yes\n", $response);

        $echo = self::body($out);
        self::assertSame(['q' => '1'], $echo['query']);
        self::assertSame('abc', $echo['headers']['X-Token']);
        self::assertSame('sid=xyz', $echo['headers']['Cookie']);
        // pretty-printed with two spaces, like json.dumps(indent=2)
        self::assertStringContainsString("{\n  \"method\": \"GET\",\n  \"path\": \"/echo\",", $out);
    }

    public function testJsonFormAndMultipartBodies(): void
    {
        [$rc, $out] = self::cli('post', LocalServer::url('/echo'), 'name=Ann', 'age:=30', 'tags:=["a"]', 'name=Bea', '-p', 'Bb');
        self::assertSame(0, $rc);
        self::assertStringStartsWith("{\n  \"name\": \"Bea\",\n  \"age\": 30,\n  \"tags\": [\n    \"a\"\n  ]\n}\n\n", $out, 'the request body is shown first');
        $echo = self::body(substr($out, strpos($out, "}\n\n") + 3));
        self::assertSame('{"name":"Bea","age":30,"tags":["a"]}', $echo['body']);
        self::assertSame('application/json', $echo['headers']['Content-Type']);

        [, $out] = self::cli('post', LocalServer::url('/echo'), '-f', 'a=1', 'b=two words', 'ignored:=1');
        $echo = self::body($out);
        self::assertSame('a=1&b=two+words', $echo['body'], 'form mode drops := fields, as Python does');
        self::assertSame('application/x-www-form-urlencoded', $echo['headers']['Content-Type']);

        $file = $this->dir . DIRECTORY_SEPARATOR . 'note.txt';
        file_put_contents($file, 'hello');
        [$rc, $out] = self::cli('post', LocalServer::url('/upload'), "@{$file}", 'title=Hi');
        self::assertSame(0, $rc);
        $echo = self::body($out);
        self::assertSame(['title' => 'Hi'], $echo['post']);
        self::assertSame(['name' => 'note.txt', 'type' => 'text/plain', 'content' => 'hello'], $echo['files']['file']);

        [, $out] = self::cli('post', LocalServer::url('/upload'), '--multipart', 'a=1');
        self::assertSame(['post' => ['a' => '1'], 'files' => []], self::body($out));
    }

    public function testHeaderRemovalAuthAndRedirects(): void
    {
        [, $out] = self::cli('get', LocalServer::url('/echo'), 'Accept:', '-a', 'ann:secret');
        $echo = self::body($out);
        self::assertArrayNotHasKey('Accept', $echo['headers']);
        self::assertSame('Basic ' . base64_encode('ann:secret'), $echo['headers']['Authorization']);

        [$rc, $out] = self::cli('get', LocalServer::url('/redirect'), '--headers');
        self::assertSame(0, $rc);
        self::assertStringStartsWith("HTTP/1.1 200 OK\n", $out, 'redirects are followed by default');

        [$rc, $out] = self::cli('get', LocalServer::url('/redirect'), '--no-follow', '--headers');
        self::assertSame(0, $rc, 'a 302 is not an error');
        self::assertStringStartsWith("HTTP/1.1 302 Found\n", $out);
        self::assertStringContainsString("\nlocation: /echo?redirected=1\n", $out);

        [$rc, , $err] = self::cli('get', LocalServer::url('/redirect/3'), '--max-redirects', '1');
        self::assertSame(1, $rc);
        self::assertStringStartsWith('Error: ', $err);
    }

    public function testErrorsAndStatusCodes(): void
    {
        [$rc, $out, $err] = self::cli('get', LocalServer::url('/status/404'));
        self::assertSame(1, $rc, 'a status of 400 or more exits with 1');
        self::assertSame("status 404\n", $out);
        self::assertSame('', $err);

        [$rc, $out, $err] = self::cli('get', LocalServer::url('/status/500'), '-q');
        self::assertSame([1, '', ''], [$rc, $out, $err]);

        [$rc, $out, $err] = self::cli('get', 'http://127.0.0.1:1/', '--timeout', '5');
        self::assertSame(1, $rc);
        self::assertSame('', $out);
        self::assertStringStartsWith('Error: Failed to perform, curl: (7)', $err);

        [$rc, , $err] = self::cli('post', LocalServer::url('/upload'), '@' . $this->dir . '/missing.txt');
        self::assertSame(1, $rc);
        self::assertSame('Error: file not found: ' . $this->dir . "/missing.txt\n", $err);

        [$rc, $out, $err] = self::cli('get', LocalServer::url('/image'));
        self::assertSame([0, ''], [$rc, $out]);
        self::assertSame("Binary image data (image/png, 8 bytes)\n", $err);
    }

    public function testDownloads(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            [$rc, $out, $err] = self::cli('get', LocalServer::url('/download'), '-d');
            self::assertSame(0, $rc);
            self::assertStringStartsWith("HTTP/1.1 200 OK\n", $out, 'download mode prints the response headers');
            self::assertSame("Downloaded to report_1.txt\n", $err, 'the server-given name is made safe');
            self::assertSame('report body', file_get_contents($this->dir . '/report_1.txt'));

            [$rc, $out, $err] = self::cli('get', LocalServer::url('/echo/data.json?x=1'), '--download', '--quiet');
            self::assertSame([0, '', ''], [$rc, $out, $err]);
            self::assertFileExists($this->dir . '/data.json_x_1');
        } finally {
            chdir((string) $cwd);
        }

        mkdir($this->dir . '/sub');
        $target = $this->dir . '/sub/out.bin';
        [$rc, , $err] = self::cli('get', LocalServer::url('/download'), '-d', '-o', $target);
        self::assertSame(0, $rc);
        self::assertSame("Downloaded to {$target}\n", $err, 'an -o path is used as given');
        self::assertSame('report body', file_get_contents($target));
    }

    public function testRunHttpFile(): void
    {
        $file = $this->dir . '/flow.http';
        file_put_contents($file, implode("\n", [
            '### log in',
            'GET ' . LocalServer::url('/cookies/set?flavor=oat'),
            '###',
            'POST ' . LocalServer::url('/echo'),
            'Content-Type: application/json',
            '',
            '{"key": "value"}',
            '###',
            LocalServer::url('/status/418'),
        ]));

        [$rc, $out, $err] = self::cli('run', $file);
        self::assertSame(1, $rc);
        self::assertSame(
            '--- [1] GET ' . LocalServer::url('/cookies/set?flavor=oat') . "\n"
            . '--- [2] POST ' . LocalServer::url('/echo') . "\n"
            . '--- [3] GET ' . LocalServer::url('/status/418') . "\n"
            . "\n1 request(s) failed.\n",
            $err,
        );
        [$cookies, $echo] = explode("\n}\n{", $out, 3);
        self::assertSame(['cookies' => ['flavor' => 'oat']], json_decode($cookies . "\n}", true));
        $echo = json_decode('{' . substr($echo, 0, (int) strrpos($echo, '}') + 1), true);
        self::assertSame('{"key": "value"}', $echo['body']);
        self::assertSame('flavor=oat', $echo['headers']['Cookie'], 'the session keeps cookies between requests');

        [, $out] = self::cli('run', $file, '--no-session', '-q');
        self::assertSame('', $out);
        [, $out] = self::cli('run', $file, '--no-session');
        self::assertStringNotContainsString('"Cookie"', $out, 'without a session nothing carries over');
    }

    public function testRunHar(): void
    {
        $file = $this->dir . '/capture.har';
        file_put_contents($file, json_encode(['log' => ['entries' => [
            ['request' => [
                'method' => 'post',
                'url' => LocalServer::url('/echo'),
                'headers' => [
                    ['name' => ':authority', 'value' => 'skipped'],
                    ['name' => 'Content-Length', 'value' => '999'],
                    ['name' => 'X-Har', 'value' => 'yes'],
                ],
                'postData' => ['mimeType' => 'text/plain', 'text' => 'a=b'],
            ]],
            ['request' => ['url' => '']],
        ]]]));

        [$rc, $out, $err] = self::cli('run', $file);
        self::assertSame(0, $rc, $err);
        self::assertSame('--- [1] POST ' . LocalServer::url('/echo') . "\n--- [2] skipping entry with no URL\n", $err);
        $echo = self::body($out);
        self::assertSame('a=b', $echo['body']);
        self::assertSame('yes', $echo['headers']['X-Har']);
        self::assertSame('3', $echo['headers']['Content-Length']);

        file_put_contents($file, '{"log": {"entries": []}}');
        self::assertSame([1, '', "Error: no entries found in HAR file.\n"], self::cli('run', $file));
        file_put_contents($file, '{not json');
        self::assertSame([1, '', "Error: invalid HAR JSON: Syntax error\n"], self::cli('run', $file));
        rename($file, $this->dir . '/capture.txt');
        self::assertSame([1, '', 'Error: unsupported file format: ' . $this->dir . "/capture.txt\n"], self::cli('run', $this->dir . '/capture.txt'));
    }

    public function testColorsOnATerminal(): void
    {
        [$rc, $out] = self::cli('get', LocalServer::url('/echo'), '--console', 'color');
        self::assertSame(0, $rc);
        self::assertStringContainsString(Console::BOLD_GREEN . 'HTTP/1.1 200 OK' . Console::RESET, $out);
        self::assertStringContainsString(Console::BOLD_CYAN . 'content-type' . Console::RESET . Console::DIM . ': ' . Console::RESET . 'application/json', $out);
        self::assertStringContainsString(Console::BOLD_BLUE . '"method"' . Console::RESET . ': ' . Console::GREEN . '"GET"' . Console::RESET, $out);

        [, $out] = self::cli('get', LocalServer::url('/echo'), '--console', 'tty');
        self::assertStringStartsWith("HTTP/1.1 200 OK\n", $out, 'a terminal gets headers and body');
        self::assertStringNotContainsString("\e[", $out);
    }

    public function testDoctorAndFingerprintCommands(): void
    {
        [$rc, $out] = self::cli('doctor');
        self::assertSame(0, $rc);
        $keys = array_map(static fn ($l) => explode(':', $l, 2)[0], array_slice(explode("\n", trim($out)), 2));
        self::assertSame(
            ['php', 'executable', 'platform', 'machine', 'curl_cffi', 'ffi_enable', 'libcurl', 'library', 'impersonation',
                'api_root', 'config_path', 'config_present', 'api_key_configured', 'fingerprint_path', 'fingerprint_present', 'fingerprint_count'],
            $keys,
        );
        self::assertStringStartsWith("curl-cffi doctor\n----------------\nphp: " . PHP_VERSION . "\n", $out);
        self::assertStringContainsString("\nlibcurl: " . Library::version() . "\n", $out);
        self::assertStringContainsString("\nconfig_present: False\napi_key_configured: False\n", $out);

        [$rc, $out] = self::cli('config', '--api-key', 'imp_test');
        self::assertSame([0, 'API key saved to ' . FingerprintManager::getConfigPath() . "\n"], [$rc, $out]);

        putenv('IMPERSONATE_API_ROOT=' . LocalServer::url('/fp'));
        [$rc, $out] = self::cli('update');
        self::assertSame([0, "Total 2 fingerprints in cache.\n"], [$rc, $out]);

        [$rc, $out] = self::cli('list');
        self::assertSame(0, $rc);
        $lines = explode("\n", rtrim($out, "\n"));
        self::assertMatchesRegularExpression('/^type +\| name +\| browser +\| version +\| os +\| os_version +\| h3_fingerprints$/', $lines[0]);
        self::assertMatchesRegularExpression('/^-+-\+-+-\+-/', $lines[1]);
        self::assertMatchesRegularExpression('/^custom  \| custom_a +\| chrome +\| 999 +\| +\| +\| true +$/', implode("\n", preg_grep('/custom_a/', $lines)));
        self::assertCount(count(FingerprintManager::listFingerprints()) + 2, $lines);

        [, $out] = self::cli('list', '--json');
        $rows = json_decode($out, true);
        self::assertSame(FingerprintManager::listFingerprints(), $rows);
        self::assertStringStartsWith("[\n  {\n    \"type\": ", $out);

        putenv('IMPERSONATE_API_KEY=imp_wrong');
        [$rc, $out, $err] = self::cli('update');
        self::assertSame([1, ''], [$rc, $out], 'a failed update exits with 1');
        self::assertNotSame('', $err);
    }

    public function testDefaultImpersonation(): void
    {
        if (!Library::supportsImpersonation()) {
            self::markTestSkipped('needs libcurl-impersonate');
        }
        [$rc, $out] = self::cli('get', LocalServer::url('/echo'), '--keep-impersonate');
        self::assertSame(0, $rc);
        self::assertStringContainsString('Chrome/', self::body($out)['headers']['User-Agent'], 'chrome is the default, as in Python');
    }

    public function testBinary(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/curl-cffi';
        $impersonate = Library::supportsImpersonation() ? [] : ['-i', ''];
        $run = static function (array $args): array {
            $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);

            return [proc_close($p), $out, $err];
        };

        [$rc, $out, $err] = $run([$bin, 'get', LocalServer::url('/echo'), 'q==1', ...$impersonate]);
        self::assertSame(0, $rc, $err);
        self::assertSame(['q' => '1'], json_decode($out, true)['query'], 'piped output is the body only');

        [$rc, , $err] = $run([$bin, 'get', LocalServer::url('/status/404'), ...$impersonate]);
        self::assertSame(1, $rc);

        [$rc, $out, $err] = $run([$bin, 'get']);
        self::assertSame([2, ''], [$rc, $out]);
        self::assertStringEndsWith("curl-cffi get: error: the following arguments are required: URL\n", $err);
    }

    /**
     * Run the command. Unless the test asks for --keep-impersonate, stock
     * libcurl gets `-i ''`, since it cannot impersonate. `--console color`
     * or `--console tty` pretends stdout is a terminal.
     *
     * @return array{int, string, string}
     */
    private static function cli(string ...$args): array
    {
        $tty = false;
        $color = false;
        if (($i = array_search('--console', $args, true)) !== false) {
            $tty = true;
            $color = $args[$i + 1] === 'color';
            array_splice($args, $i, 2);
        }
        if (($i = array_search('--keep-impersonate', $args, true)) !== false) {
            array_splice($args, $i, 1);
        } elseif (!Library::supportsImpersonation() && !in_array($args[0], ['doctor', 'update', 'list', 'config'], true)) {
            array_push($args, '-i', '');
        }
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $rc = Main::main(['curl-cffi', ...$args], new Console($out, $err, $tty, $color));
        rewind($out);
        rewind($err);

        return [$rc, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return array<string, mixed> the JSON body at the end of the output */
    private static function body(string $out): array
    {
        $start = strrpos($out, "\n{\n");
        $json = json_decode($start === false ? $out : substr($out, $start + 1), true);
        self::assertIsArray($json, $out);

        return $json;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
