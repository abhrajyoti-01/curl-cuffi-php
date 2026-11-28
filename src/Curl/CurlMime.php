<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

use CurlCffi\Ffi\Library;
use FFI;
use FFI\CData;

/**
 * Wrapper for libcurl's curl_mime_* API (multipart/form-data), the PHP twin
 * of curl_cffi.CurlMime.
 *
 *     $mp = new CurlMime();
 *     $mp->addpart('avatar', contentType: 'image/png', filename: 'me.png', localPath: '/tmp/me.png');
 *     $mp->addpart('note', data: 'hello');
 *     $session->post($url, multipart: $mp);
 *     $mp->close();
 */
final class CurlMime
{
    private FFI $ffi;
    private ?CData $form;
    private Curl $curl;

    public function __construct(?Curl $curl = null)
    {
        $this->ffi = Library::ffi();
        $this->curl = $curl ?? new Curl();
        $this->form = $this->ffi->curl_mime_init($this->curl->handle());
        if ($this->form === null) {
            throw new CurlError('curl_mime_init() failed');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Add one part. Use either $localPath (file on disk) or $data, not both.
     */
    public function addpart(
        string $name,
        ?string $contentType = null,
        ?string $filename = null,
        ?string $localPath = null,
        ?string $data = null,
    ): void {
        if ($this->form === null) {
            throw new CurlError('This CurlMime is closed.');
        }
        if ($localPath !== null && $data !== null) {
            throw new CurlError('Can not use local_path and data at the same time.');
        }
        $part = $this->ffi->curl_mime_addpart($this->form);
        $this->check($this->ffi->curl_mime_name($part, $name));
        if ($contentType !== null) {
            $this->check($this->ffi->curl_mime_type($part, $contentType));
        }
        if ($localPath !== null) {
            if (!is_file($localPath)) {
                throw new CurlError("File not found at {$localPath}");
            }
            $this->check($this->ffi->curl_mime_filedata($part, $localPath));
        }
        if ($filename !== null) {
            $this->check($this->ffi->curl_mime_filename($part, $filename));
        }
        if ($data !== null) {
            // libcurl copies the data, so the PHP string may go away afterwards.
            $this->check($this->ffi->curl_mime_data($part, $data, strlen($data)));
        }
    }

    /**
     * Build a form from a list of parts, each an array with the keys of addpart()
     * (snake_case keys such as content_type and local_path work too).
     *
     * @param list<array<string, string>> $parts
     */
    public static function fromList(array $parts): self
    {
        $form = new self();
        foreach ($parts as $part) {
            $form->addpart(
                name: (string) $part['name'],
                contentType: $part['contentType'] ?? $part['content_type'] ?? null,
                filename: $part['filename'] ?? null,
                localPath: $part['localPath'] ?? $part['local_path'] ?? null,
                data: isset($part['data']) ? (string) $part['data'] : null,
            );
        }

        return $form;
    }

    /** Attach the form to a Curl handle as CURLOPT_MIMEPOST. */
    public function attach(?Curl $curl = null): void
    {
        if ($this->form === null) {
            throw new CurlError('This CurlMime is closed.');
        }
        ($curl ?? $this->curl)->setopt(Const\CurlOpt::MIMEPOST, $this->form);
    }

    /** The raw curl_mime* pointer. */
    public function form(): ?CData
    {
        return $this->form;
    }

    /** Free the form. Call after the request that used it has finished. */
    public function close(): void
    {
        if ($this->form !== null) {
            $this->ffi->curl_mime_free($this->form);
            $this->form = null;
        }
    }

    private function check(int $code): void
    {
        if ($code !== 0) {
            throw new CurlError('Add field failed.', $code);
        }
    }
}
