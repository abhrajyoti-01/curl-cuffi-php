<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Parity;

use CurlCffi\Requests\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the PHP port against the public API of Python curl_cffi, item by
 * item: every option each call accepts, every public method, property and
 * attribute of each class, every exception, enum member and exported name.
 *
 * The Python side is python_api.json, written by python_api.py from
 * curl_cffi's source (CI regenerates it to prove it is current). Each Python
 * name must exist in PHP, under its snake_case or camelCase spelling, or be
 * listed in ApiDifferences::NOT_PORTED with the reason. When a listed name
 * gets ported, testDifferencesAreCurrent fails until it is taken off the list.
 */
final class ApiParityTest extends TestCase
{
    /** Python class => PHP class */
    public const CLASSES = [
        'Session' => \CurlCffi\Requests\Session::class,
        'AsyncSession' => \CurlCffi\Requests\AsyncSession::class,
        'RetryStrategy' => \CurlCffi\Requests\RetryStrategy::class,
        'Request' => \CurlCffi\Requests\Request::class,
        'Response' => \CurlCffi\Requests\Response::class,
        'Headers' => \CurlCffi\Requests\Headers::class,
        'Cookies' => \CurlCffi\Requests\Cookies::class,
        'WebSocket' => \CurlCffi\Requests\WebSocket\WebSocket::class,
        'AsyncWebSocket' => \CurlCffi\Requests\WebSocket\AsyncWebSocket::class,
        'WebSocketRetryStrategy' => \CurlCffi\Requests\WebSocket\WebSocketRetryStrategy::class,
        'ExtraFingerprints' => \CurlCffi\Impersonate\ExtraFingerprints::class,
        'CacheBackend' => \CurlCffi\Requests\Cache\CacheBackend::class,
        'FileCacheBackend' => \CurlCffi\Requests\Cache\FileCacheBackend::class,
        'Curl' => \CurlCffi\Curl\Curl::class,
        'CurlMime' => \CurlCffi\Curl\CurlMime::class,
        'CurlShare' => \CurlCffi\Curl\CurlShare::class,
        'CurlWsFrame' => \CurlCffi\Curl\CurlWsFrame::class,
        'Fingerprint' => \CurlCffi\Impersonate\Fingerprint::class,
        'FingerprintManager' => \CurlCffi\Impersonate\FingerprintManager::class,
    ];

    /** Python module-level names that are static methods or namespaces in PHP */
    private const EXPORTS = [
        'get_fingerprint' => [\CurlCffi\Impersonate\FingerprintManager::class, 'getFingerprint'],
        'config_warnings' => [\CurlCffi\Warnings::class, 'config'],
        'ffi' => [\CurlCffi\Ffi\Library::class, 'ffi'],
        'lib' => [\CurlCffi\Ffi\Library::class, 'ffi'],
        'exceptions' => [\CurlCffi\Requests\Exceptions\RequestException::class, null],
    ];

    /** Where each PHP exception lives, by Python name. */
    private const EXCEPTION_NAMESPACES = [
        'CurlError' => 'CurlCffi\\Curl\\',
        'FingerprintUpdateError' => 'CurlCffi\\Impersonate\\',
    ];

    /** Python enum => PHP class with the same constants */
    private const ENUMS = [
        'WsCloseCode' => \CurlCffi\Requests\WebSocket\WsCloseCode::class,
        'BrowserType' => \CurlCffi\Impersonate\BrowserType::class,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $reference = null;

    /** @return array<string, mixed> */
    public static function reference(): array
    {
        return self::$reference ??= json_decode(
            (string) file_get_contents(__DIR__ . '/python_api.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, array{string}> */
    public static function optionGroups(): array
    {
        $out = [];
        foreach (array_keys(self::reference()['options']) as $call) {
            $out[$call] = [$call];
        }

        return $out;
    }

    #[DataProvider('optionGroups')]
    public function testOptions(string $call): void
    {
        $missing = [];
        foreach (self::reference()['options'][$call] as $name) {
            if (!$this->hasOption($call, $name) && !isset(ApiDifferences::NOT_PORTED["{$call}({$name})"])) {
                $missing[] = $name;
            }
        }
        self::assertSame([], $missing, "PHP {$call} lacks these Python options");
    }

    /** @return array<string, array{string}> */
    public static function classes(): array
    {
        $out = [];
        foreach (array_keys(self::reference()['classes']) as $class) {
            $out[$class] = [$class];
        }

        return $out;
    }

    #[DataProvider('classes')]
    public function testClassMembers(string $pyClass): void
    {
        if (isset(ApiDifferences::NOT_PORTED[$pyClass])) {
            self::assertFalse(
                isset(self::CLASSES[$pyClass]) && class_exists(self::CLASSES[$pyClass]),
                "{$pyClass} exists now: take it off ApiDifferences::NOT_PORTED"
            );

            return;
        }
        $phpClass = self::CLASSES[$pyClass] ?? null;
        self::assertNotNull($phpClass, "No PHP class mapped for {$pyClass}");
        self::assertTrue(class_exists($phpClass), "{$phpClass} does not exist");

        $missing = [];
        foreach (self::reference()['classes'][$pyClass] as $member => $kind) {
            if (!self::hasMember($phpClass, $member) && !isset(ApiDifferences::NOT_PORTED["{$pyClass}.{$member}"])) {
                $missing[] = "{$member} ({$kind})";
            }
        }
        self::assertSame([], $missing, "{$phpClass} lacks these members of Python's {$pyClass}");
    }

    public function testRequestsFunctions(): void
    {
        $missing = [];
        foreach (self::reference()['functions']['requests'] as $fn) {
            if (!self::hasMember(\CurlCffi\Requests\Requests::class, $fn)) {
                $missing[] = $fn;
            }
        }
        self::assertSame([], $missing, 'Requests lacks these curl_cffi.requests functions');
    }

    public function testExceptions(): void
    {
        $problems = [];
        foreach (self::reference()['exceptions'] as $name => $bases) {
            if (isset(ApiDifferences::NOT_PORTED["exception {$name}"])) {
                continue;
            }
            $class = self::exceptionClass($name);
            if (!class_exists($class)) {
                $problems[] = "{$name}: missing";
                continue;
            }
            // PHP has single inheritance: extending one of the Python bases is enough.
            $phpBases = [];
            foreach ($bases as $base) {
                $phpBase = self::exceptionClass($base);
                if (class_exists($phpBase) && !in_array($base, ['Exception', 'Warning'], true)) {
                    $phpBases[] = $phpBase;
                }
            }
            if ($phpBases === []) {
                continue;
            }
            $ok = false;
            foreach ($phpBases as $phpBase) {
                $ok = $ok || is_subclass_of($class, $phpBase);
            }
            if (!$ok) {
                $problems[] = "{$name}: should extend one of " . implode(', ', $phpBases);
            }
        }
        self::assertSame([], $problems);
    }

    /** @return array<string, array{string}> */
    public static function enums(): array
    {
        $out = [];
        foreach (array_keys(self::reference()['enums']) as $enum) {
            $out[$enum] = [$enum];
        }

        return $out;
    }

    #[DataProvider('enums')]
    public function testEnums(string $enum): void
    {
        $class = self::ENUMS[$enum] ?? 'CurlCffi\\Curl\\Const\\' . $enum;
        self::assertTrue(class_exists($class) || enum_exists($class), "{$class} does not exist");

        $problems = [];
        foreach (self::reference()['enums'][$enum] as $member => $value) {
            if (enum_exists($class)) {
                $case = null;
                foreach ($class::cases() as $c) {
                    if ($c->name === $member) {
                        $case = $c;
                    }
                }
                if ($case === null) {
                    $problems[] = "{$member}: missing";
                } elseif ($value !== null && $case instanceof \BackedEnum && $case->value !== $value) {
                    $problems[] = "{$member}: " . var_export($case->value, true) . ' instead of ' . var_export($value, true);
                }
                continue;
            }
            if (!defined("{$class}::{$member}")) {
                $problems[] = "{$member}: missing";
            } elseif ($value !== null && constant("{$class}::{$member}") !== $value) {
                $problems[] = "{$member}: " . var_export(constant("{$class}::{$member}"), true) . ' instead of ' . var_export($value, true);
            }
        }
        self::assertSame([], $problems, "{$class} differs from Python's {$enum}");
    }

    public function testExports(): void
    {
        $missing = [];
        foreach (self::reference()['exports'] as $module => $names) {
            foreach ($names as $name) {
                if (isset(ApiDifferences::NOT_PORTED["export {$name}"]) || self::exportExists($name)) {
                    continue;
                }
                $missing[] = "{$module}.{$name}";
            }
        }
        self::assertSame([], $missing, 'Exported Python names with no PHP counterpart');
    }

    /** Every listed difference must still be one, so the list cannot go stale. */
    public function testDifferencesAreCurrent(): void
    {
        $ref = self::reference();
        $stale = [];
        foreach (array_keys(ApiDifferences::NOT_PORTED) as $key) {
            if (preg_match('/^(\S+)\((\w+)\)$/', $key, $m)) {
                $known = in_array($m[2], $ref['options'][$m[1]] ?? [], true);
                $ported = $known && $this->hasOption($m[1], $m[2]);
            } elseif (preg_match('/^exception (\w+)$/', $key, $m)) {
                $known = isset($ref['exceptions'][$m[1]]);
                $ported = class_exists(self::exceptionClass($m[1]));
            } elseif (preg_match('/^export (\w+)$/', $key, $m)) {
                $known = in_array($m[1], [...$ref['exports']['curl_cffi'], ...$ref['exports']['curl_cffi.requests']], true);
                $ported = self::exportExists($m[1]);
            } elseif (preg_match('/^(\w+)\.(\w+)$/', $key, $m)) {
                $known = isset($ref['classes'][$m[1]][$m[2]]);
                $ported = $known && !isset(ApiDifferences::NOT_PORTED[$m[1]])
                    && class_exists(self::CLASSES[$m[1]]) && self::hasMember(self::CLASSES[$m[1]], $m[2]);
            } else {
                $known = isset($ref['classes'][$key]);
                $ported = isset(self::CLASSES[$key]) && class_exists(self::CLASSES[$key]);
            }
            if (!$known) {
                $stale[] = "{$key}: Python curl_cffi has no such name";
            } elseif ($ported) {
                $stale[] = "{$key}: ported now";
            }
        }
        self::assertSame([], $stale, 'Take these off ApiDifferences::NOT_PORTED');
    }

    public function testReferenceIsForTheTargetedVersion(): void
    {
        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/CURL_CFFI_VERSION')),
            self::reference()['curl_cffi_version']
        );
    }

    private function hasOption(string $call, string $name): bool
    {
        $allowed = match ($call) {
            'Session.__init__' => [...Options::SESSION, 'curl'],
            'AsyncSession.__init__' => [...Options::SESSION, 'max_clients'],
            'Session.request', 'AsyncSession.request' => Options::REQUEST,
            'Session.stream', 'AsyncSession.stream' => array_diff(Options::REQUEST, ['stream']),
            'requests.request' => [...Options::REQUEST, 'curl_options', 'debug'],
            'Session.ws_connect' => Options::WS_CONNECT,
            'AsyncSession.ws_connect' => Options::ASYNC_WS_CONNECT,
            'WebSocket.__init__' => Options::WEBSOCKET,
            'WebSocket.connect' => Options::WS_CONNECT_STANDALONE,
            'WebSocket.run_forever' => ['**kwargs'],
            // new AsyncWebSocket(Loop $loop, Curl $curl, ...): its own option list
            'AsyncWebSocket.__init__' => ['curl', 'autoclose', 'skip_utf8_validation', 'debug', 'ws_retry', 'max_message_size'],
            default => [],
        };

        return in_array($name, $allowed, true);
    }

    /** A method, property or documented magic member, as snake_case or camelCase. */
    public static function hasMember(string $class, string $name): bool
    {
        $camel = lcfirst(str_replace('_', '', ucwords($name, '_')));
        foreach (array_unique([$camel, $name]) as $n) {
            if (method_exists($class, $n) || property_exists($class, $n) || defined("{$class}::{$n}")) {
                return true;
            }
        }
        $doc = '';
        for ($r = new \ReflectionClass($class); $r !== false; $r = $r->getParentClass()) {
            $doc .= (string) $r->getDocComment();
        }
        foreach (array_unique([$camel, $name]) as $n) {
            $q = preg_quote($n, '/');
            if (preg_match('/@method\s+(?:static\s+)?\S+\s+' . $q . '\(/', $doc)
                || preg_match('/@property(?:-read|-write)?\s+\S+\s+\$' . $q . '\b/', $doc)) {
                return true;
            }
        }

        return false;
    }

    private static function exceptionClass(string $name): string
    {
        return (self::EXCEPTION_NAMESPACES[$name] ?? 'CurlCffi\\Requests\\Exceptions\\') . $name;
    }

    private static function exportExists(string $name): bool
    {
        if (isset(self::EXPORTS[$name])) {
            [$class, $method] = self::EXPORTS[$name];

            return $method === null ? class_exists($class) : method_exists($class, $method);
        }
        if (isset(self::CLASSES[$name])) {
            return class_exists(self::CLASSES[$name]);
        }
        if (isset(self::ENUMS[$name])) {
            return class_exists(self::ENUMS[$name]) || enum_exists(self::ENUMS[$name]);
        }
        if (class_exists('CurlCffi\\Curl\\Const\\' . $name) || class_exists(self::exceptionClass($name))) {
            return true;
        }
        if (in_array($name, self::reference()['functions']['requests'], true)) {
            return self::hasMember(\CurlCffi\Requests\Requests::class, $name);
        }

        return false;
    }
}
