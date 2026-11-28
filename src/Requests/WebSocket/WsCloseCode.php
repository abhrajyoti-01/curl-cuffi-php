<?php

declare(strict_types=1);

namespace CurlCffi\Requests\WebSocket;

/** WebSocket close codes, see https://www.iana.org/assignments/websocket/websocket.xhtml */
final class WsCloseCode
{
    public const OK = 1000;
    public const GOING_AWAY = 1001;
    public const PROTOCOL_ERROR = 1002;
    public const UNSUPPORTED_DATA = 1003;
    public const UNKNOWN = 1005;
    public const ABNORMAL_CLOSURE = 1006;
    public const INVALID_DATA = 1007;
    public const POLICY_VIOLATION = 1008;
    public const MESSAGE_TOO_BIG = 1009;
    public const MANDATORY_EXTENSION = 1010;
    public const INTERNAL_ERROR = 1011;
    public const SERVICE_RESTART = 1012;
    public const TRY_AGAIN_LATER = 1013;
    public const BAD_GATEWAY = 1014;
    public const TLS_HANDSHAKE = 1015;
    public const UNAUTHORIZED = 3000;
    public const FORBIDDEN = 3003;
    public const TIMEOUT = 3008;
}
