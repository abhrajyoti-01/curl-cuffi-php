<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Cache;

/**
 * Keeps each cached response as a HAR JSON file named after its key, like
 * Python's FileCacheBackend. The default folder is the same as Python's,
 * "curl_cffi_cache" in the system temp folder, and so are the files.
 *
 *     $s = new Session(cache: 3600);    // FileCacheBackend, entries fresh for an hour
 *     $s = new Session(cache: new FileCacheBackend(expires: new \DateInterval('P1D'), path: '/var/cache/http'));
 */
class FileCacheBackend extends CacheBackend
{
    public readonly string $path;

    /**
     * @param list<string>|null $methods
     * @param list<string>|null $ignored
     */
    public function __construct(
        int|float|\DateInterval $expires,
        ?string $path = null,
        ?array $methods = null,
        ?array $ignored = null,
    ) {
        parent::__construct($expires, $methods, $ignored);
        $this->path = $path ?? self::defaultPath();
        if (!is_dir($this->path) && !@mkdir($this->path, 0777, true) && !is_dir($this->path)) {
            throw new \RuntimeException("Cannot create the cache folder {$this->path}");
        }
    }

    public static function defaultPath(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'curl_cffi_cache';
    }

    public function clear(): void
    {
        foreach (glob($this->path . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (preg_match('/^[0-9a-f]{64}$/', basename($file, '.json'))) {
                @unlink($file);
            }
        }
    }

    protected function readPayload(string $key): ?array
    {
        $file = $this->filePath($key);
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $entries = $payload['log']['entries'] ?? null;
            if (!is_array($entries) || $entries === []) {
                throw new \UnexpectedValueException('empty HAR entries');
            }
            self::parseTimestamp((string) $entries[0]['startedDateTime']);

            return $payload;
        } catch (\Throwable) {
            // A broken entry is dropped, as in Python.
            @unlink($file);

            return null;
        }
    }

    protected function writePayload(string $key, array $payload): void
    {
        $file = $this->filePath($key);
        $tmp = substr($file, 0, -5) . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Cannot write the cache file {$tmp}");
        }
        // Replace in one step, so a reader never sees half a file.
        if (!@rename($tmp, $file)) {
            @unlink($file);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot write the cache file {$file}");
            }
        }
    }

    protected function deletePayload(string $key): void
    {
        @unlink($this->filePath($key));
    }

    private function filePath(string $key): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
