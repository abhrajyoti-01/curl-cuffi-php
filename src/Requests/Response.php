<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Curl\Curl;
use CurlCffi\Requests\Exceptions\HTTPError;
use CurlCffi\Requests\Exceptions\JSONDecodeError;

/**
 * What the server sent back, like curl_cffi.requests.Response.
 *
 * Property names are camelCase; the Python snake_case names also work for
 * reading (`$r->status_code`), and so do the Python method names
 * (`$r->raise_for_status()`).
 *
 * @property-read string      $text            body decoded to UTF-8
 * @property      string      $encoding        encoding used for $text
 * @property      string      $charset         same as $encoding
 * @property-read ?string     $charsetEncoding charset from the Content-Type header
 * @property-read bool        $isRedirect
 */
class Response
{
    private const REDIRECT_STATI = [301, 302, 303, 307, 308];

    public string $url = '';
    /** Raw body bytes. Empty for streamed responses until read() or iteration. */
    public string $content = '';
    public int $statusCode = 200;
    public string $reason = 'OK';
    /** Whether statusCode is in [200, 400). */
    public bool $ok = true;
    public Headers $headers;
    /** Cookies set by this response (Set-Cookie headers). */
    public Cookies $cookies;
    /** Seconds the transfer took. */
    public float $elapsed = 0.0;
    /** @var string|callable(string): string encoding when the Content-Type has no charset */
    public mixed $defaultEncoding = 'utf-8';
    public int $redirectCount = 0;
    public string $redirectUrl = '';
    /** A CurlHttpVersion constant. */
    public int $httpVersion = 0;
    public string $primaryIp = '';
    public int $primaryPort = 0;
    public string $localIp = '';
    public int $localPort = 0;
    /** @var list<Response> redirect responses, oldest first, without bodies */
    public array $history = [];
    /** @var array<int, mixed> values of the session's extra curl_infos */
    public array $infos = [];
    public int $downloadSize = 0;
    public int $uploadSize = 0;
    public int $headerSize = 0;
    public int $requestSize = 0;
    public int $responseSize = 0;
    public ?Request $request = null;

    private ?string $textCache = null;
    private ?string $encodingValue = null;
    private ?StreamTransfer $stream = null;

    public function __construct(public ?Curl $curl = null, ?Request $request = null)
    {
        $this->headers = new Headers();
        $this->cookies = new Cookies();
        $this->request = $request;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'text' => $this->text(),
            'encoding', 'charset' => $this->encoding(),
            'charsetEncoding', 'charset_encoding' => $this->charsetEncoding(),
            'isRedirect', 'is_redirect' => $this->isRedirect(),
            'status_code' => $this->statusCode,
            'default_encoding' => $this->defaultEncoding,
            'redirect_count' => $this->redirectCount,
            'redirect_url' => $this->redirectUrl,
            'http_version' => $this->httpVersion,
            'primary_ip' => $this->primaryIp,
            'primary_port' => $this->primaryPort,
            'local_ip' => $this->localIp,
            'local_port' => $this->localPort,
            'download_size' => $this->downloadSize,
            'upload_size' => $this->uploadSize,
            'header_size' => $this->headerSize,
            'request_size' => $this->requestSize,
            'response_size' => $this->responseSize,
            default => throw new \LogicException("Undefined property Response::\${$name}"),
        };
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === 'encoding' || $name === 'charset') {
            $this->setEncoding((string) $value);

            return;
        }
        throw new \LogicException("Cannot set Response::\${$name}");
    }

    /** @param array<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return match ($name) {
            'raise_for_status' => $this->raiseForStatus(),
            'iter_content' => $this->iterContent(...$arguments),
            'iter_lines' => $this->iterLines(...$arguments),
            default => throw new \BadMethodCallException("Undefined method Response::{$name}()"),
        };
    }

    /**
     * The page's main content as Markdown, like Python's Response.markdown():
     * readability picks the article and its title, then they are converted
     * to Markdown as "<h1>title</h1><main>article</main>".
     *
     * Needs two optional packages, as Python needs its "extra" dependencies:
     *     composer require fivefilters/readability.php league/html-to-markdown
     */
    public function markdown(): string
    {
        if (!class_exists(\fivefilters\Readability\Readability::class)
            || !class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            throw new \LogicException(
                'Response::markdown() needs: composer require fivefilters/readability.php league/html-to-markdown'
            );
        }
        $html = $this->text();
        $readability = new \fivefilters\Readability\Readability(new \fivefilters\Readability\Configuration());
        try {
            $readability->parse($html);
            $title = (string) $readability->getTitle();
            $summary = (string) $readability->getContent();
        } catch (\fivefilters\Readability\ParseException) {
            // No article found (a short page): use the whole body.
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? html_entity_decode(trim($m[1])) : '';
            $summary = preg_match('~<body[^>]*>(.*)</body>~is', $html, $m) ? $m[1] : $html;
        }
        $converter = new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'head script style']);

        $markdown = $converter->convert('<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) . "</h1><main>{$summary}</main>");

        // Plain characters rather than HTML entities, as Python's markdownify
        // writes them, with heading underlines as long as the decoded text.
        $lines = explode("\n", html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (preg_match('/^(=+|-+)$/', $lines[$i]) && trim($lines[$i - 1]) !== '' && !preg_match('/^(=+|-+)$/', $lines[$i - 1])) {
                $lines[$i] = str_repeat($lines[$i][0], max(3, (int) preg_match_all('/./us', $lines[$i - 1])));
            }
        }

        return implode("\n", $lines);
    }

    /** Body decoded to UTF-8 using encoding(); invalid bytes are replaced. */
    public function text(): string
    {
        if ($this->textCache === null) {
            $this->textCache = $this->content === '' ? '' : self::decode($this->content, $this->encoding());
        }

        return $this->textCache;
    }

    /**
     * The encoding for text(): set explicitly, else the Content-Type charset,
     * else defaultEncoding (a string, or a callable that guesses from the bytes).
     */
    public function encoding(): string
    {
        if ($this->encodingValue === null) {
            $encoding = $this->charsetEncoding();
            if ($encoding === null) {
                $encoding = is_callable($this->defaultEncoding)
                    ? (string) ($this->defaultEncoding)($this->content)
                    : (string) $this->defaultEncoding;
            }
            $this->encodingValue = $encoding !== '' ? $encoding : 'utf-8';
        }

        return $this->encodingValue;
    }

    public function setEncoding(string $encoding): void
    {
        if ($this->textCache !== null) {
            throw new \LogicException('Cannot set encoding after text has been accessed');
        }
        $this->encodingValue = $encoding;
    }

    /** Charset from the Content-Type header, if any. */
    public function charsetEncoding(): ?string
    {
        $contentType = $this->headers->get('Content-Type');
        if ($contentType !== null && preg_match('/charset=["\']?([\w.:-]+)/i', $contentType, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Throw HTTPError unless the status code is in [200, 400). */
    public function raiseForStatus(): void
    {
        if (!$this->ok) {
            throw new HTTPError("HTTP Error {$this->statusCode}: {$this->reason}", 0, $this);
        }
    }

    public function isRedirect(): bool
    {
        return $this->headers->has('location') && in_array($this->statusCode, self::REDIRECT_STATI, true);
    }

    /**
     * Decode the body as JSON. Returns arrays by default, like Python dicts;
     * pass $associative: false for stdClass objects.
     */
    public function json(bool $associative = true, int $depth = 512, int $flags = 0): mixed
    {
        try {
            return json_decode($this->text(), $associative, $depth, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JSONDecodeError('Failed to decode JSON: ' . $e->getMessage(), 0, $this, $e);
        }
    }

    /**
     * Iterate over the body. For streamed responses chunks come straight from
     * the network; otherwise the buffered content is split.
     *
     * @return \Generator<int, string>
     */
    public function iterContent(?int $chunkSize = null, bool $decodeUnicode = false): \Generator
    {
        if ($this->stream === null) {
            $size = $chunkSize ?? max(1, strlen($this->content));
            foreach ($this->content === '' ? [] : str_split($this->content, $size) as $chunk) {
                yield $chunk;
            }

            return;
        }
        // Network chunks are passed through as they arrive; chunkSize is a
        // hint only, as in Python curl_cffi.
        try {
            while (($chunk = $this->stream->next()) !== null) {
                yield $chunk;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Iterate over the body line by line (split on "\n" or $delimiter).
     *
     * @return \Generator<int, string>
     */
    public function iterLines(?int $chunkSize = null, bool $decodeUnicode = false, ?string $delimiter = null): \Generator
    {
        $pending = null;
        foreach ($this->iterContent($chunkSize, $decodeUnicode) as $chunk) {
            if ($pending !== null) {
                $chunk = $pending . $chunk;
            }
            if ($delimiter !== null) {
                $lines = explode($delimiter, $chunk);
            } else {
                $lines = preg_split("/\r\n|\n|\r/", $chunk) ?: [];
            }
            $pending = array_pop($lines);
            if ($pending === '') {
                $pending = null;
            }
            foreach ($lines as $line) {
                yield $line;
            }
        }
        if ($pending !== null) {
            yield $pending;
        }
    }

    /** Read the rest of a streamed body into $content and return it. */
    public function read(): string
    {
        if ($this->stream !== null) {
            $buffer = '';
            foreach ($this->iterContent() as $chunk) {
                $buffer .= $chunk;
            }
            $this->content = $buffer;
            $this->textCache = null;
        }

        return $this->content;
    }

    /** Stop a streamed transfer and free its handle. Safe to call more than once. */
    public function close(): void
    {
        if ($this->stream !== null) {
            $this->stream->close();
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @internal */
    public function attachStream(StreamTransfer $stream): void
    {
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        return "<Response [{$this->statusCode}]>";
    }

    private static function decode(string $content, string $encoding): string
    {
        $normalized = strtolower(str_replace('_', '-', $encoding));
        if (in_array($normalized, ['utf-8', 'utf8'], true)) {
            if (function_exists('mb_scrub')) {
                return mb_scrub($content, 'UTF-8');
            }

            return preg_match('//u', $content) ? $content : (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // unknown encoding name, fall through
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $content);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return self::decode($content, 'utf-8');
    }
}
