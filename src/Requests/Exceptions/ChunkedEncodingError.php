<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/** The server declared chunked encoding but sent an invalid chunk. */
class ChunkedEncodingError extends RequestException
{
}
