<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * A failure the command reports as "Error: <message>" on stderr before
 * exiting with status 1, like Python's `print(..., file=sys.stderr);
 * sys.exit(1)`.
 *
 * @internal
 */
final class CliError extends \RuntimeException
{
}
