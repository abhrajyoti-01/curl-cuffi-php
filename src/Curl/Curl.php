<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

use Closure;
use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Ffi\Library;
use CurlCffi\Impersonate\BrowserType;
use FFI;
use FFI\CData;

/**
 * Low-level wrapper for libcurl's curl_easy_* functions, the PHP twin of
 * Python's curl_cffi.Curl.
 *
 *     $c = new Curl();
 *     $c->impersonate('chrome');
 *     $c->setopt(CurlOpt::URL, 'https://example.com');
 *     $body = '';
 *     $c->setopt(CurlOpt::WRITEFUNCTION, function (string $chunk) use (&$body) { $body .= $chunk; });
 *     $c->perform();
 *     $status = $c->getinfo(CurlInfo::RESPONSE_CODE);
 */
class Curl
{
    private const ERROR_BUFFER_SIZE = 256; // CURL_ERROR_SIZE

    /** Options whose value is a list of strings sent as a curl_slist. */
    private const SLIST_OPTIONS = [
        CurlOpt::HTTPHEADER,
        CurlOpt::PROXYHEADER,
        CurlOpt::RESOLVE,
        CurlOpt::CONNECT_TO,
        CurlOpt::HTTP200ALIASES,
        CurlOpt::QUOTE,
        CurlOpt::POSTQUOTE,
        CurlOpt::PREQUOTE,
        CurlOpt::MAIL_RCPT,
        CurlOpt::TELNETOPTIONS,
    ];

    /** Slists freed by perform(clearHeaders: true). */
    private const HEADER_OPTIONS = [CurlOpt::HTTPHEADER, CurlOpt::PROXYHEADER];

    /** Slists freed by perform(clearResolve: true). */
    private const RESOLVE_OPTIONS = [CurlOpt::RESOLVE, CurlOpt::CONNECT_TO];

    private FFI $ffi;
    private ?CData $handle;
    private CData $errorBuffer;
    private string $cacert;
    private bool $isCertSet = false;

    /** @var array<int, CData> option => curl_slist* */
    private array $slists = [];

    /**
     * Keeps PHP values that libcurl points at (callbacks, POSTFIELDS) alive.
     *
     * @var array<int, mixed>
     */
    private array $keepAlive = [];

    /** First exception thrown inside a callback during perform(). */
    private ?\Throwable $callbackException = null;

    /**
     * @param string     $cacert CA bundle path; by default Library::caBundle() is used
     * @param bool       $debug  print libcurl's verbose output to STDERR
     * @param CData|null $handle an existing CURL* handle to wrap
     */
    public function __construct(string $cacert = '', bool $debug = false, ?CData $handle = null)
    {
        $this->ffi = Library::ffi();
        $this->handle = $handle ?? $this->ffi->curl_easy_init();
        if ($this->handle === null) {
            throw new CurlError('curl_easy_init() failed');
        }
        $this->cacert = $cacert !== '' ? $cacert : (string) Library::caBundle();
        $this->setErrorBuffer();
        if ($debug) {
            $this->debug();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Turn on verbose output to STDERR. */
    public function debug(): void
    {
        $this->setopt(CurlOpt::VERBOSE, 1);
    }

    /**
     * Wrapper for curl_easy_setopt().
     *
     * Value types follow Python curl_cffi:
     *  - long and curl_off_t options take int|bool
     *  - string options take string (or null to unset)
     *  - list options (HTTPHEADER, PROXYHEADER, RESOLVE, ...) take list<string>;
     *    like Python, repeated calls append until perform() clears them
     *  - WRITEFUNCTION / HEADERFUNCTION take callable(string $chunk): ?int
     *    (return null or the chunk length to continue, anything else aborts)
     *  - WRITEDATA / HEADERDATA take a writable stream resource
     *  - READFUNCTION takes callable(int $maxBytes): string, READDATA a readable stream
     *  - XFERINFOFUNCTION takes callable(int $dlTotal, int $dlNow, int $ulTotal, int $ulNow): ?int
     *  - DEBUGFUNCTION takes true (print to STDERR) or callable(int $type, string $data)
     *
     * @return int 0 on success; failures throw CurlError
     */
    public function setopt(int $option, mixed $value): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $type = intdiv($option, 10000) * 10000;

        switch ($option) {
            case CurlOpt::WRITEFUNCTION:
            case CurlOpt::HEADERFUNCTION:
                return $this->setWriteCallback($option, self::toCallable($value), $option);
            case CurlOpt::WRITEDATA:
            case CurlOpt::HEADERDATA:
                $stream = self::toStream($value, $option);
                $fn = static function (string $chunk) use ($stream): int {
                    return (int) fwrite($stream, $chunk);
                };
                $cbOption = $option === CurlOpt::WRITEDATA ? CurlOpt::WRITEFUNCTION : CurlOpt::HEADERFUNCTION;

                return $this->setWriteCallback($cbOption, $fn, $option);
            case CurlOpt::READFUNCTION:
                return $this->setReadCallback(self::toCallable($value));
            case CurlOpt::READDATA:
                $stream = self::toStream($value, $option);

                return $this->setReadCallback(static fn (int $max): string => (string) fread($stream, max(1, $max)));
            case CurlOpt::XFERINFOFUNCTION:
                return $this->setXferInfoCallback(self::toCallable($value));
            case CurlOpt::DEBUGFUNCTION:
                return $this->setDebugCallback($value === true ? self::defaultDebug(...) : self::toCallable($value));
        }

        if (in_array($option, self::SLIST_OPTIONS, true)) {
            return $this->setSlist($option, $value);
        }

        if ($type === 0 || $type === 30000) {
            // long (0) or curl_off_t (30000)
            if (!is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException(sprintf('Option %s expects int|bool', self::optName($option)));
            }
            // PHP passes an int vararg as a 64-bit integer, which libcurl reads
            // correctly as long or curl_off_t on all supported 64-bit ABIs.
            return $this->check($this->ffi->curl_easy_setopt($this->handle, $option, (int) $value), 'setopt', $option);
        }

        if ($type === 10000) {
            if ($value === null) {
                $ret = $this->ffi->curl_easy_setopt($this->handle, $option, $this->ffi->cast('char *', null));

                return $this->check($ret, 'setopt', $option);
            }
            if ($value instanceof CData) {
                $this->keepAlive[$option] = $value;

                return $this->check($this->ffi->curl_easy_setopt($this->handle, $option, $value), 'setopt', $option);
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('Option %s expects string', self::optName($option)));
            }
            [$owner, $c] = $this->cString($value);
            // libcurl copies string options, except POSTFIELDS which it points at.
            if ($option === CurlOpt::POSTFIELDS) {
                $this->keepAlive[$option] = $owner;
                $this->ffi->curl_easy_setopt($this->handle, CurlOpt::POSTFIELDSIZE_LARGE, strlen($value));
            }
            $ret = $this->check($this->ffi->curl_easy_setopt($this->handle, $option, $c), 'setopt', $option);
            if ($option === CurlOpt::CAINFO) {
                $this->isCertSet = true;
            }

            return $ret;
        }

        if ($type === 40000) {
            // struct curl_blob { void *data; size_t len; unsigned int flags; }, CURL_BLOB_COPY = 1
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('Option %s expects string', self::optName($option)));
            }
            $blob = $this->ffi->new('struct { void *data; size_t len; unsigned int flags; }');
            [$owner, $data] = $this->cString($value);
            $blob->data = $this->ffi->cast('void *', $data);
            $blob->len = strlen($value);
            $blob->flags = 1;
            $ret = $this->ffi->curl_easy_setopt($this->handle, $option, FFI::addr($blob));

            return $this->check($ret, 'setopt', $option);
        }

        throw new \InvalidArgumentException('Option unsupported: ' . self::optName($option));
    }

    /**
     * Wrapper for curl_easy_getinfo(). Returns string, int, float or list<string>
     * depending on the info type, like Python curl_cffi.
     */
    public function getinfo(int $option): string|int|float|array
    {
        $type = $option & 0xF00000;
        if ($this->handle === null) {
            return match ($type) {
                0x100000 => '',
                0x300000 => 0.0,
                0x400000 => [],
                default => 0,
            };
        }

        $ctype = match ($type) {
            0x100000 => 'char *',
            0x200000 => 'long',
            0x300000 => 'double',
            0x400000 => 'struct curl_slist *',
            0x500000 => 'uint64_t', // curl_socket_t, widened
            0x600000 => 'int64_t',
            default => throw new \InvalidArgumentException('Unknown info type for ' . $option),
        };
        $out = $this->ffi->new($ctype);
        $this->check($this->ffi->curl_easy_getinfo($this->handle, $option, FFI::addr($out)), 'getinfo', $option);

        switch ($type) {
            case 0x100000:
                return FFI::isNull($out) ? '' : FFI::string($out);
            case 0x300000:
                return (float) $out->cdata;
            case 0x400000:
                $list = self::slistToArray($out);
                if (!FFI::isNull($out)) {
                    $this->ffi->curl_slist_free_all($out);
                }

                return $list;
            case 0x500000:
                $socket = $out->cdata;
                $bad = PHP_OS_FAMILY === 'Windows' ? -1 : 0xFFFFFFFF;

                return ($socket === $bad || $socket === -1) ? -1 : $socket;
            default:
                return (int) $out->cdata;
        }
    }

    /** libcurl version string. */
    public function version(): string
    {
        return Library::version();
    }

    /**
     * Impersonate a browser: sets its TLS ClientHello, HTTP/2 settings and,
     * with $defaultHeaders, its default headers (User-Agent, Accept, ...).
     *
     * @return int 0 on success
     */
    public function impersonate(BrowserType|string $target, bool $defaultHeaders = true): int
    {
        if ($this->handle === null) {
            return 0;
        }
        $ffi = Library::impersonateFfi();
        if ($ffi === null) {
            throw new CurlError(
                'The loaded libcurl (' . Library::loadedPath() . ') has no curl_easy_impersonate(); '
                . 'load libcurl-impersonate instead.'
            );
        }
        $name = BrowserType::resolve($target);
        $ret = $ffi->curl_easy_impersonate($this->handle, $name, $defaultHeaders ? 1 : 0);
        if ($ret !== 0) {
            throw new CurlError("Failed to impersonate \"{$name}\", curl: ({$ret}) " . $this->strerror($ret), $ret);
        }

        return $ret;
    }

    /**
     * Wrapper for curl_easy_perform().
     *
     * @param bool $clearHeaders free HTTPHEADER/PROXYHEADER lists afterwards
     * @param bool $clearResolve free RESOLVE/CONNECT_TO lists afterwards
     *
     * @throws CurlError when the transfer fails
     */
    public function perform(bool $clearHeaders = true, bool $clearResolve = true): void
    {
        if ($this->handle === null) {
            throw new CurlError('Cannot perform request on closed handle.');
        }
        $this->ensureCacert();
        $this->callbackException = null;
        FFI::memset($this->errorBuffer, 0, self::ERROR_BUFFER_SIZE);

        $ret = $this->ffi->curl_easy_perform($this->handle);

        try {
            if ($this->callbackException !== null) {
                throw $this->callbackException;
            }
            $this->check($ret, 'perform');
        } finally {
            $this->callbackException = null;
            $this->cleanHandlesAndBuffers($clearHeaders, $clearResolve);
        }
    }

    /** Free per-request lists, like Python's clean_handles_and_buffers(). */
    public function cleanHandlesAndBuffers(bool $clearHeaders = true, bool $clearResolve = true): void
    {
        $toFree = [];
        if ($clearHeaders) {
            array_push($toFree, ...self::HEADER_OPTIONS);
        }
        if ($clearResolve) {
            array_push($toFree, ...self::RESOLVE_OPTIONS);
        }
        foreach ($toFree as $option) {
            if (isset($this->slists[$option])) {
                $this->ffi->curl_easy_setopt($this->handle, $option, $this->ffi->cast('void *', null));
                $this->ffi->curl_slist_free_all($this->slists[$option]);
                unset($this->slists[$option]);
            }
        }
    }

    /** Wrapper for curl_easy_pause(). */
    public function pause(int $bitmask): int
    {
        return $this->handle === null ? 0 : $this->ffi->curl_easy_pause($this->handle, $bitmask);
    }

    /**
     * Wrapper for curl_easy_duphandle(). Options are copied; callbacks are
     * PHP closures bound to this object, so set them again on the copy.
     */
    public function duphandle(): self
    {
        if ($this->handle === null) {
            throw new CurlError('Cannot duplicate a closed handle.');
        }

        return new self($this->cacert, false, $this->ffi->curl_easy_duphandle($this->handle));
    }

    /** Wrapper for curl_easy_reset(): back to a fresh handle state. */
    public function reset(): void
    {
        if ($this->handle === null) {
            return;
        }
        $this->ffi->curl_easy_reset($this->handle);
        $this->freeAllSlists();
        $this->keepAlive = [];
        $this->isCertSet = false;
        $this->setErrorBuffer();
    }

    /** Wrapper for curl_easy_cleanup(). Safe to call more than once. */
    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        $this->ffi->curl_easy_cleanup($this->handle);
        $this->handle = null;
        $this->freeAllSlists();
        $this->keepAlive = [];
    }

    /** The raw CURL* pointer, for CurlMulti. */
    public function handle(): ?CData
    {
        return $this->handle;
    }

    /** Human-readable message for a CURLcode. */
    public function strerror(int $code): string
    {
        // PHP FFI returns `const char *` results as PHP strings already.
        $message = $this->ffi->curl_easy_strerror($code);

        return $message instanceof CData ? FFI::string($message) : (string) $message;
    }

    /**
     * Parse "HTTP/1.1 200 OK" into [CurlHttpVersion constant, status code, reason].
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public static function parseStatusLine(string $statusLine): array
    {
        if (!preg_match('#^HTTP/(\d(?:\.\d)?) ([0-9]{3}) ?(.*)$#', rtrim($statusLine, "\r\n"), $m)) {
            return [CurlHttpVersion::NONE, 0, ''];
        }
        $version = match ($m[1]) {
            '1.0' => CurlHttpVersion::V1_0,
            '1.1' => CurlHttpVersion::V1_1,
            '2', '2.0' => CurlHttpVersion::V2_0,
            '3', '3.0' => CurlHttpVersion::V3,
            default => CurlHttpVersion::NONE,
        };

        return [$version, (int) $m[2], $m[3]];
    }

    /** Reason phrase of a status line, e.g. "Not Found". */
    public static function getReasonPhrase(string $statusLine): string
    {
        return self::parseStatusLine($statusLine)[2];
    }

    // ---------------------------------------------------------------- internals

    private function setErrorBuffer(): void
    {
        $this->errorBuffer = $this->ffi->new('char[' . self::ERROR_BUFFER_SIZE . ']');
        $ptr = $this->ffi->cast('char *', FFI::addr($this->errorBuffer));
        $this->ffi->curl_easy_setopt($this->handle, CurlOpt::ERRORBUFFER, $ptr);
    }

    private function ensureCacert(): void
    {
        if ($this->isCertSet || $this->cacert === '') {
            return;
        }
        $this->setopt(CurlOpt::CAINFO, $this->cacert);
        $this->setopt(CurlOpt::PROXY_CAINFO, $this->cacert);
    }

    private function check(int $code, string $action, ?int $option = null): int
    {
        if ($code === 0) {
            return 0;
        }
        $message = FFI::string($this->errorBuffer);
        if ($message === '') {
            $message = $this->strerror($code);
        }
        $what = $option === null ? $action : $action . ' ' . self::optName($option);

        throw new CurlError(
            "Failed to {$what}, curl: ({$code}) {$message}. "
            . 'See https://curl.se/libcurl/c/libcurl-errors.html first for more details.',
            $code
        );
    }

    /** @param callable(string): mixed $fn */
    private function setWriteCallback(int $cbOption, callable $fn, int $keepKey): int
    {
        $holder = $this->ffi->new('cffi_write_holder');
        $holder->fn = function (CData $ptr, int $size, int $nmemb, $userdata) use ($fn): int {
            $length = $size * $nmemb;
            if ($this->callbackException !== null) {
                return 0;
            }
            try {
                $ret = $fn($length > 0 ? FFI::string($ptr, $length) : '');
            } catch (\Throwable $e) {
                $this->callbackException = $e;

                return 0; // aborts the transfer with CURLE_WRITE_ERROR
            }

            return ($ret === null || $ret === true) ? $length : (int) $ret;
        };
        $this->keepAlive[$cbOption] = $holder;
        $this->keepAlive[$keepKey] = $holder;
        $ret = $this->ffi->curl_easy_setopt($this->handle, $cbOption, $holder->fn);

        return $this->check($ret, 'setopt', $cbOption);
    }

    /** @param callable(int): string $fn */
    private function setReadCallback(callable $fn): int
    {
        $holder = $this->ffi->new('cffi_read_holder');
        $holder->fn = function (CData $buffer, int $size, int $nitems, $userdata) use ($fn): int {
            $max = $size * $nitems;
            try {
                $data = (string) $fn($max);
            } catch (\Throwable $e) {
                $this->callbackException = $e;

                return 0x10000000; // CURL_READFUNC_ABORT
            }
            $length = min(strlen($data), $max);
            if ($length > 0) {
                FFI::memcpy($buffer, $data, $length);
            }

            return $length;
        };
        $this->keepAlive[CurlOpt::READFUNCTION] = $holder;
        $ret = $this->ffi->curl_easy_setopt($this->handle, CurlOpt::READFUNCTION, $holder->fn);
        return $this->check($ret, 'setopt', CurlOpt::READFUNCTION);
    }

    /** @param callable(int, int, int, int): mixed $fn */
    private function setXferInfoCallback(callable $fn): int
    {
        $holder = $this->ffi->new('cffi_xferinfo_holder');
        $holder->fn = function ($clientp, int $dlTotal, int $dlNow, int $ulTotal, int $ulNow) use ($fn): int {
            try {
                return (int) ($fn($dlTotal, $dlNow, $ulTotal, $ulNow) ?? 0);
            } catch (\Throwable $e) {
                $this->callbackException = $e;

                return 1;
            }
        };
        $this->keepAlive[CurlOpt::XFERINFOFUNCTION] = $holder;
        $ret = $this->ffi->curl_easy_setopt($this->handle, CurlOpt::XFERINFOFUNCTION, $holder->fn);
        $this->check($ret, 'setopt', CurlOpt::XFERINFOFUNCTION);

        return $this->setopt(CurlOpt::NOPROGRESS, 0);
    }

    /** @param callable(int, string): mixed $fn */
    private function setDebugCallback(callable $fn): int
    {
        $holder = $this->ffi->new('cffi_debug_holder');
        $holder->fn = function ($handle, int $type, CData $data, int $size, $userptr) use ($fn): int {
            try {
                $fn($type, $size > 0 ? FFI::string($data, $size) : '');
            } catch (\Throwable) {
                // Debug output must never break a transfer.
            }

            return 0;
        };
        $this->keepAlive[CurlOpt::DEBUGFUNCTION] = $holder;
        $ret = $this->ffi->curl_easy_setopt($this->handle, CurlOpt::DEBUGFUNCTION, $holder->fn);
        $this->check($ret, 'setopt', CurlOpt::DEBUGFUNCTION);

        return $this->setopt(CurlOpt::VERBOSE, 1);
    }

    private function setSlist(int $option, mixed $value): int
    {
        if ($value === null || $value === []) {
            if (isset($this->slists[$option])) {
                $this->ffi->curl_easy_setopt($this->handle, $option, $this->ffi->cast('void *', null));
                $this->ffi->curl_slist_free_all($this->slists[$option]);
                unset($this->slists[$option]);
            }

            return 0;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Option %s expects list<string>', self::optName($option)));
        }
        $list = $this->slists[$option] ?? null;
        foreach ($value as $item) {
            $next = $this->ffi->curl_slist_append($list, (string) $item);
            if ($next === null) {
                throw new CurlError('curl_slist_append() failed');
            }
            $list = $next;
        }
        $this->slists[$option] = $list;

        return $this->check($this->ffi->curl_easy_setopt($this->handle, $option, $list), 'setopt', $option);
    }

    private function freeAllSlists(): void
    {
        foreach ($this->slists as $list) {
            $this->ffi->curl_slist_free_all($list);
        }
        $this->slists = [];
    }

    /**
     * NUL-terminated C copy of a PHP string. Returns [owner, char* pointer];
     * the buffer lives as long as the owner CData is referenced.
     *
     * @return array{0: CData, 1: CData}
     */
    private function cString(string $value): array
    {
        $length = strlen($value);
        $owner = $this->ffi->new('char[' . ($length + 1) . ']');
        if ($length > 0) {
            FFI::memcpy($owner, $value, $length);
        }

        return [$owner, $this->ffi->cast('char *', FFI::addr($owner))];
    }

    /** @return list<string> */
    private static function slistToArray(CData $head): array
    {
        $items = [];
        $node = $head;
        while (!FFI::isNull($node)) {
            $items[] = FFI::string($node->data);
            $node = $node->next;
        }

        return $items;
    }

    private static function toCallable(mixed $value): Closure
    {
        if (!is_callable($value)) {
            throw new \InvalidArgumentException('Expected a callable');
        }

        return Closure::fromCallable($value);
    }

    /** @return resource */
    private static function toStream(mixed $value, int $option)
    {
        if (!is_resource($value)) {
            throw new \InvalidArgumentException(sprintf('Option %s expects a stream resource', self::optName($option)));
        }

        return $value;
    }

    private static function defaultDebug(int $type, string $data): void
    {
        // CURLINFO_TEXT 0, HEADER_IN 1, HEADER_OUT 2, DATA_IN 3, DATA_OUT 4, SSL_DATA_IN 5, SSL_DATA_OUT 6
        $prefix = ['* ', '< ', '> ', '{ ', '} ', '<<< ', '>>> '][$type] ?? '';
        if ($type >= 3) {
            fwrite(STDERR, $prefix . strlen($data) . " bytes\n");

            return;
        }
        fwrite(STDERR, $prefix . rtrim($data, "\r\n") . "\n");
    }

    private static function optName(int $option): string
    {
        return 'CurlOpt::' . (CurlOpt::nameOf($option) ?? (string) $option);
    }
}
