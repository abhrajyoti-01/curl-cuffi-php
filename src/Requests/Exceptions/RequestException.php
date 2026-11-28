<?php

declare(strict_types=1);

namespace CurlCffi\Requests\Exceptions;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\CurlError;
use CurlCffi\Requests\Response;

/**
 * Base exception of the requests API, like curl_cffi.requests.RequestException.
 * getCode() is the CURLcode (0 for errors not raised by libcurl) and
 * $response holds what was received before the error, if anything.
 */
class RequestException extends CurlError
{
    private const CODE2ERROR = [
        CurlECode::UNSUPPORTED_PROTOCOL => InvalidSchema::class,
        CurlECode::URL_MALFORMAT => InvalidURL::class,
        CurlECode::COULDNT_RESOLVE_PROXY => ProxyError::class,
        CurlECode::COULDNT_RESOLVE_HOST => DNSError::class,
        CurlECode::COULDNT_CONNECT => ConnectionError::class,
        CurlECode::WEIRD_SERVER_REPLY => ConnectionError::class,
        CurlECode::REMOTE_ACCESS_DENIED => ConnectionError::class,
        CurlECode::HTTP2 => HTTPError::class,
        CurlECode::HTTP_RETURNED_ERROR => HTTPError::class,
        CurlECode::WRITE_ERROR => RequestException::class,
        CurlECode::READ_ERROR => RequestException::class,
        CurlECode::OUT_OF_MEMORY => RequestException::class,
        CurlECode::OPERATION_TIMEDOUT => Timeout::class,
        CurlECode::SSL_CONNECT_ERROR => SSLError::class,
        CurlECode::INTERFACE_FAILED => InterfaceError::class,
        CurlECode::TOO_MANY_REDIRECTS => TooManyRedirects::class,
        CurlECode::UNKNOWN_OPTION => RequestException::class,
        CurlECode::SETOPT_OPTION_SYNTAX => RequestException::class,
        CurlECode::GOT_NOTHING => ConnectionError::class,
        CurlECode::SSL_ENGINE_NOTFOUND => SSLError::class,
        CurlECode::SSL_ENGINE_SETFAILED => SSLError::class,
        CurlECode::SEND_ERROR => ConnectionError::class,
        CurlECode::SEND_FAIL_REWIND => UnrewindableBodyError::class,
        CurlECode::RECV_ERROR => ConnectionError::class,
        CurlECode::SSL_CERTPROBLEM => SSLError::class,
        CurlECode::SSL_CIPHER => SSLError::class,
        CurlECode::PEER_FAILED_VERIFICATION => CertificateVerifyError::class,
        CurlECode::BAD_CONTENT_ENCODING => HTTPError::class,
        CurlECode::SSL_ENGINE_INITFAILED => SSLError::class,
        CurlECode::SSL_CACERT_BADFILE => SSLError::class,
        CurlECode::SSL_CRL_BADFILE => SSLError::class,
        CurlECode::SSL_ISSUER_ERROR => SSLError::class,
        CurlECode::SSL_PINNEDPUBKEYNOTMATCH => SSLError::class,
        CurlECode::SSL_INVALIDCERTSTATUS => SSLError::class,
        CurlECode::HTTP2_STREAM => HTTPError::class,
        CurlECode::HTTP3 => HTTPError::class,
        CurlECode::QUIC_CONNECT_ERROR => ConnectionError::class,
        CurlECode::PROXY => ProxyError::class,
        CurlECode::SSL_CLIENTCERT => SSLError::class,
        CurlECode::ECH_REQUIRED => SSLError::class,
        CurlECode::PARTIAL_FILE => IncompleteRead::class,
    ];

    public function __construct(
        string $message = '',
        int $code = 0,
        public readonly ?Response $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The exception class for a CURLcode, like curl_cffi's code2error().
     *
     * @return class-string<RequestException>
     */
    public static function classFor(int $code, string $message = ''): string
    {
        if ($code === CurlECode::RECV_ERROR && str_contains($message, 'CONNECT')) {
            return ProxyError::class;
        }

        return self::CODE2ERROR[$code] ?? self::class;
    }

    /** Build the right subclass for a CurlError raised by a transfer. */
    public static function fromCurlError(CurlError $e, ?Response $response = null): self
    {
        $class = self::classFor($e->getCode(), $e->getMessage());

        return new $class($e->getMessage(), $e->getCode(), $response, $e);
    }
}
