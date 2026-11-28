<?php

declare(strict_types=1);

namespace CurlCffi\Impersonate;

/**
 * Browser targets built into libcurl-impersonate, as listed in curl_cffi's
 * fingerprints.py (NATIVE_IMPERSONATE_TARGETS, curl_cffi 0.17.0).
 */
final class NativeTargets
{
    /** @var list<array{browser: string, version: string, os: string, os_version: string, target_name: string, h3_fingerprints: bool}> */
    public const TARGETS = [
        ['browser' => 'Chrome', 'version' => '99', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome99', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '100', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome100', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '101', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome101', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '104', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome104', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '107', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome107', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '110', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome110', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '116', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome116', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '119', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'chrome119', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '120', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'chrome120', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '123', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'chrome123', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '124', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'chrome124', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '131', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'chrome131', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '133', 'os' => 'macOS', 'os_version' => 'Sequoia', 'target_name' => 'chrome133a', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '136', 'os' => 'macOS', 'os_version' => 'Sequoia', 'target_name' => 'chrome136', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '142', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'chrome142', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '145', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'chrome145', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '146', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'chrome146', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '150', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'chrome150', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '151', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome151', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '151', 'os' => 'macOS', 'os_version' => '26.0', 'target_name' => 'chrome151_macos', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '152', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'chrome152', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '152', 'os' => 'macOS', 'os_version' => '26.0', 'target_name' => 'chrome152_macos', 'h3_fingerprints' => true],
        ['browser' => 'Chrome', 'version' => '99', 'os' => 'Android', 'os_version' => '12', 'target_name' => 'chrome99_android', 'h3_fingerprints' => false],
        ['browser' => 'Chrome', 'version' => '131', 'os' => 'Android', 'os_version' => '14', 'target_name' => 'chrome131_android', 'h3_fingerprints' => false],
        ['browser' => 'Edge', 'version' => '99', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'edge99', 'h3_fingerprints' => false],
        ['browser' => 'Edge', 'version' => '101', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'edge101', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '15.3', 'os' => 'macOS', 'os_version' => 'Big Sur', 'target_name' => 'safari153', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '15.5', 'os' => 'macOS', 'os_version' => 'Monterey', 'target_name' => 'safari155', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '17.0', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'safari170', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '17.2', 'os' => 'iOS', 'os_version' => '17.2', 'target_name' => 'safari172_ios', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '18.0', 'os' => 'macOS', 'os_version' => 'Sequoia', 'target_name' => 'safari180', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '18.0', 'os' => 'iOS', 'os_version' => '18.0', 'target_name' => 'safari180_ios', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '18.4', 'os' => 'macOS', 'os_version' => 'Sequoia', 'target_name' => 'safari184', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '18.4', 'os' => 'iOS', 'os_version' => '18.4', 'target_name' => 'safari184_ios', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '26.0', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'safari260', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '26.0.1', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'safari2601', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '26.0', 'os' => 'iOS', 'os_version' => '26.0', 'target_name' => 'safari260_ios', 'h3_fingerprints' => false],
        ['browser' => 'Safari', 'version' => '27.0', 'os' => 'macOS', 'os_version' => '27.0', 'target_name' => 'safari270', 'h3_fingerprints' => true],
        ['browser' => 'Safari', 'version' => '27.0', 'os' => 'iOS', 'os_version' => '27.0', 'target_name' => 'safari270_ios', 'h3_fingerprints' => true],
        ['browser' => 'Firefox', 'version' => '133.0', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'firefox133', 'h3_fingerprints' => false],
        ['browser' => 'Firefox', 'version' => '135.0', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'firefox135', 'h3_fingerprints' => false],
        ['browser' => 'Firefox', 'version' => '144.0', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'firefox144', 'h3_fingerprints' => false],
        ['browser' => 'Firefox', 'version' => '147.0', 'os' => 'macOS', 'os_version' => 'Tahoe', 'target_name' => 'firefox147', 'h3_fingerprints' => true],
        ['browser' => 'Firefox', 'version' => '153.0', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'firefox153', 'h3_fingerprints' => false],
        ['browser' => 'Firefox', 'version' => '154.0', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'firefox154', 'h3_fingerprints' => false],
        ['browser' => 'Firefox', 'version' => '155.0', 'os' => 'Windows', 'os_version' => '10', 'target_name' => 'firefox155', 'h3_fingerprints' => false],
        ['browser' => 'Tor', 'version' => '14.5', 'os' => 'macOS', 'os_version' => 'Sonoma', 'target_name' => 'tor145', 'h3_fingerprints' => false],
    ];

    /** Old names that libcurl-impersonate still accepts. */
    public const DEPRECATED_ALIASES = ['safari15_3', 'safari15_5', 'safari17_0', 'safari17_2_ios', 'safari18_0', 'safari18_0_ios'];

    /** Whether $target (after resolving "chrome" and the like) is built into libcurl-impersonate. */
    public static function isNative(string $target): bool
    {
        $name = BrowserType::resolve($target);
        if (in_array($name, self::DEPRECATED_ALIASES, true)) {
            return true;
        }
        foreach (self::TARGETS as $t) {
            if ($t['target_name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
