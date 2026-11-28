<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

use CurlCffi\Impersonate\FingerprintManager;
use CurlCffi\Impersonate\FingerprintUpdateError;

/**
 * Fingerprint management: `update`, `list [--json]` and
 * `config --api-key KEY`, like curl_cffi.cli.pro.
 *
 * @internal
 */
final class ProCommands
{
    public const LIST_HEADERS = ['type', 'name', 'browser', 'version', 'os', 'os_version', 'h3_fingerprints'];

    /** argparse type for --api-key. */
    public static function validateApiKey(string $value): string
    {
        if (!str_starts_with($value, 'imp_')) {
            throw new \InvalidArgumentException("API key must start with 'imp_'.");
        }

        return $value;
    }

    /** @return int 0, or 1 when the update failed (Python exits with 0 then) */
    public static function update(Console $io): int
    {
        try {
            $updated = FingerprintManager::updateFingerprints();
        } catch (FingerprintUpdateError $e) {
            $io->err($e->getMessage());

            return 1;
        }
        $io->out("Total {$updated} fingerprint" . ($updated === 1 ? '' : 's') . ' in cache.');

        return 0;
    }

    public static function list(Console $io, bool $json): int
    {
        FingerprintManager::clearCache();
        $rows = FingerprintManager::listFingerprints();
        if ($json) {
            $io->out(Output::prettyJson($rows));

            return 0;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = array_map(static fn ($h) => self::cell($row[$h] ?? null), self::LIST_HEADERS);
        }
        self::printTable($io, self::LIST_HEADERS, $table);

        return 0;
    }

    public static function config(Console $io, string $apiKey): int
    {
        FingerprintManager::setApiKey($apiKey);
        $io->out('API key saved to ' . FingerprintManager::getConfigPath());

        return 0;
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) || is_object($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private static function printTable(Console $io, array $headers, array $rows): void
    {
        $widths = array_map(self::length(...), $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], self::length($cell));
            }
        }
        $render = static function (array $values) use ($widths): string {
            $cells = [];
            foreach ($values as $i => $value) {
                $cells[] = $value . str_repeat(' ', $widths[$i] - self::length($value));
            }

            return implode(' | ', $cells);
        };
        $io->out($render($headers));
        $io->out(implode('-+-', array_map(static fn ($w) => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            $io->out($render($row));
        }
    }

    /** Characters in a string, as Python's len() counts them (no mbstring needed). */
    private static function length(string $text): int
    {
        $count = @preg_match_all('/./su', $text);

        return is_int($count) ? $count : strlen($text);
    }
}
