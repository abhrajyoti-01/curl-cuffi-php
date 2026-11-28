<?php

declare(strict_types=1);

namespace CurlCffi;

/**
 * Python curl_cffi's CurlCffiWarning and config_warnings().
 *
 * Python silences these warnings by default (curl_cffi calls
 * config_warnings(on=False) on import), so they are off here too. Turn them
 * on while developing to see hints such as a ja3 string overriding the
 * impersonated browser:
 *
 *     Warnings::config(on: true);   // curl_cffi.config_warnings(on=True)
 *
 * When on, each warning is an E_USER_WARNING.
 */
final class Warnings
{
    private static bool $on = false;

    public static function config(bool $on = false): void
    {
        self::$on = $on;
    }

    public static function enabled(): bool
    {
        return self::$on;
    }

    /** @internal */
    public static function warn(string $message): void
    {
        if (self::$on) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
