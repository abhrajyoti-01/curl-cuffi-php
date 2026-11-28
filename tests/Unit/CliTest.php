<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Unit;

use CurlCffi\Cli\CliError;
use CurlCffi\Cli\Console;
use CurlCffi\Cli\HttpFile;
use CurlCffi\Cli\Items;
use CurlCffi\Cli\Main;
use CurlCffi\Cli\Output;
use CurlCffi\Cli\UsageError;
use CurlCffi\Requests\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the curl-cffi command that need no network. Expected values
 * follow Python's curl_cffi.cli (parse.py, output.py, run.py).
 */
final class CliTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function urls(): array
    {
        return [
            'localhost shortcut' => [':8080/api', 'http://localhost:8080/api'],
            'bare port' => [':3000', 'http://localhost:3000'],
            'no scheme' => ['example.com/a', 'https://example.com/a'],
            'explicit port' => ['example.com:8080/a', 'http://example.com:8080/a'],
            'port 443' => ['example.com:443/a', 'https://example.com:443/a'],
            'colon in path only' => ['example.com/a:b', 'https://example.com/a:b'],
            'scheme kept' => ['http://example.com', 'http://example.com'],
            'other scheme kept' => ['ws://example.com:1', 'ws://example.com:1'],
        ];
    }

    #[DataProvider('urls')]
    public function testProcessUrl(string $in, string $out): void
    {
        self::assertSame($out, Items::processUrl($in));
    }

    public function testRequestItems(): void
    {
        $p = Items::parse([
            'X-Token:abc', 'Accept:', 'q==1', 'q==2', 'name=Ann', 'age:=30', 'tags:=["a",{}]', '@a.txt',
            '+sid=xyz', 'a:b=c', 'eq==x=y', 'Time:12:30', 'X-Space: padded',
        ]);
        self::assertSame(['X-Token' => 'abc', 'Time' => '12:30', 'X-Space' => ' padded'], $p->headers);
        self::assertSame(['Accept'], $p->headersToRemove);
        self::assertSame([['q', '1'], ['q', '2'], ['eq', 'x=y']], $p->queryParams);
        // "=" is checked before ":", so a:b=c is a data field, as in Python
        self::assertSame([['name', 'Ann'], ['a:b', 'c']], $p->dataFields);
        self::assertSame('age', $p->jsonFields[0][0]);
        self::assertSame(30, $p->jsonFields[0][1]);
        self::assertSame('["a",{}]', json_encode($p->jsonFields[1][1]));
        self::assertSame([['file', 'a.txt']], $p->files);
        self::assertSame(['sid' => 'xyz'], $p->cookies);
    }

    public function testBadItems(): void
    {
        foreach (['plain' => "unknown request item 'plain'", '+flag' => "unknown request item '+flag'", 'n:={x' => "invalid JSON in 'n:={x'"] as $item => $message) {
            try {
                Items::parse([$item]);
                self::fail("{$item} parsed");
            } catch (CliError $e) {
                self::assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function testArgumentParsing(): void
    {
        $get = Main::parsers()['get'];
        $v = $get->parse(['example.com', 'a=1', '-vd', '--imp=safari', '--timeout', '2.5', '--no-verify', 'b=2', '--max-red=3', '--http2']);
        self::assertSame('example.com', $v['url']);
        self::assertSame(['a=1', 'b=2'], $v['items']);
        self::assertTrue($v['verbose']);
        self::assertTrue($v['download']);
        self::assertSame('safari', $v['impersonate']);
        self::assertSame(2.5, $v['timeout']);
        self::assertFalse($v['verify']);
        self::assertTrue($v['follow']);
        self::assertSame(3, $v['max_redirects']);
        self::assertSame('v2', $v['http_version']);

        $v = $get->parse(['-ichrome124', 'u', '--', '-not-an-option']);
        self::assertSame('chrome124', $v['impersonate']);
        self::assertSame(['-not-an-option'], $v['items']);

        $v = $get->parse(['u', '-i=', '-p', 'hb']);
        self::assertSame('', $v['impersonate']);
        self::assertSame('hb', $v['print_spec']);
        self::assertSame(30, $v['max_redirects']);
        self::assertNull($v['timeout']);
        self::assertNull($v['http_version']);
        self::assertFalse($v['json_mode']);
    }

    /** @return array<string, array{list<string>, string}> */
    public static function usageErrors(): array
    {
        return [
            'missing url' => [[], 'the following arguments are required: URL'],
            'group' => [['u', '--headers', '--body'], 'argument --body: not allowed with argument --headers'],
            'bundled group' => [['u', '-vq'], 'argument --quiet/-q: not allowed with argument --verbose/-v'],
            'ambiguous' => [['u', '--http'], 'ambiguous option: --http could match --http1.1, --http2, --http3, --http3-only'],
            'unknown' => [['u', '--nope', '-z'], 'unrecognized arguments: --nope -z'],
            'no value' => [['u', '--timeout'], 'argument --timeout: expected one argument'],
            'option as value' => [['u', '-o', '-v'], 'argument --output/-o: expected one argument'],
            'float' => [['u', '--timeout', 'soon'], "argument --timeout: invalid float value: 'soon'"],
            'int' => [['u', '--max-redirects', '1.5'], "argument --max-redirects: invalid int value: '1.5'"],
            'flag value' => [['u', '--verbose=yes'], "argument --verbose/-v: ignored explicit argument 'yes'"],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('usageErrors')]
    public function testUsageErrors(array $args, string $message): void
    {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage($message);
        Main::parsers()['get']->parse($args);
    }

    public function testMainExitCodes(): void
    {
        [$rc, $out] = self::main([]);
        self::assertSame(0, $rc);
        self::assertStringStartsWith("Usage: curl-cffi <command> [options]\n\nHTTP request CLI with browser impersonation.", $out);

        [$rc, $out] = self::main(['GET', '--help']);
        self::assertSame(0, $rc);
        self::assertStringStartsWith('usage: curl-cffi GET [-h] [--json | --form | --multipart]', $out);
        self::assertStringContainsString("Make an HTTP GET request.\n", $out);
        self::assertStringContainsString('  --http3-only          Use HTTP/3 only (no fallback to HTTP/2 or HTTP/1.1)', $out);

        [$rc, $out, $err] = self::main(['Get', 'x']);
        self::assertSame(2, $rc);
        self::assertSame('', $out);
        self::assertStringContainsString("curl-cffi: error: argument command: invalid choice: 'Get' (choose from delete, DELETE, get, GET,", $err);

        [$rc, , $err] = self::main(['config', '--api-key', 'nope']);
        self::assertSame(2, $rc);
        self::assertSame("usage: curl-cffi config [-h] --api-key API_KEY\ncurl-cffi config: error: argument --api-key: API key must start with 'imp_'.\n", $err);

        [$rc, , $err] = self::main(['get', 'example.com', 'oops']);
        self::assertSame(1, $rc);
        self::assertSame("Error: unknown request item 'oops'\n", $err);

        [$rc, , $err] = self::main(['run', 'missing.http']);
        self::assertSame(1, $rc);
        self::assertSame("Error: file not found: missing.http\n", $err);
    }

    public function testPrintSpec(): void
    {
        self::assertSame('hb', Output::printSpec([], true));
        self::assertSame('b', Output::printSpec([], false));
        self::assertSame('HhBb', Output::printSpec(['verbose' => true], false));
        self::assertSame('h', Output::printSpec(['headers_only' => true], true));
        self::assertSame('b', Output::printSpec(['body_only' => true], true));
        self::assertSame('', Output::printSpec(['quiet' => true], true));
        self::assertSame('h', Output::printSpec(['download' => true], false));
        self::assertSame('Bh', Output::printSpec(['print_spec' => 'Bh', 'verbose' => true], false));
        self::assertSame('b', Output::printSpec(['print_spec' => ''], false));
    }

    public function testPrettyJsonMatchesPythonDumps(): void
    {
        // json.dumps(json.loads(src), indent=2, ensure_ascii=False)
        $src = '{"a":[1,2.0,{"b":null}],"e":{},"l":[],"u":"caf\u00e9 \u2028/","t":true}';
        $python = "{\n  \"a\": [\n    1,\n    2.0,\n    {\n      \"b\": null\n    }\n  ],\n  \"e\": {},\n  \"l\": [],\n"
            . "  \"u\": \"caf\u{e9} \u{2028}/\",\n  \"t\": true\n}";
        self::assertSame($python, Output::prettyJson(json_decode($src, false)));
    }

    public function testDownloadNames(): void
    {
        self::assertSame('report.pdf', Output::sanitizeFilename('../../etc/report.pdf'));
        self::assertSame('x.txt', Output::sanitizeFilename('C:\\dir\\x.txt'));
        self::assertSame('na_ve_file_1_.txt', Output::sanitizeFilename("na?ve file(1).txt"));
        self::assertSame("na\u{ef}ve.txt", Output::sanitizeFilename("na\u{ef}ve.txt"));
        self::assertSame('download', Output::sanitizeFilename("\0"));

        $r = new Response();
        self::assertSame('file.zip_a_1', Output::downloadName($r, 'https://x.test/dl/file.zip?a=1'));
        self::assertSame('dl', Output::downloadName($r, 'https://x.test/dl/'));
        $r->headers->set('Content-Disposition', 'attachment; filename="q1 report.csv"');
        self::assertSame('q1_report.csv', Output::downloadName($r, 'https://x.test/dl'));
    }

    public function testHttpFile(): void
    {
        $body = tempnam(sys_get_temp_dir(), 'cli');
        file_put_contents($body, '{"from": "file"}');
        $warnings = [];
        $text = "### first\r\n"
            . "# comment\r\n"
            . "POST https://example.com/api HTTP/1.1\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Pad :  spaced  \r\n"
            . "\r\n"
            . "{\"key\": \"value\"}\r\n"
            . "// also a comment\r\n"
            . "\r\n"
            . "> {% client.test() %}\r\n"
            . "after the handler\r\n"
            . "###\n"
            . "example.com/implied-get\n"
            . "###\n"
            . "\n"
            . "###\n"
            . "put :8080/x\n"
            . "Accept: */*\n"
            . "\n"
            . "< {$body}\n"
            . "< /no/such/file.json\n"
            . "###\n"
            . "CONNECT proxy.test:443\n"
            . "line without colon starts the body\n";
        $requests = HttpFile::parse($text, function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });
        unlink($body);

        self::assertCount(4, $requests);
        self::assertSame(['POST', 'https://example.com/api'], [$requests[0]->method, $requests[0]->url]);
        self::assertSame(['Content-Type' => 'application/json', 'X-Pad' => 'spaced'], $requests[0]->headers);
        self::assertSame('{"key": "value"}', $requests[0]->body);
        self::assertSame(['GET', 'example.com/implied-get', [], ''], [$requests[1]->method, $requests[1]->url, $requests[1]->headers, $requests[1]->body]);
        self::assertSame(['PUT', ':8080/x', ['Accept' => '*/*']], [$requests[2]->method, $requests[2]->url, $requests[2]->headers]);
        self::assertSame('{"from": "file"}', $requests[2]->body);
        self::assertSame(['Error: referenced file not found: /no/such/file.json'], $warnings);
        self::assertSame(['CONNECT', 'proxy.test:443', 'line without colon starts the body'], [$requests[3]->method, $requests[3]->url, $requests[3]->body]);

        self::assertSame([], HttpFile::parse(''));
        self::assertSame([], HttpFile::parse("###\n# only a comment\n###"));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function main(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $rc = Main::main(['curl-cffi', ...$args], new Console($out, $err, tty: false));
        rewind($out);
        rewind($err);

        return [$rc, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
