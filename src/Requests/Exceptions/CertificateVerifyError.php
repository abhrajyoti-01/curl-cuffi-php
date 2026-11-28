<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/** Raised when certificate validation has failed. */
class CertificateVerifyError extends SSLError
{
}
