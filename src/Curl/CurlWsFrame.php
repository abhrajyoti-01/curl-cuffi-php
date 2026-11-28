<?php

declare(strict_types=1);

namespace CurlCffi\Curl;

/**
 * Metadata of a received WebSocket frame (struct curl_ws_frame), copied out
 * of libcurl so it stays valid after the next receive.
 *
 * flags is a mix of CurlWsFlag values; bytesleft is what remains of this
 * frame (0 at its end).
 */
final class CurlWsFrame
{
    public function __construct(
        public readonly int $age,
        public readonly int $flags,
        public readonly int $offset,
        public readonly int $bytesleft,
        public readonly int $len,
    ) {
    }
}
