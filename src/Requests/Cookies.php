<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Requests\Exceptions\CookieConflict;

/**
 * A cookie jar, like curl_cffi.requests.Cookies.
 *
 *     $jar = new Cookies(['session' => 'abc']);
 *     $jar->set('theme', 'dark', domain: 'example.com');
 *     $jar['theme'];                // "dark"
 *     $jar->get('session');         // "abc"
 *
 * @implements \ArrayAccess<string, string>
 * @implements \IteratorAggregate<string, string>
 */
final class Cookies implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<string, Cookie> Cookie::key() => cookie */
    private array $jar = [];

    /** @param Cookies|array<string, string>|list<Cookie>|null $cookies */
    public function __construct(Cookies|array|null $cookies = null)
    {
        $this->update($cookies);
    }

    public function set(string $name, string $value, string $domain = '', string $path = '/', bool $secure = false): void
    {
        $this->setCookie(new Cookie($name, $value, $domain, $path, $secure));
    }

    public function setCookie(Cookie $cookie): void
    {
        $this->jar[$cookie->key()] = $cookie;
    }

    /**
     * Value of cookie $name. With several cookies of that name on unrelated
     * domains, pass $domain or a CookieConflict is thrown, as in Python.
     */
    public function get(string $name, ?string $default = null, ?string $domain = null, ?string $path = null): ?string
    {
        $value = null;
        $matchedDomain = '';
        foreach ($this->matching($name, $domain, $path) as $cookie) {
            if ($value !== null
                && !str_ends_with($matchedDomain, $cookie->domain)
                && !str_ends_with($cookie->domain, $matchedDomain)
                && $value !== $cookie->value) {
                throw new CookieConflict(
                    "Multiple cookies exist with name={$name} on {$matchedDomain} and {$cookie->domain}, "
                    . 'add domain parameter to suppress this error.'
                );
            }
            $value = $cookie->value;
            $matchedDomain = $cookie->domain;
        }

        return $value ?? $default;
    }

    /**
     * Cookies as name => value, optionally only for one domain/path.
     *
     * @return array<string, string>
     */
    public function getDict(?string $domain = null, ?string $path = null): array
    {
        $out = [];
        foreach ($this->jar as $cookie) {
            if (($domain === null || $cookie->domain === $domain) && ($path === null || $cookie->path === $path)) {
                $out[$cookie->name] = $cookie->value;
            }
        }

        return $out;
    }

    public function delete(string $name, ?string $domain = null, ?string $path = null): void
    {
        foreach ($this->matching($name, $domain, $path) as $key => $cookie) {
            unset($this->jar[$key]);
        }
    }

    public function clear(?string $domain = null, ?string $path = null): void
    {
        foreach ($this->jar as $key => $cookie) {
            if (($domain === null || $cookie->domain === $domain) && ($path === null || $cookie->path === $path)) {
                unset($this->jar[$key]);
            }
        }
    }

    /** @param Cookies|array<string, string>|list<Cookie>|null $cookies */
    public function update(Cookies|array|null $cookies): void
    {
        if ($cookies instanceof self) {
            foreach ($cookies->jar as $cookie) {
                $this->setCookie(clone $cookie);
            }

            return;
        }
        foreach ($cookies ?? [] as $name => $value) {
            if ($value instanceof Cookie) {
                $this->setCookie(clone $value);
            } else {
                $this->set((string) $name, (string) $value);
            }
        }
    }

    /** @return list<Cookie> */
    public function all(): array
    {
        return array_values($this->jar);
    }

    /**
     * Cookies in libcurl format for a request to $host; cookies without a
     * domain are bound to that host, like Python's get_cookies_for_curl().
     *
     * @return list<Cookie>
     */
    public function forCurl(string $host): array
    {
        $this->clearExpired();
        $out = [];
        foreach ($this->jar as $cookie) {
            $c = clone $cookie;
            if ($c->domain === '') {
                $c->domain = self::effectiveHost($host);
            }
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Apply libcurl's COOKIECHANGES list ("SET\t<line>" / "DELETE\t<line>").
     *
     * @param list<string> $changes
     */
    public function updateFromCurlChanges(array $changes): void
    {
        foreach ($changes as $change) {
            $parts = explode("\t", $change, 2);
            if (count($parts) !== 2 || !in_array($parts[0], ['SET', 'DELETE'], true)) {
                throw new \InvalidArgumentException("Invalid curl cookie change: {$change}");
            }
            $cookie = Cookie::fromCurlFormat($parts[1]);
            $this->removeCurlCookie($cookie);
            if ($parts[0] === 'SET') {
                $this->setCookie($cookie);
            }
        }
        $this->clearExpired();
    }

    public function clearExpired(): void
    {
        $now = time();
        foreach ($this->jar as $key => $cookie) {
            if ($cookie->isExpired($now)) {
                unset($this->jar[$key]);
            }
        }
    }

    public function count(): int
    {
        return count($this->jar);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getDict());
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->get((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): string
    {
        $value = $this->get((string) $offset);
        if ($value === null) {
            throw new \OutOfBoundsException("Cookie not found: {$offset}");
        }

        return $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, (string) $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->delete((string) $offset);
    }

    /** @return array<string, Cookie> */
    private function matching(string $name, ?string $domain, ?string $path): array
    {
        $out = [];
        foreach ($this->jar as $key => $cookie) {
            if ($cookie->name === $name
                && ($domain === null || $cookie->domain === $domain)
                && ($path === null || $cookie->path === $path)) {
                $out[$key] = $cookie;
            }
        }

        return $out;
    }

    private function removeCurlCookie(Cookie $cookie): void
    {
        $bare = ltrim($cookie->domain, '.');
        foreach ($this->jar as $key => $existing) {
            if ($existing->name === $cookie->name
                && $existing->path === $cookie->path
                && ltrim($existing->domain, '.') === $bare) {
                unset($this->jar[$key]);
            }
        }
    }

    /** Request host without port, lower case. */
    private static function effectiveHost(string $host): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', $host));
    }
}
