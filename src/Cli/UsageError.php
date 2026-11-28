<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * A command line that does not parse. The command prints the usage and the
 * message to stderr and exits with status 2, like argparse.
 *
 * @internal
 */
final class UsageError extends \RuntimeException
{
}
