<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

/**
 * Raised when a libcurl call fails. getCode() is the CURLcode, see
 * CurlCffi\Curl\Const\CurlECode. Mirrors curl_cffi.CurlError.
 */
class CurlError extends \RuntimeException
{
}
