<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/**
 * A WebSocket receive or send timed out. In Python this is also a Timeout;
 * here it is a WebSocketError.
 */
class WebSocketTimeout extends WebSocketError
{
}
