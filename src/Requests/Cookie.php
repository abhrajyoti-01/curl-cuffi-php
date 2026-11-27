<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Requests\Exceptions\RequestException;

/**
 * One cookie, convertible to and from libcurl's Netscape cookie-file line
 * (curl_cffi's CurlMorsel).
 */
final class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public string $domain = '',
        public string $path = '/',
        public bool $secure = false,
        public int $expires = 0,
        public bool $httpOnly = false,
        /** Whether the cookie also applies to subdomains of $domain. */
        public bool $subdomains = false,
    ) {
    }

    /** Parse "domain\tTRUE\t/\tFALSE\t0\tname\tvalue". */
    public static function fromCurlFormat(string $line): self
    {
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (count($parts) < 7) {
            throw new \InvalidArgumentException("Invalid curl cookie line: {$line}");
        }
        [$host, $subdomains, $path, $secure, $expires, $name] = $parts;
        $value = implode("\t", array_slice($parts, 6));
        $httpOnly = false;
        if (str_starts_with($host, '#HttpOnly_')) {
            $httpOnly = true;
            $host = substr($host, 10);
        }

        return new self(
            name: $name,
            value: $value,
            domain: $host,
            path: $path,
            secure: $secure === 'TRUE',
            expires: (int) $expires,
            httpOnly: $httpOnly,
            subdomains: $subdomains === 'TRUE',
        );
    }

    public function toCurlFormat(): string
    {
        if ($this->domain === '') {
            throw new RequestException("Domain not found for cookie {$this->name}={$this->value}");
        }

        return implode("\t", [
            ($this->httpOnly ? '#HttpOnly_' : '') . $this->domain,
            $this->subdomains ? 'TRUE' : 'FALSE',
            $this->path,
            $this->secure ? 'TRUE' : 'FALSE',
            (string) $this->expires,
            $this->name,
            $this->value,
        ]);
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expires > 0 && $this->expires < ($now ?? time());
    }

    /** Key that identifies the cookie slot: same name, domain and path replace each other. */
    public function key(): string
    {
        return strtolower(ltrim($this->domain, '.')) . "\0" . $this->path . "\0" . $this->name;
    }
}
