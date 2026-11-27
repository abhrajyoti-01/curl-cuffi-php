<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

use CurlCffi\Ffi\Library;
use FFI;
use FFI\CData;

/**
 * Thin wrapper for libcurl's multi interface: drives several Curl handles
 * at once without threads. Used for streaming responses and AsyncSession.
 */
final class CurlMulti
{
    private const CURLMSG_DONE = 1;

    private FFI $ffi;
    private ?CData $multi;

    /** @var array<int, Curl> pointer address => Curl */
    private array $handles = [];

    public function __construct()
    {
        $this->ffi = Library::ffi();
        $this->multi = $this->ffi->curl_multi_init();
        if ($this->multi === null) {
            throw new CurlError('curl_multi_init() failed');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Start a transfer on $curl. */
    public function add(Curl $curl): void
    {
        $handle = $curl->handle() ?? throw new CurlError('Cannot add a closed handle.');
        $curl->beforePerform();
        $this->check($this->ffi->curl_multi_add_handle($this->multi, $handle), 'add handle');
        $this->handles[self::address($handle)] = $curl;
    }

    public function remove(Curl $curl): void
    {
        $handle = $curl->handle();
        if ($handle === null || $this->multi === null) {
            return;
        }
        $key = self::address($handle);
        if (isset($this->handles[$key])) {
            $this->ffi->curl_multi_remove_handle($this->multi, $handle);
            unset($this->handles[$key]);
        }
    }

    /** Run pending work without blocking. Returns the number of running transfers. */
    public function perform(): int
    {
        $running = $this->ffi->new('int');
        $this->check($this->ffi->curl_multi_perform($this->multi, FFI::addr($running)), 'perform');

        return $running->cdata;
    }

    /** Wait up to $timeoutMs for activity on any transfer. */
    public function poll(int $timeoutMs): void
    {
        $numfds = $this->ffi->new('int');
        $this->check(
            $this->ffi->curl_multi_poll($this->multi, null, 0, $timeoutMs, FFI::addr($numfds)),
            'poll'
        );
    }

    /**
     * Finished transfers since the last call.
     *
     * @return list<array{0: Curl, 1: int}> [handle, CURLcode result]
     */
    public function infoRead(): array
    {
        $done = [];
        $left = $this->ffi->new('int');
        while (true) {
            $msg = $this->ffi->curl_multi_info_read($this->multi, FFI::addr($left));
            if ($msg === null) {
                break;
            }
            if ($msg->msg !== self::CURLMSG_DONE) {
                continue;
            }
            $key = self::address($msg->easy_handle);
            if (isset($this->handles[$key])) {
                $done[] = [$this->handles[$key], (int) $msg->data->result];
            }
        }

        return $done;
    }

    public function count(): int
    {
        return count($this->handles);
    }

    public function close(): void
    {
        if ($this->multi === null) {
            return;
        }
        foreach ($this->handles as $curl) {
            $this->remove($curl);
        }
        $this->ffi->curl_multi_cleanup($this->multi);
        $this->multi = null;
    }

    private static function address(CData $pointer): int
    {
        return (int) Library::ffi()->cast('uintptr_t', $pointer)->cdata;
    }

    private function check(int $code, string $action): void
    {
        if ($code !== 0) {
            $message = $this->ffi->curl_multi_strerror($code);
            $text = $message instanceof CData ? FFI::string($message) : (string) $message;

            throw new CurlError("Failed to {$action}, curl multi: ({$code}) {$text}", $code);
        }
    }
}
