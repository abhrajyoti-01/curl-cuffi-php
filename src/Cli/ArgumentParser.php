<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * The part of Python's argparse that the curl-cffi command needs: long and
 * short options (with unique-prefix abbreviations, "--opt=value", "-ovalue"
 * and bundled flags), --flag/--no-flag pairs, mutually exclusive groups,
 * typed values and positionals. Errors and help read like argparse's.
 *
 * Options may come before, between or after positionals, and "--" ends
 * the options.
 *
 * @internal
 */
final class ArgumentParser
{
    /** @var list<array<string, mixed>> */
    private array $options = [];
    /** @var list<array<string, mixed>> */
    private array $positionals = [];
    /** @var list<list<int>> option indexes of each mutually exclusive group */
    private array $groups = [];

    public function __construct(
        public readonly string $prog,
        private readonly ?string $description = null,
        private readonly bool $rawDescription = false,
        bool $addHelp = true,
    ) {
        if ($addHelp) {
            $this->addOption(['-h', '--help'], action: 'help', help: 'show this help message and exit');
        }
    }

    /** Start a mutually exclusive group; pass the returned id as `group`. */
    public function addGroup(): int
    {
        $this->groups[] = [];

        return count($this->groups) - 1;
    }

    /**
     * @param list<string> $names  option strings, e.g. ['--timeout'] or ['--json', '-j']
     * @param string       $action store, store_true, store_const, boolean (adds --no-NAME) or help
     * @param string|\Closure(string): mixed|null $type 'int', 'float', or a converter that
     *        throws \InvalidArgumentException with the message to show
     */
    public function addOption(
        array $names,
        string $action = 'store',
        ?string $dest = null,
        mixed $default = null,
        mixed $const = null,
        string|\Closure|null $type = null,
        bool $required = false,
        ?string $help = null,
        ?string $metavar = null,
        ?int $group = null,
    ): void {
        if ($action === 'boolean') {
            $long = array_values(array_filter($names, static fn ($n) => str_starts_with($n, '--')));
            foreach ($long as $n) {
                $names[] = '--no-' . substr($n, 2);
            }
        }
        $dest ??= str_replace('-', '_', ltrim($this->longest($names), '-'));
        $index = count($this->options);
        $this->options[] = [
            'names' => $names, 'action' => $action, 'dest' => $dest, 'default' => $default,
            'const' => $const, 'type' => $type, 'required' => $required, 'help' => $help,
            'metavar' => $metavar ?? strtoupper($dest), 'group' => $group,
        ];
        if ($group !== null) {
            $this->groups[$group][] = $index;
        }
    }

    /** @param string|null $nargs null for exactly one value, '*' for any number */
    public function addPositional(string $dest, ?string $metavar = null, ?string $nargs = null, ?string $help = null): void
    {
        $this->positionals[] = ['dest' => $dest, 'metavar' => $metavar ?? $dest, 'nargs' => $nargs, 'help' => $help];
    }

    /**
     * Every option string this parser accepts, e.g. for the parity check.
     *
     * @return list<string>
     */
    public function optionStrings(): array
    {
        $out = [];
        foreach ($this->options as $o) {
            array_push($out, ...$o['names']);
        }

        return $out;
    }

    /** Default value of the option named by any of its option strings, e.g. '--max-redirects'. */
    public function defaultOf(string $optionString): mixed
    {
        $index = $this->findExact($optionString);
        if ($index === null) {
            throw new \OutOfBoundsException("No option {$optionString}");
        }

        return $this->options[$index]['default'];
    }

    /** @return list<string> metavars of the positionals */
    public function positionalNames(): array
    {
        return array_map(static fn ($p) => $p['metavar'], $this->positionals);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed> dest => value
     *
     * @throws UsageError
     * @throws HelpRequested
     */
    public function parse(array $args): array
    {
        $values = [];
        foreach ($this->options as $o) {
            if ($o['action'] !== 'help') {
                $values[$o['dest']] ??= $o['action'] === 'store_true' ? false : $o['default'];
            }
        }
        $used = [];        // option index => the option string given
        $positionals = [];
        $extras = [];
        $n = count($args);
        for ($i = 0; $i < $n; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                array_push($positionals, ...array_slice($args, $i + 1));
                break;
            }
            if ($arg === '' || $arg === '-' || $arg[0] !== '-' || self::isNegativeNumber($arg)) {
                $positionals[] = $arg;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                [$name, $explicit] = str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, null];
                $resolved = $this->resolveLong($name);
                if ($resolved === null) {
                    $extras[] = $arg;
                    continue;
                }
                $this->apply((int) $this->findExact($resolved), $resolved, $explicit, $args, $i, $values, $used);
                continue;
            }

            // short options: "-v", "-vq" (bundled flags), "-ichrome", "-i=chrome", "-i chrome"
            $chars = substr($arg, 1);
            while ($chars !== '') {
                $name = '-' . $chars[0];
                $rest = substr($chars, 1);
                $index = $this->findExact($name);
                if ($index === null) {
                    $extras[] = $chars === substr($arg, 1) ? $arg : $name;
                    break;
                }
                $takesValue = $this->options[$index]['action'] === 'store';
                if ($takesValue) {
                    $explicit = $rest === '' ? null : (str_starts_with($rest, '=') ? substr($rest, 1) : $rest);
                    $this->apply($index, $name, $explicit, $args, $i, $values, $used);
                    break;
                }
                if (str_starts_with($rest, '=')) {
                    $this->apply($index, $name, substr($rest, 1), $args, $i, $values, $used);
                    break;
                }
                $this->apply($index, $name, null, $args, $i, $values, $used);
                $chars = $rest;
            }
        }

        // positionals: each single one takes a value, a '*' one takes what is left over
        $missing = [];
        $star = null;
        foreach ($this->positionals as $p) {
            if ($p['nargs'] === '*') {
                $star = $p['dest'];
                $values[$p['dest']] = [];
                continue;
            }
            if ($positionals === []) {
                $missing[] = $p['metavar'];
                continue;
            }
            $values[$p['dest']] = array_shift($positionals);
        }
        if ($star !== null) {
            $values[$star] = $positionals;
            $positionals = [];
        }
        foreach ($this->options as $index => $o) {
            if ($o['required'] && !isset($used[$index])) {
                $missing[] = implode('/', $o['names']);
            }
        }
        if ($missing !== []) {
            throw new UsageError('the following arguments are required: ' . implode(', ', $missing));
        }
        $extras = [...$extras, ...$positionals];
        if ($extras !== []) {
            throw new UsageError('unrecognized arguments: ' . implode(' ', $extras));
        }

        return $values;
    }

    public function formatUsage(): string
    {
        $parts = [];
        $done = [];
        foreach ($this->options as $index => $o) {
            if (isset($done[$index])) {
                continue;
            }
            if ($o['group'] !== null) {
                $members = [];
                foreach ($this->groups[$o['group']] as $member) {
                    $members[] = $this->usagePart($member);
                    $done[$member] = true;
                }
                $parts[] = '[' . implode(' | ', $members) . ']';
                continue;
            }
            $part = $this->usagePart($index);
            $parts[] = $o['required'] ? $part : "[{$part}]";
        }
        foreach ($this->positionals as $p) {
            $parts[] = $p['nargs'] === '*' ? "[{$p['metavar']} ...]" : $p['metavar'];
        }

        $prefix = "usage: {$this->prog} ";
        $width = self::width();
        $lines = [];
        $line = '';
        foreach ($parts as $part) {
            if ($line !== '' && strlen($prefix) + strlen($line) + 1 + strlen($part) > $width) {
                $lines[] = $line;
                $line = $part;
            } else {
                $line = $line === '' ? $part : "{$line} {$part}";
            }
        }
        $lines[] = $line;

        return $prefix . implode("\n" . str_repeat(' ', strlen($prefix)), $lines) . "\n";
    }

    public function formatHelp(): string
    {
        $out = $this->formatUsage();
        if ($this->description !== null) {
            $out .= "\n" . ($this->rawDescription
                ? rtrim($this->description) . "\n"
                : self::wrap(preg_replace('/\s+/', ' ', trim($this->description)), self::width(), '') . "\n");
        }
        if ($this->positionals !== []) {
            $out .= "\npositional arguments:\n";
            foreach ($this->positionals as $p) {
                $out .= self::helpEntry($p['metavar'], $p['help']);
            }
        }
        $out .= "\noptions:\n";
        foreach ($this->options as $o) {
            $invocation = implode(', ', $o['names']);
            if ($o['action'] === 'store') {
                $invocation .= ' ' . $o['metavar'];
            }
            $out .= self::helpEntry($invocation, $o['help']);
        }

        return $out;
    }

    private function usagePart(int $index): string
    {
        $o = $this->options[$index];
        if ($o['action'] === 'boolean') {
            return implode(' | ', $o['names']);
        }
        $name = $o['names'][0];

        return $o['action'] === 'store' ? "{$name} {$o['metavar']}" : $name;
    }

    /**
     * @param list<string>          $args
     * @param array<string, mixed>  $values
     * @param array<int, string>    $used
     */
    private function apply(int $index, string $given, ?string $explicit, array $args, int &$i, array &$values, array &$used): void
    {
        $o = $this->options[$index];
        $display = implode('/', $o['names']);
        if ($o['action'] === 'help') {
            throw new HelpRequested($this->formatHelp());
        }
        if ($o['group'] !== null) {
            foreach ($this->groups[$o['group']] as $other) {
                if ($other !== $index && isset($used[$other])) {
                    throw new UsageError("argument {$display}: not allowed with argument " . implode('/', $this->options[$other]['names']));
                }
            }
        }
        $used[$index] = $given;

        switch ($o['action']) {
            case 'store_true':
            case 'store_const':
            case 'boolean':
                if ($explicit !== null) {
                    throw new UsageError("argument {$display}: ignored explicit argument '{$explicit}'");
                }
                $values[$o['dest']] = match ($o['action']) {
                    'store_true' => true,
                    'store_const' => $o['const'],
                    default => !str_starts_with($given, '--no-'),
                };

                return;
        }

        if ($explicit === null) {
            $next = $args[$i + 1] ?? null;
            if ($next === null || ($next !== '' && $next[0] === '-' && $next !== '-' && !self::isNegativeNumber($next))) {
                throw new UsageError("argument {$display}: expected one argument");
            }
            $explicit = $next;
            $i++;
        }
        $values[$o['dest']] = $this->convert($o, $display, $explicit);
    }

    /** @param array<string, mixed> $o */
    private function convert(array $o, string $display, string $value): mixed
    {
        $type = $o['type'];
        if ($type === 'float') {
            if (!is_numeric(trim($value)) && !in_array(strtolower(trim($value)), ['inf', '+inf', '-inf', 'infinity', 'nan'], true)) {
                throw new UsageError("argument {$display}: invalid float value: '{$value}'");
            }

            return is_numeric(trim($value)) ? (float) trim($value) : (float) str_ireplace(['infinity', 'inf', 'nan'], ['INF', 'INF', 'NAN'], trim($value));
        }
        if ($type === 'int') {
            if (!preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
                throw new UsageError("argument {$display}: invalid int value: '{$value}'");
            }

            return (int) trim($value);
        }
        if ($type instanceof \Closure) {
            try {
                return $type($value);
            } catch (\InvalidArgumentException $e) {
                throw new UsageError("argument {$display}: {$e->getMessage()}");
            }
        }

        return $value;
    }

    /** The full option string a long option or its abbreviation stands for. */
    private function resolveLong(string $name): ?string
    {
        $all = array_values(array_filter($this->optionStrings(), static fn ($s) => str_starts_with($s, '--')));
        if (in_array($name, $all, true)) {
            return $name;
        }
        $matches = array_values(array_filter($all, static fn ($s) => str_starts_with($s, $name)));
        if (count($matches) > 1) {
            throw new UsageError("ambiguous option: {$name} could match " . implode(', ', $matches));
        }

        return $matches[0] ?? null;
    }

    private function findExact(string $name): ?int
    {
        foreach ($this->options as $index => $o) {
            if (in_array($name, $o['names'], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function longest(array $names): string
    {
        foreach ($names as $n) {
            if (str_starts_with($n, '--')) {
                return $n;
            }
        }

        return $names[0];
    }

    private static function isNegativeNumber(string $arg): bool
    {
        return (bool) preg_match('/^-\d+$|^-\d*\.\d+$/', $arg);
    }

    private static function helpEntry(string $invocation, ?string $help): string
    {
        $width = self::width();
        $column = min(24, max(11, $width - 20));
        $line = '  ' . $invocation;
        if ($help === null || $help === '') {
            return $line . "\n";
        }
        $wrapped = explode("\n", self::wrap($help, max(11, $width - $column), ''));
        if (strlen($line) <= $column - 2) {
            $out = str_pad($line, $column) . array_shift($wrapped) . "\n";
        } else {
            $out = $line . "\n";
        }
        foreach ($wrapped as $rest) {
            $out .= str_repeat(' ', $column) . $rest . "\n";
        }

        return $out;
    }

    private static function wrap(string $text, int $width, string $indent): string
    {
        return $indent . wordwrap($text, $width - strlen($indent), "\n" . $indent, false);
    }

    /** Text width, like argparse: the terminal's columns minus 2. */
    private static function width(): int
    {
        $columns = getenv('COLUMNS');

        return (is_string($columns) && ctype_digit($columns) && (int) $columns > 20 ? (int) $columns : 80) - 2;
    }
}
