<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/**
 * Case-insensitive, multi-value, order-preserving HTTP headers, like
 * curl_cffi.requests.Headers.
 *
 * Accepts:
 *  - ['Accept' => 'text/html', 'X-Many' => ['a', 'b']]
 *  - ['Accept: text/html', 'X-Many: a']           (raw header lines)
 *  - [['Accept', 'text/html'], ['X-Many', 'a']]   (pairs)
 *  - another Headers instance
 *
 * A null value means "do not send this header at all", which also removes
 * headers libcurl or impersonation would add.
 *
 * @implements \ArrayAccess<string, ?string>
 * @implements \IteratorAggregate<string, ?string>
 */
final class Headers implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var list<array{0: string, 1: ?string}> */
    private array $list = [];
    private ?string $encoding = null;

    /** @param Headers|array<mixed>|null $headers */
    public function __construct(Headers|array|null $headers = null)
    {
        if ($headers instanceof self) {
            $this->list = $headers->list;

            return;
        }
        foreach (self::toPairs($headers ?? []) as [$k, $v]) {
            $this->list[] = [$k, $v];
        }
    }

    /** Value of $key; several values are joined with ", ". */
    public function get(string $key, ?string $default = null): ?string
    {
        $values = $this->getList($key);
        if ($values === []) {
            return $default;
        }
        $nonNull = array_values(array_filter($values, static fn ($v) => $v !== null));

        return $nonNull === [] ? null : implode(', ', $nonNull);
    }

    /** @return list<?string> every value of $key, in order */
    public function getList(string $key, bool $splitCommas = false): array
    {
        $lower = strtolower($key);
        $values = [];
        foreach ($this->list as [$k, $v]) {
            if (strtolower($k) === $lower) {
                $values[] = $v;
            }
        }
        if (!$splitCommas) {
            return $values;
        }
        $split = [];
        foreach ($values as $v) {
            foreach ($v === null ? [null] : explode(',', $v) as $part) {
                $split[] = $part === null ? null : trim($part);
            }
        }

        return $split;
    }

    /** Replace all values of $key with $value. */
    public function set(string $key, ?string $value): void
    {
        $lower = strtolower($key);
        $position = null;
        foreach ($this->list as $i => [$k]) {
            if (strtolower($k) === $lower) {
                $position ??= $i;
                unset($this->list[$i]);
            }
        }
        if ($position === null) {
            $this->list[] = [$key, $value];
        } else {
            $this->list[$position] = [$key, $value];
            ksort($this->list);
        }
        $this->list = array_values($this->list);
    }

    /** Add a value for $key, keeping existing ones. */
    public function add(string $key, ?string $value): void
    {
        $this->list[] = [$key, $value];
    }

    public function remove(string $key): void
    {
        $lower = strtolower($key);
        $this->list = array_values(array_filter(
            $this->list,
            static fn (array $pair) => strtolower($pair[0]) !== $lower
        ));
    }

    public function has(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->list as [$k]) {
            if (strtolower($k) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge other headers in: keys present in $headers replace ours, keeping
     * all of their values.
     *
     * @param Headers|array<mixed>|null $headers
     */
    public function update(Headers|array|null $headers): void
    {
        if ($headers === null) {
            return;
        }
        $other = $headers instanceof self ? $headers : new self($headers);
        foreach ($other->keys() as $key) {
            $this->remove($key);
        }
        foreach ($other->list as $pair) {
            $this->list[] = $pair;
        }
    }

    /** @return list<array{0: string, 1: ?string}> every [name, value] pair, in order */
    public function multiItems(): array
    {
        return $this->list;
    }

    /** @return list<array{0: string, 1: ?string}> every [name, value] pair as sent or received, like Python's Headers.raw */
    public function raw(): array
    {
        return $this->list;
    }

    /** @return array<string, ?string> name => value, repeated names joined with ", ", like Python's items() */
    public function items(): array
    {
        return $this->toArray();
    }

    /** @return list<?string> values, repeated names joined with ", " */
    public function values(): array
    {
        return array_values($this->toArray());
    }

    /**
     * Encoding of the header bytes: "ascii" when they all are, else "utf-8"
     * when valid, else "iso-8859-1", as Python guesses it.
     */
    public function encoding(): string
    {
        if ($this->encoding !== null) {
            return $this->encoding;
        }
        $all = '';
        foreach ($this->list as [$k, $v]) {
            $all .= $k . ($v ?? '');
        }
        if (!preg_match('/[\x80-\xff]/', $all)) {
            return 'ascii';
        }

        return preg_match('//u', $all) ? 'utf-8' : 'iso-8859-1';
    }

    public function setEncoding(string $encoding): void
    {
        $this->encoding = $encoding;
    }

    /** @return list<string> unique names, original case of the first occurrence */
    public function keys(): array
    {
        $seen = [];
        foreach ($this->list as [$k]) {
            $seen[strtolower($k)] ??= $k;
        }

        return array_values($seen);
    }

    /** @return array<string, ?string> name => joined value */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function copy(): self
    {
        return new self($this);
    }

    public function count(): int
    {
        return count($this->keys());
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): ?string
    {
        if (!$this->has((string) $offset)) {
            throw new \OutOfBoundsException("Header not found: {$offset}");
        }

        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \InvalidArgumentException('Header name required');
        }
        $this->set((string) $offset, $value === null ? null : (string) $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove((string) $offset);
    }

    /**
     * Parse raw response header lines ("Name: value").
     *
     * @param list<string> $lines
     */
    public static function fromLines(array $lines): self
    {
        $h = new self();
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $h->list[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
        }

        return $h;
    }

    /**
     * @param array<mixed> $headers
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function toPairs(array $headers): array
    {
        $pairs = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (is_array($value) && count($value) === 2 && array_is_list($value)) {
                    $pairs[] = [(string) $value[0], $value[1] === null ? null : (string) $value[1]];
                    continue;
                }
                if (is_string($value)) {
                    $pos = strpos($value, ':');
                    if ($pos === false) {
                        throw new \InvalidArgumentException("Invalid header line: {$value}");
                    }
                    $pairs[] = [trim(substr($value, 0, $pos)), trim(substr($value, $pos + 1))];
                    continue;
                }
                throw new \InvalidArgumentException('Invalid header entry');
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    $pairs[] = [$key, $v === null ? null : (string) $v];
                }
                continue;
            }
            $pairs[] = [$key, $value === null ? null : (string) $value];
        }

        return $pairs;
    }
}
