<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * Where the command writes: stdout, stderr, and whether stdout is an
 * interactive terminal. Python's CLI colors its output with rich on a
 * terminal; this colors with ANSI codes there, unless NO_COLOR is set or
 * TERM is "dumb".
 *
 * @internal
 */
final class Console
{
    public const RESET = "\e[0m";
    public const BOLD_GREEN = "\e[1;32m";
    public const BOLD_YELLOW = "\e[1;33m";
    public const BOLD_RED = "\e[1;31m";
    public const BOLD_CYAN = "\e[1;36m";
    public const BOLD_BLUE = "\e[1;34m";
    public const GREEN = "\e[32m";
    public const CYAN = "\e[36m";
    public const MAGENTA = "\e[35m";
    public const DIM = "\e[2m";

    /** @var resource */
    public readonly mixed $stdout;
    /** @var resource */
    public readonly mixed $stderr;
    public readonly bool $tty;
    public readonly bool $color;

    /**
     * @param resource|null $stdout defaults to STDOUT
     * @param resource|null $stderr defaults to STDERR
     * @param bool|null     $tty    whether stdout is a terminal; detected when null
     */
    public function __construct(mixed $stdout = null, mixed $stderr = null, ?bool $tty = null, ?bool $color = null)
    {
        $this->stdout = $stdout ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $this->stderr = $stderr ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
        $this->tty = $tty ?? (function_exists('stream_isatty') && @stream_isatty($this->stdout));
        $this->color = $color ?? ($this->tty && self::colorAllowed($this->stdout));
    }

    /** Write a line (or several) to stdout, like print(). */
    public function out(string $text = ''): void
    {
        fwrite($this->stdout, $text . "\n");
    }

    /** Write a line to stderr, like print(..., file=sys.stderr). */
    public function err(string $text = ''): void
    {
        fwrite($this->stderr, $text . "\n");
    }

    public function paint(string $text, string $style): string
    {
        return $this->color && $text !== '' ? $style . $text . self::RESET : $text;
    }

    /** @param resource $stream */
    private static function colorAllowed(mixed $stream): bool
    {
        $noColor = getenv('NO_COLOR');
        if (is_string($noColor) && $noColor !== '' || getenv('TERM') === 'dumb') {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support($stream, true);
        }

        return true;
    }
}
