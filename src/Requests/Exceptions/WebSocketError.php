<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

use CurlCffi\Curl\CurlError;

/**
 * WebSocket-specific error. The code is a WsCloseCode or a CurlECode, as in
 * Python's curl_cffi.requests.WebSocketError.
 */
class WebSocketError extends CurlError
{
}
