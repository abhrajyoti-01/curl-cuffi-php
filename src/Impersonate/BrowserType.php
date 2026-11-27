<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

/**
 * Browser targets supported by libcurl-impersonate, same names as
 * Python curl_cffi's BrowserType.
 *
 * Any string the native library accepts can also be passed directly, so new
 * targets work as soon as the binary supports them.
 */
enum BrowserType: string
{
    case edge99 = 'edge99';
    case edge101 = 'edge101';
    case chrome99 = 'chrome99';
    case chrome100 = 'chrome100';
    case chrome101 = 'chrome101';
    case chrome104 = 'chrome104';
    case chrome107 = 'chrome107';
    case chrome110 = 'chrome110';
    case chrome116 = 'chrome116';
    case chrome119 = 'chrome119';
    case chrome120 = 'chrome120';
    case chrome123 = 'chrome123';
    case chrome124 = 'chrome124';
    case chrome131 = 'chrome131';
    case chrome133a = 'chrome133a';
    case chrome136 = 'chrome136';
    case chrome142 = 'chrome142';
    case chrome145 = 'chrome145';
    case chrome146 = 'chrome146';
    case chrome150 = 'chrome150';
    case chrome151 = 'chrome151';
    case chrome151_macos = 'chrome151_macos';
    case chrome152 = 'chrome152';
    case chrome152_macos = 'chrome152_macos';
    case chrome99_android = 'chrome99_android';
    case chrome131_android = 'chrome131_android';
    case safari153 = 'safari153';
    case safari155 = 'safari155';
    case safari170 = 'safari170';
    case safari172_ios = 'safari172_ios';
    case safari180 = 'safari180';
    case safari180_ios = 'safari180_ios';
    case safari184 = 'safari184';
    case safari184_ios = 'safari184_ios';
    case safari260 = 'safari260';
    case safari260_ios = 'safari260_ios';
    case safari2601 = 'safari2601';
    case safari270 = 'safari270';
    case safari270_ios = 'safari270_ios';
    case firefox133 = 'firefox133';
    case firefox135 = 'firefox135';
    case firefox144 = 'firefox144';
    case firefox147 = 'firefox147';
    case firefox153 = 'firefox153';
    case firefox154 = 'firefox154';
    case firefox155 = 'firefox155';
    case tor145 = 'tor145';
    case safari15_3 = 'safari15_3';
    case safari15_5 = 'safari15_5';
    case safari17_0 = 'safari17_0';
    case safari17_2_ios = 'safari17_2_ios';
    case safari18_0 = 'safari18_0';
    case safari18_0_ios = 'safari18_0_ios';

    public const DEFAULT_CHROME = 'chrome152';
    public const DEFAULT_EDGE = 'edge101';
    public const DEFAULT_SAFARI = 'safari270';
    public const DEFAULT_SAFARI_IOS = 'safari270_ios';
    public const DEFAULT_SAFARI_BETA = 'safari270';
    public const DEFAULT_SAFARI_IOS_BETA = 'safari270_ios';
    public const DEFAULT_CHROME_ANDROID = 'chrome131_android';
    public const DEFAULT_FIREFOX = 'firefox155';
    public const DEFAULT_TOR = 'tor145';

    /** Short names that point at the newest version of each browser. */
    public const REAL_TARGET_MAP = [
        'chrome' => self::DEFAULT_CHROME,
        'edge' => self::DEFAULT_EDGE,
        'safari' => self::DEFAULT_SAFARI,
        'safari_ios' => self::DEFAULT_SAFARI_IOS,
        'safari_beta' => self::DEFAULT_SAFARI_BETA,
        'safari_ios_beta' => self::DEFAULT_SAFARI_IOS_BETA,
        'chrome_android' => self::DEFAULT_CHROME_ANDROID,
        'firefox' => self::DEFAULT_FIREFOX,
        'tor' => self::DEFAULT_TOR,
    ];

    /** Turns "chrome" into "chrome152" and so on; other names pass through unchanged. */
    public static function resolve(self|string $target): string
    {
        $name = $target instanceof self ? $target->value : $target;

        return self::REAL_TARGET_MAP[$name] ?? $name;
    }
}
