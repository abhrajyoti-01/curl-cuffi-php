<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/** URL helpers matching curl_cffi.requests.utils. */
final class Url
{
    private const UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    public static function isAbsolute(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['scheme'], $parts['host']);
    }

    /**
     * Add query params, aware of the ones already in the URL. A key that
     * appears once on both sides is replaced; otherwise values are appended.
     * Lists repeat the key (a=1&a=2); booleans and arrays-as-values become JSON,
     * as in Python's update_url_params().
     *
     * @param array<mixed> $params
     */
    public static function updateParams(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }
        [$base, $query, $fragment] = self::split($url);
        $existing = self::parseQuery($query);

        $newPairs = self::toPairs($params);
        $oldCount = array_count_values(array_map(static fn ($p) => $p[0], $existing));
        $newCount = array_count_values(array_map(static fn ($p) => $p[0], $newPairs));
        foreach ($newPairs as [$key, $value]) {
            if (($oldCount[$key] ?? 0) === 1 && ($newCount[$key] ?? 0) === 1) {
                foreach ($existing as $i => $pair) {
                    if ($pair[0] === $key) {
                        $existing[$i] = [$key, $value];
                    }
                }
            } else {
                $existing[] = [$key, $value];
            }
        }

        $encoded = implode('&', array_map(
            static fn ($p) => urlencode($p[0]) . '=' . urlencode($p[1]),
            $existing
        ));

        return $base . ($encoded !== '' ? '?' . $encoded : '') . $fragment;
    }

    /**
     * Form-encode $data like Python's urlencode(): repeated keys for lists,
     * PHP-style brackets for nested string-keyed arrays.
     *
     * @param array<mixed> $data
     */
    public static function encodeForm(array $data): string
    {
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $pairs[] = http_build_query([$key => $value], '', '&', PHP_QUERY_RFC1738);
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $v) {
                $pairs[] = urlencode((string) $key) . '=' . urlencode(self::scalar($v, false));
            }
        }

        return implode('&', $pairs);
    }

    /** Resolve $url against $base (RFC 3986), like urllib.parse.urljoin(). */
    public static function join(string $base, string $url): string
    {
        if ($url === '') {
            return $base;
        }
        if (self::isAbsolute($url)) {
            return $url;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'])) {
            return $url;
        }
        $authority = $b['scheme'] . '://'
            . (isset($b['user']) ? $b['user'] . (isset($b['pass']) ? ':' . $b['pass'] : '') . '@' : '')
            . ($b['host'] ?? '')
            . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($url, '//')) {
            return $b['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, '?')) {
            return $authority . ($b['path'] ?? '/') . $url;
        }
        if (str_starts_with($url, '#')) {
            return $authority . ($b['path'] ?? '/') . (isset($b['query']) ? '?' . $b['query'] : '') . $url;
        }
        if (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            $basePath = $b['path'] ?? '/';
            $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = ($dir === '' ? '/' : $dir) . $url;
        }
        $suffix = '';
        if (preg_match('/^([^?#]*)(.*)$/s', $path, $m)) {
            [$path, $suffix] = [$m[1], $m[2]];
        }

        return $authority . self::removeDotSegments($path) . $suffix;
    }

    /**
     * Quote characters that are not allowed in a URL, leaving reserved
     * characters and valid %XX escapes alone, like requests' requote_uri().
     */
    public static function requote(string $uri): string
    {
        $unquoted = self::unquoteUnreserved($uri);
        $safe = "!#$%&'()*+,/:;=?@[]~|";
        if ($unquoted === null) {
            $unquoted = $uri;
            $safe = "!#$&'()*+,/:;=?@[]~|";
        }

        return self::quote($unquoted, $safe);
    }

    /**
     * Percent-encode the path and query, keeping only $keep plus unreserved
     * characters, like curl_cffi's `quote` request option.
     */
    public static function quotePathAndParams(string $url, string $extraUnsafe = ''): string
    {
        $safe = str_replace(str_split($extraUnsafe), '', "!#$%&'()*+,/:;=?@[]~");
        [$base, $query, $fragment] = self::split($url);
        $p = parse_url($base);
        $path = $p['path'] ?? '';
        $prefix = substr($base, 0, strlen($base) - strlen($path));
        $pairs = self::parseQuery($query);
        $encodedQuery = implode('&', array_map(
            static fn ($pair) => self::quote($pair[0], $safe, true) . '=' . self::quote($pair[1], $safe, true),
            $pairs
        ));

        return $prefix . self::quote($path, $safe) . ($encodedQuery !== '' ? '?' . $encodedQuery : '') . $fragment;
    }

    public static function host(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** @return array{0: string, 1: string, 2: string} [before ?, query, #fragment] */
    private static function split(string $url): array
    {
        $fragment = '';
        if (($pos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }
        $query = '';
        if (($pos = strpos($url, '?')) !== false) {
            $query = substr($url, $pos + 1);
            $url = substr($url, 0, $pos);
        }

        return [$url, $query, $fragment];
    }

    /** @return list<array{0: string, 1: string}> keeps blank values and repeated keys */
    private static function parseQuery(string $query): array
    {
        $pairs = [];
        if ($query === '') {
            return $pairs;
        }
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $kv = explode('=', $part, 2);
            $pairs[] = [urldecode($kv[0]), urldecode($kv[1] ?? '')];
        }

        return $pairs;
    }

    /**
     * @param array<mixed> $params
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function toPairs(array $params): array
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_int($key) && is_array($value) && count($value) === 2 && array_is_list($value)) {
                // list of [key, value] tuples
                $pairs[] = [(string) $value[0], self::scalar($value[1], true)];
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $v) {
                    $pairs[] = [(string) $key, self::scalar($v, true)];
                }
                continue;
            }
            $pairs[] = [(string) $key, self::scalar($value, true)];
        }

        return $pairs;
    }

    private static function scalar(mixed $value, bool $jsonForBoolAndDict): string
    {
        if (is_bool($value)) {
            return $jsonForBoolAndDict ? ($value ? 'true' : 'false') : ($value ? 'True' : 'False');
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if ($value === null) {
            return $jsonForBoolAndDict ? 'None' : 'None';
        }

        return (string) $value;
    }

    private static function unquoteUnreserved(string $uri): ?string
    {
        $parts = explode('%', $uri);
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $h = substr($parts[$i], 0, 2);
            if (strlen($h) === 2 && ctype_alnum($h)) {
                if (!ctype_xdigit($h)) {
                    return null; // invalid percent-escape
                }
                $c = chr((int) hexdec($h));
                if (str_contains(self::UNRESERVED, $c)) {
                    $parts[$i] = $c . substr($parts[$i], 2);
                } else {
                    $parts[$i] = '%' . $parts[$i];
                }
            } else {
                $parts[$i] = '%' . $parts[$i];
            }
        }

        return implode('', $parts);
    }

    private static function quote(string $s, string $safe, bool $plusForSpace = false): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (str_contains(self::UNRESERVED, $c) || str_contains($safe, $c)) {
                $out .= $c;
            } elseif ($c === ' ' && $plusForSpace) {
                $out .= '+';
            } else {
                $out .= sprintf('%%%02X', ord($c));
            }
        }

        return $out;
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $result = implode('/', $out);
        if (preg_match('#/\.{1,2}$#', $path)) {
            $result .= '/';
        }

        return $result === '' ? '/' : $result;
    }
}
