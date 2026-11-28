<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

/**
 * The WebSocket is closed. In Python this is also a SessionClosed; PHP has
 * no multiple inheritance, so catch WebSocketClosed or WebSocketError.
 */
class WebSocketClosed extends WebSocketError
{
}
