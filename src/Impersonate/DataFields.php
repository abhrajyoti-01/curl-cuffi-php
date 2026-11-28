<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

/**
 * Shared by Fingerprint and ExtraFingerprints, which are Python dataclasses
 * in curl_cffi: fields are camelCase properties that also answer to their
 * Python snake_case names, in the constructor and as properties.
 *
 * @internal
 */
trait DataFields
{
    /** Fields by name, e.g. new Fingerprint(tls_version: '1.3') or (tlsVersion: '1.3'). */
    public function __construct(mixed ...$fields)
    {
        foreach ($fields as $name => $value) {
            if (!is_string($name)) {
                throw new \TypeError(static::class . ': pass fields by name, e.g. tls_grease: true');
            }
            $prop = self::propertyName($name) ?? throw new \TypeError(static::class . ": unknown field '{$name}'");
            $this->assign($prop, $value);
        }
    }

    /**
     * Build from an array with snake_case (or camelCase) keys, such as a
     * fingerprint JSON entry. Unknown keys are ignored.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $known = [];
        foreach ($data as $name => $value) {
            if (is_string($name) && self::propertyName($name) !== null) {
                $known[$name] = $value;
            }
        }

        return new static(...$known);
    }

    /** @return array<string, mixed> fields by their Python (snake_case) names */
    public function toArray(): array
    {
        $out = [];
        foreach (self::fieldNames() as $prop) {
            $out[self::snake($prop)] = $this->{$prop};
        }

        return $out;
    }

    public function __get(string $name): mixed
    {
        $prop = self::propertyName($name) ?? throw new \Error('Undefined property: ' . static::class . '::$' . $name);

        return $this->{$prop};
    }

    public function __set(string $name, mixed $value): void
    {
        $prop = self::propertyName($name) ?? throw new \Error('Undefined property: ' . static::class . '::$' . $name);
        $this->assign($prop, $value);
    }

    public function __isset(string $name): bool
    {
        $prop = self::propertyName($name);

        return $prop !== null && isset($this->{$prop});
    }

    /** Set a field, converting scalars such as "1" for an int field (JSON from other tools). */
    private function assign(string $prop, mixed $value): void
    {
        try {
            $this->{$prop} = $value;

            return;
        } catch (\TypeError $e) {
            $type = (new \ReflectionProperty($this, $prop))->getType();
            $names = $type instanceof \ReflectionNamedType ? [$type->getName()]
                : array_map(static fn ($t) => $t->getName(), $type instanceof \ReflectionUnionType ? $type->getTypes() : []);
            foreach (['int', 'string', 'bool', 'float'] as $scalar) {
                if (in_array($scalar, $names, true) && is_scalar($value)) {
                    settype($value, $scalar);
                    $this->{$prop} = $value;

                    return;
                }
            }
            throw new \TypeError(static::class . "::\${$prop}: " . $e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> */
    private static function fieldNames(): array
    {
        static $cache = [];

        return $cache[static::class] ??= array_values(array_map(
            static fn (\ReflectionProperty $p) => $p->getName(),
            array_filter(
                (new \ReflectionClass(static::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
                static fn (\ReflectionProperty $p) => !$p->isStatic(),
            ),
        ));
    }

    private static function propertyName(string $name): ?string
    {
        $camel = lcfirst(str_replace('_', '', ucwords($name, '_')));
        foreach (self::fieldNames() as $prop) {
            if ($prop === $camel || $prop === $name) {
                return $prop;
            }
        }

        return null;
    }

    private static function snake(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
