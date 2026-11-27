<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/** The request body could not be rewound for a redirect or retry. */
class UnrewindableBodyError extends RequestException
{
}
