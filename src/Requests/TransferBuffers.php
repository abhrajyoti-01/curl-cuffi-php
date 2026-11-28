<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

/**
 * Body and raw header bytes collected during one transfer.
 *
 * @internal
 */
final class TransferBuffers
{
    public string $body = '';
    public string $headers = '';
}
