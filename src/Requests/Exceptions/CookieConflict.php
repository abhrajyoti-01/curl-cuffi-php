<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/** Same cookie exists for different domains. */
class CookieConflict extends RequestException
{
}
