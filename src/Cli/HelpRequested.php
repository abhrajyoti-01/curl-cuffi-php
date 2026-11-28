<?php

declare(strict_types=1);

namespace CurlCffi\Cli;

/**
 * -h or --help was given. The message is the help text to print before
 * exiting with status 0.
 *
 * @internal
 */
final class HelpRequested extends \RuntimeException
{
}
