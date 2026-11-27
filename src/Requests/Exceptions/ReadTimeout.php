<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/** The server did not send any data in the allotted amount of time. */
class ReadTimeout extends Timeout
{
}
