<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

use CurlCffi\Curl\Const\CurlLockData;
use CurlCffi\Curl\Const\CurlShareCode;
use CurlCffi\Curl\Const\CurlShareOpt;
use CurlCffi\Ffi\Library;
use FFI;
use FFI\CData;

/**
 * Wrapper for libcurl's share interface (curl_share_*), like Python's
 * curl_cffi.CurlShare: handles attached to one share use the same DNS cache,
 * TLS session cache and, if asked, connection pool.
 *
 *     $share = new CurlShare();                  // DNS + TLS sessions
 *     $a = new Session(curl_share: $share);
 *     $b = new AsyncSession(curl_share: $share);
 *
 *     $c = new Curl();
 *     $c->setopt(CurlOpt::SHARE, $share);
 *
 * PHP runs a request on one thread, so no lock callbacks are needed (Python
 * installs them for its threads).
 */
final class CurlShare
{
    private FFI $ffi;
    private ?CData $handle;
    /** cleanup was refused because handles were still attached; retried when they close */
    private bool $cleanupPending = false;

    /**
     * Named arguments, as in Python: connect (default false), dns (true) and
     * ssl_session / sslSession (true).
     *
     * connect shares the connection pool. As in Python, only use it for
     * handles that run one after the other, not in parallel on several
     * curl_multi handles.
     */
    public function __construct(mixed ...$options)
    {
        $flags = ['connect' => false, 'dns' => true, 'ssl_session' => true];
        foreach ($options as $key => $value) {
            $name = is_string($key) ? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key)) : null;
            if ($name === null || !array_key_exists($name, $flags)) {
                throw new \TypeError('CurlShare: unknown option ' . var_export($key, true));
            }
            $flags[$name] = (bool) $value;
        }

        $this->ffi = Library::ffi();
        $this->handle = $this->ffi->curl_share_init();
        if ($this->handle === null) {
            throw new CurlError('Failed to create share handle, curl_share_init returned NULL');
        }
        if ($flags['connect']) {
            $this->share(CurlLockData::CONNECT);
        }
        if ($flags['dns']) {
            $this->share(CurlLockData::DNS);
        }
        if ($flags['ssl_session']) {
            $this->share(CurlLockData::SSL_SESSION);
        }
    }

    public function __destruct()
    {
        if ($this->cleanup() !== CurlShareCode::OK) {
            $this->cleanupPending = true;
        }
    }

    /** Start sharing a CurlLockData kind (DNS, SSL_SESSION, CONNECT, COOKIE, ...). */
    public function share(int $data): void
    {
        $this->setopt(CurlShareOpt::SHARE, $data);
    }

    /** Stop sharing a CurlLockData kind. */
    public function unshare(int $data): void
    {
        $this->setopt(CurlShareOpt::UNSHARE, $data);
    }

    /**
     * curl_share_cleanup(). Close every attached handle first: otherwise this
     * throws CurlError with code CurlShareCode::IN_USE and the share stays
     * open, so you can retry, as in Python.
     */
    public function close(): void
    {
        $code = $this->cleanup();
        if ($code !== CurlShareCode::OK) {
            throw new CurlError('Failed to close share handle: ' . $this->strerror($code), $code);
        }
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    /** @internal the CURLSH* for CURLOPT_SHARE */
    public function handle(): CData
    {
        if ($this->handle === null) {
            throw new CurlError('The share handle is closed.');
        }

        return $this->handle;
    }

    /** @internal called by Curl when an attached handle is cleaned up */
    public function handleDetached(): void
    {
        if ($this->cleanupPending && $this->cleanup() === CurlShareCode::OK) {
            $this->cleanupPending = false;
        }
    }

    private function setopt(int $option, int $value): void
    {
        $code = $this->ffi->curl_share_setopt($this->handle(), $option, $value);
        if ($code !== CurlShareCode::OK) {
            $name = CurlShareOpt::nameOf($option) ?? (string) $option;
            throw new CurlError("Failed to set share option {$name}: " . $this->strerror($code), $code);
        }
    }

    private function cleanup(): int
    {
        if ($this->handle === null) {
            return CurlShareCode::OK;
        }
        $code = $this->ffi->curl_share_cleanup($this->handle);
        if ($code === CurlShareCode::OK) {
            $this->handle = null;
        }

        return $code;
    }

    private function strerror(int $code): string
    {
        $message = $this->ffi->curl_share_strerror($code);

        return $message instanceof CData ? FFI::string($message) : (string) $message;
    }
}
