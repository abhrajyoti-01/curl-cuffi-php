<?php

declare(strict_types=1);

namespace CurlCffi\Requests;

use CurlCffi\Curl\Const\CurlECode;
use CurlCffi\Curl\Const\CurlFollow;
use CurlCffi\Curl\Const\CurlHttpVersion;
use CurlCffi\Curl\Const\CurlInfo;
use CurlCffi\Curl\Const\CurlOpt;
use CurlCffi\Curl\Curl;
use CurlCffi\Curl\CurlError;
use CurlCffi\Curl\CurlMime;
use CurlCffi\Curl\CurlShare;
use CurlCffi\Ffi\Library;
use CurlCffi\Impersonate\BrowserType;
use CurlCffi\Impersonate\ExtraFingerprints;
use CurlCffi\Impersonate\Fingerprint;
use CurlCffi\Impersonate\FingerprintManager;
use CurlCffi\Impersonate\FingerprintOptions;
use CurlCffi\Impersonate\NativeTargets;
use CurlCffi\Requests\Cache\CacheBackend;
use CurlCffi\Requests\Exceptions\ImpersonateError;
use CurlCffi\Requests\Exceptions\RequestException;
use CurlCffi\Requests\Exceptions\SessionClosed;
use CurlCffi\Warnings;

/**
 * Settings, cookies and request/response handling shared by Session and
 * AsyncSession, like curl_cffi's BaseSession.
 */
abstract class BaseSession
{
    public Headers $headers;
    /** @var array{0: string, 1: string}|null */
    public ?array $auth;
    public ?string $baseUrl;
    /** @var array<mixed>|null */
    public ?array $params;
    public bool|string $verify;
    /** @var float|int|array{0: float|int, 1: float|int}|null */
    public float|int|array|null $timeout;
    public bool $trustEnv;
    public bool|int|string $allowRedirects;
    public int $maxRedirects;
    public RetryStrategy $retry;
    public BrowserType|Fingerprint|string|null $impersonate;
    public ?string $ja3;
    public ?string $akamai;
    public ?string $perk;
    /** @var ExtraFingerprints|array<string, mixed>|null */
    public ExtraFingerprints|array|null $extraFp;
    public bool $defaultHeaders;
    /** @var string|callable(string): string */
    public mixed $defaultEncoding;
    /** @var array<int, mixed> CurlOpt => value, applied last on every request */
    public array $curlOptions;
    /** @var list<int> CurlInfo values copied to Response::$infos */
    public array $curlInfos;
    public int|string|null $httpVersion;
    public bool $debug;
    public ?string $interface;
    /** @var string|list<string>|null */
    public string|array|null $dns;
    public ?string $dohUrl;
    /** @var string|array{0: string, 1: string}|null */
    public string|array|null $cert;
    /** @var array<string, string> scheme or "all" => proxy URL */
    public array $proxies;
    /** @var array{0: string, 1: string}|null */
    public ?array $proxyAuth;
    /** @var class-string<Response> */
    public string $responseClass;
    public bool $discardCookies;
    public bool $raiseForStatus;

    protected Cookies $cookieJar;
    protected ?CacheBackend $cache;
    protected ?CurlShare $curlShare;
    protected bool $closed = false;
    private ?bool $hasCookieChanges = null;

    public function __construct(mixed ...$options)
    {
        $o = Options::normalize($options, Options::SESSION, 'Session');

        $this->headers = new Headers($o['headers'] ?? null);
        $this->cookieJar = new Cookies($o['cookies'] ?? null);
        $this->auth = $o['auth'] ?? null;
        $this->baseUrl = $o['base_url'] ?? null;
        $this->params = $o['params'] ?? null;
        $this->verify = $o['verify'] ?? true;
        $this->timeout = array_key_exists('timeout', $o) ? $o['timeout'] : 30;
        $this->trustEnv = $o['trust_env'] ?? true;
        $this->allowRedirects = $o['allow_redirects'] ?? true;
        $this->maxRedirects = $o['max_redirects'] ?? 30;
        $this->retry = RetryStrategy::normalize($o['retry'] ?? 0);
        $this->impersonate = $o['impersonate'] ?? null;
        $this->ja3 = $o['ja3'] ?? null;
        $this->akamai = $o['akamai'] ?? null;
        $this->perk = $o['perk'] ?? null;
        $this->extraFp = $o['extra_fp'] ?? null;
        $this->defaultHeaders = $o['default_headers'] ?? true;
        $this->defaultEncoding = $o['default_encoding'] ?? 'utf-8';
        $this->curlOptions = $o['curl_options'] ?? [];
        $this->curlInfos = $o['curl_infos'] ?? [];
        $this->httpVersion = $o['http_version'] ?? null;
        $this->debug = $o['debug'] ?? false;
        $this->interface = $o['interface'] ?? null;
        $this->dns = $o['dns'] ?? null;
        $this->dohUrl = $o['doh_url'] ?? null;
        $this->cert = $o['cert'] ?? null;
        $this->discardCookies = $o['discard_cookies'] ?? false;
        $this->raiseForStatus = $o['raise_for_status'] ?? false;
        $this->cache = CacheBackend::normalize($o['cache'] ?? null);
        $share = $o['curl_share'] ?? null;
        if ($share !== null && !$share instanceof CurlShare) {
            throw new \TypeError('`curl_share` must be a ' . CurlShare::class);
        }
        $this->curlShare = $share;

        $responseClass = $o['response_class'] ?? Response::class;
        if (!is_a($responseClass, Response::class, true)) {
            throw new \TypeError('`response_class` must be a subclass of ' . Response::class . ", not {$responseClass}");
        }
        $this->responseClass = $responseClass;

        $proxy = $o['proxy'] ?? null;
        $proxies = $o['proxies'] ?? null;
        if ($proxy !== null && $proxies) {
            throw new \TypeError("Cannot specify both 'proxy' and 'proxies'");
        }
        $this->proxies = $proxy !== null ? ['all' => $proxy] : ($proxies ?? []);
        $this->proxyAuth = $o['proxy_auth'] ?? null;

        if ($this->baseUrl !== null && !Url::isAbsolute($this->baseUrl)) {
            throw new \InvalidArgumentException("You need to provide an absolute url for 'base_url'");
        }

        if ($this->verify === true) {
            $env = getenv('REQUESTS_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE');
            if (is_string($env) && $env !== '') {
                $this->verify = $env;
            }
        }
    }


    /** The session's cookie jar. */
    public function cookies(): Cookies
    {
        return $this->cookieJar;
    }

    /** @param Cookies|array<string, string> $cookies */
    public function setCookies(Cookies|array $cookies): void
    {
        $this->cookieJar = new Cookies($cookies);
    }

    /** The response cache, or null when caching is off, like Python's cache_backend. */
    public function cacheBackend(): ?CacheBackend
    {
        return $this->cache;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'cookies') {
            return $this->cookieJar;
        }
        if ($name === 'cache_backend' || $name === 'cacheBackend') {
            return $this->cache;
        }
        throw new \LogicException("Undefined property Session::\${$name}");
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === 'cookies') {
            $this->setCookies($value);

            return;
        }
        throw new \LogicException("Undefined property Session::\${$name}");
    }







    /** Free the session's handles. The session cannot be used afterwards. */
    abstract public function close(): void;

    /**
     * Run one request synchronously on $c. A streamed request ($o['stream'])
     * must get a handle of its own, which the response then owns.
     *
     * @param array<string, mixed> $o normalised request options
     */
    protected function performSync(string $method, string $url, array $o, Curl $c): Response
    {
        $stream = (bool) ($o['stream'] ?? false);
        $ownedMime = null;

        try {
            [$request, $buffers, $ownedMime, $injected] = $this->setCurlOptions($c, $method, $url, $o, $stream);
        } catch (\Throwable $e) {
            $c->reset();
            if ($stream) {
                $c->close();
            }
            throw $e;
        }

        $defaultEncoding = $o['default_encoding'] ?? $this->defaultEncoding;
        $discardCookies = ($o['discard_cookies'] ?? false) || $this->discardCookies;
        $useCache = $this->cache?->shouldCacheRequest($request, $stream, $o['content_callback'] ?? null) ?? false;

        if ($useCache && ($cached = $this->cache->get($request, $this->responseClass)) !== null) {
            if (!$discardCookies) {
                $this->cookieJar->update($cached->cookies);
            }
            $ownedMime?->close();
            $c->reset();
            if ($this->raiseForStatus) {
                $cached->raiseForStatus();
            }

            return $cached;
        }

        if ($stream) {
            $transfer = new StreamTransfer($c, function (CurlError $e) use ($c, $buffers, $request, $defaultEncoding, $discardCookies) {
                $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, []);
                $rsp->request = $request;

                return RequestException::fromCurlError($e, $rsp);
            });
            $c->setopt(CurlOpt::WRITEFUNCTION, $transfer->writer());
            $transfer->start();

            $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
            $rsp->request = $request;
            if ($transfer->isDone() && ($error = $transfer->error()) !== null) {
                $transfer->close();
                $ownedMime?->close();
                throw $error;
            }
            // The mime form must outlive the transfer, so the transfer owns it.
            $transfer->own($ownedMime);
            $rsp->attachStream($transfer);
            if ($this->raiseForStatus) {
                $rsp->raiseForStatus();
            }

            return $rsp;
        }

        try {
            try {
                $c->perform();
            } catch (CurlError $e) {
                $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
                $rsp->request = $request;
                throw RequestException::fromCurlError($e, $rsp);
            }
            $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
            $rsp->request = $request;
            if ($useCache) {
                $this->cache->set($request, $rsp);
            }
        } finally {
            $ownedMime?->close();
            $c->reset();
        }
        if ($this->raiseForStatus) {
            $rsp->raiseForStatus();
        }

        return $rsp;
    }

    /**
     * WebSocket handshake on $c, for wsConnect() and WebSocket::connect():
     * the options apply as for a GET request, then curl connects with
     * CONNECT_ONLY = 2 and keeps the connection for curl_ws_send/recv.
     * Cookies set by the handshake response go into the session.
     *
     * @internal
     *
     * @param array<string, mixed> $o normalised Options::WS_HANDSHAKE options
     */
    public function wsHandshake(Curl $c, string $url, array $o): Response
    {
        $this->checkClosed();
        // perform() can't be interrupted, so "no timeout" is clamped, as in Python.
        $timeout = array_key_exists('timeout', $o) ? $o['timeout'] : $this->timeout;
        $o['timeout'] = $timeout ?? WebSocket\BaseWebSocket::MAX_HANDSHAKE_SECS;
        $extra = $o['curl_options'] ?? [];
        unset($o['curl_options']);

        [$request, $buffers, $ownedMime, $injected] = $this->setCurlOptions($c, 'GET', $url, $o, false);
        foreach ($extra as $option => $value) {
            $c->setopt((int) $option, $value);
        }
        $c->setopt(CurlOpt::TCP_NODELAY, 1);
        $c->setopt(CurlOpt::CONNECT_ONLY, 2);   // https://curl.se/docs/websocket.html

        $defaultEncoding = $this->defaultEncoding;
        $discardCookies = $this->discardCookies;
        try {
            $c->perform();
        } catch (CurlError $e) {
            $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
            $rsp->request = $request;

            throw RequestException::fromCurlError($e, $rsp);
        } finally {
            $ownedMime?->close();
        }
        $rsp = $this->parseResponse($c, $buffers, $request, $defaultEncoding, $discardCookies, $injected);
        $rsp->request = $request;

        return $rsp;
    }

    /**
     * Port of curl_cffi's set_curl_options(): turn request options plus
     * session defaults into curl options.
     *
     * @param array<string, mixed> $o
     *
     * @return array{0: Request, 1: TransferBuffers, 2: ?CurlMime, 3: list<Cookie>}
     */
    protected function setCurlOptions(Curl $c, string $method, string $url, array $o, bool $stream): array
    {
        if ($this->curlShare !== null && $c->attachedShare() !== $this->curlShare) {
            $c->setopt(CurlOpt::SHARE, $this->curlShare);
        }
        $buffers = new TransferBuffers();
        $content = $o['content'] ?? null;
        $data = $o['data'] ?? null;
        $json = $o['json'] ?? null;
        $hasJson = array_key_exists('json', $o) && $json !== null;
        $multipart = $o['multipart'] ?? null;
        $ownedMime = null;

        // body
        $streamReader = null;
        if ($content !== null) {
            if (is_string($content)) {
                $body = $content;
            } elseif (is_resource($content)) {
                $streamReader = static fn (int $max): string => (string) fread($content, max(1, $max));
                $body = '';
            } elseif (is_iterable($content)) {
                $iterator = (static fn () => yield from $content)();
                $pending = '';
                $streamReader = static function (int $max) use ($iterator, &$pending): string {
                    while ($pending === '' && $iterator->valid()) {
                        $pending = (string) $iterator->current();
                        $iterator->next();
                    }
                    $chunk = substr($pending, 0, $max);
                    $pending = (string) substr($pending, strlen($chunk));

                    return $chunk;
                };
                $body = '';
            } else {
                throw new \TypeError('content must be a string, a stream resource or an iterable of strings');
            }
        } elseif (is_array($data)) {
            $body = Url::encodeForm($data);
        } elseif (is_string($data)) {
            $body = $data;
        } elseif (is_resource($data)) {
            $body = (string) stream_get_contents($data);
        } elseif ($data === null) {
            $body = '';
        } else {
            throw new \TypeError('data must be an array, a string or a stream resource');
        }
        if ($hasJson) {
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $requestBody = (($content !== null || $data !== null || $hasJson) && $multipart === null && $streamReader === null)
            ? $body : null;

        // method
        if ($method === 'POST' && $streamReader === null) {
            $c->setopt(CurlOpt::POST, 1);
        } elseif ($method !== 'GET') {
            $c->setopt(CurlOpt::CUSTOMREQUEST, $method);
        }
        if ($method === 'HEAD') {
            $c->setopt(CurlOpt::NOBODY, 1);
        }

        // url
        if ($this->params) {
            $url = Url::updateParams($url, $this->params);
        }
        if (!empty($o['params'])) {
            $url = Url::updateParams($url, $o['params']);
        }
        if ($this->baseUrl !== null) {
            $url = Url::join($this->baseUrl, $url);
        }
        $quote = $o['quote'] ?? '';
        if (is_string($quote) && $quote !== '') {
            $url = Url::quotePathAndParams($url, $quote);
        }
        if ($quote !== false) {
            $url = Url::requote($url);
        }
        $c->setopt(CurlOpt::URL, $url);

        if ($streamReader !== null) {
            $c->setopt(CurlOpt::UPLOAD, 1);
            $c->setopt(CurlOpt::READFUNCTION, $streamReader);
            $c->setopt(CurlOpt::CUSTOMREQUEST, $method);
        } elseif ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $c->setopt(CurlOpt::POSTFIELDS, $body);
            if ($method === 'GET') {
                $c->setopt(CurlOpt::CUSTOMREQUEST, $method);
            }
        }

        // headers
        $h = new Headers($this->headers);
        $h->update($o['headers'] ?? null);
        if ($streamReader !== null) {
            $length = null;
            if (is_resource($content)) {
                $stat = fstat($content);
                if (is_array($stat) && ($stat['size'] ?? 0) > 0 && @ftell($content) === 0) {
                    $length = (string) $stat['size'];
                    $h->set('Content-Length', $length);
                }
            }
            $length ??= $h->get('content-length');
            if ($length !== null) {
                $c->setopt(CurlOpt::INFILESIZE_LARGE, (int) $length);
            }
        }
        $lines = [];
        foreach ($h->multiItems() as [$k, $v]) {
            if ($v === null) {
                $lines[] = "{$k}:";       // explicitly disable this header
            } elseif ($v === '') {
                $lines[] = "{$k};";       // send an empty header
            } else {
                $lines[] = "{$k}: {$v}";
            }
        }
        if ($hasJson) {
            self::updateHeaderLine($lines, 'Content-Type', 'application/json');
        }
        if ($content === null && is_array($data) && $method !== 'POST') {
            self::updateHeaderLine($lines, 'Content-Type', 'application/x-www-form-urlencoded');
        }
        $bodyData = $content ?? $data;
        if (is_string($bodyData) && $bodyData !== '') {
            self::updateHeaderLine($lines, 'Content-Type', 'application/octet-stream');
        }
        self::updateHeaderLine($lines, 'Expect', '', true);   // never send Expect
        $c->setopt(CurlOpt::HTTPHEADER, $lines);

        $request = new Request($url, $h, $method, $requestBody);

        // cookies: load every applicable cookie into curl's engine
        $c->setopt(CurlOpt::COOKIEFILE, '');
        $c->setopt(CurlOpt::COOKIELIST, 'ALL');
        $host = Url::host($url);
        $injected = [];
        foreach ($this->cookieJar->forCurl($host) as $cookie) {
            $c->setopt(CurlOpt::COOKIELIST, $cookie->toCurlFormat());
            $injected[] = $cookie;
        }
        if (!empty($o['cookies'])) {
            foreach ((new Cookies($o['cookies']))->forCurl($host) as $cookie) {
                $c->setopt(CurlOpt::COOKIELIST, $cookie->toCurlFormat());
                $injected[] = $cookie;
            }
        }

        // files (PHP extra: Python curl_cffi only takes `multipart`)
        if (!empty($o['files'])) {
            if ($multipart !== null) {
                throw new \TypeError("Cannot use both 'files' and 'multipart'");
            }
            $multipart = $ownedMime = self::mimeFromFiles($o['files']);
        }

        // multipart: overrides POSTFIELDS; data entries become extra fields
        if ($multipart !== null) {
            if (!$multipart instanceof CurlMime) {
                throw new \TypeError('multipart must be a ' . CurlMime::class);
            }
            foreach (is_array($data) ? $data : [] as $k => $v) {
                $multipart->addpart(name: (string) $k, data: (string) $v);
            }
            $multipart->attach($c);
        }

        // auth
        $auth = $o['auth'] ?? $this->auth;
        if ($auth) {
            [$user, $password] = $auth;
            $c->setopt(CurlOpt::USERNAME, (string) $user);
            $c->setopt(CurlOpt::PASSWORD, (string) $password);
        }

        // timeout
        $timeout = array_key_exists('timeout', $o) ? $o['timeout'] : $this->timeout;
        $timeout ??= 0;
        if (is_array($timeout)) {
            [$connect, $read] = $timeout;
            $all = $connect + $read;
            $c->setopt(CurlOpt::CONNECTTIMEOUT_MS, (int) ($connect * 1000));
            if (!$stream) {
                $c->setopt(CurlOpt::TIMEOUT_MS, (int) ($all * 1000));
            } else {
                $c->setopt(CurlOpt::LOW_SPEED_LIMIT, 1);
                $c->setopt(CurlOpt::LOW_SPEED_TIME, (int) ceil($all));
            }
        } else {
            if (!$stream) {
                $c->setopt(CurlOpt::TIMEOUT_MS, (int) ($timeout * 1000));
            } else {
                $c->setopt(CurlOpt::CONNECTTIMEOUT_MS, (int) ($timeout * 1000));
                $c->setopt(CurlOpt::LOW_SPEED_LIMIT, 1);
                $c->setopt(CurlOpt::LOW_SPEED_TIME, (int) ceil($timeout));
            }
        }

        // redirects
        $allowRedirects = $o['allow_redirects'] ?? $this->allowRedirects;
        $c->setopt(CurlOpt::FOLLOWLOCATION, $allowRedirects === 'safe' ? CurlFollow::SAFE : (int) $allowRedirects);
        $c->setopt(CurlOpt::MAXREDIRS, (int) ($o['max_redirects'] ?? $this->maxRedirects));

        // proxies
        $proxy = $o['proxy'] ?? null;
        $proxies = $o['proxies'] ?? null;
        if ($proxy !== null && $proxies) {
            throw new \TypeError("Cannot specify both 'proxy' and 'proxies'");
        }
        if ($proxy !== null) {
            $proxies = ['all' => $proxy];
        }
        $proxies ??= $this->proxies;
        $proxySet = false;
        if ($proxies) {
            if (Library::supportsImpersonation()) {
                // New connection and TLS session per proxy credentials (curl-impersonate option).
                $c->setopt(CurlOpt::PROXY_CREDENTIAL_NO_REUSE, 1);
            }
            $parts = parse_url($url) ?: [];
            $scheme = $parts['scheme'] ?? '';
            $selected = $proxies[$scheme] ?? $proxies['all'] ?? null;
            if (isset($parts['host'])) {
                $selected = $proxies["{$scheme}://{$parts['host']}"] ?? $proxies["all://{$parts['host']}"] ?? $selected;
            }
            if ($selected !== null) {
                $c->setopt(CurlOpt::PROXY, $selected);
                $proxySet = true;
                if ($scheme === 'https' && !str_starts_with($selected, 'socks')) {
                    $c->setopt(CurlOpt::HTTPPROXYTUNNEL, 1);
                }
                $proxyAuth = $o['proxy_auth'] ?? $this->proxyAuth;
                if ($proxyAuth) {
                    [$user, $password] = $proxyAuth;
                    $c->setopt(CurlOpt::PROXYUSERNAME, (string) $user);
                    $c->setopt(CurlOpt::PROXYPASSWORD, (string) $password);
                }
            }
        }
        if (!$this->trustEnv) {
            if (!$proxySet) {
                $c->setopt(CurlOpt::PROXY, '');
            }
            $c->setopt(CurlOpt::NOPROXY, '');
        }

        // verify
        $verify = $o['verify'] ?? null;
        $baseVerify = $this->verify;
        $disableVerify = $verify === false || (!$baseVerify && $verify === null);
        $c->skipCacert($disableVerify);
        if ($disableVerify) {
            $c->setopt(CurlOpt::SSL_VERIFYPEER, 0);
            $c->setopt(CurlOpt::SSL_VERIFYHOST, 0);
        }
        if (is_string($verify)) {
            $c->setopt(CurlOpt::CAINFO, $verify);
            $c->setopt(CurlOpt::PROXY_CAINFO, $verify);
        } elseif (($verify === null || $verify === true) && is_string($baseVerify)) {
            $c->setopt(CurlOpt::CAINFO, $baseVerify);
            $c->setopt(CurlOpt::PROXY_CAINFO, $baseVerify);
        }

        if (!empty($o['referer'])) {
            $c->setopt(CurlOpt::REFERER, (string) $o['referer']);
        }

        $acceptEncoding = array_key_exists('accept_encoding', $o) ? $o['accept_encoding'] : 'gzip, deflate, br';
        if ($acceptEncoding !== null) {
            $c->setopt(CurlOpt::ACCEPT_ENCODING, (string) $acceptEncoding);
        }

        $cert = $o['cert'] ?? $this->cert;
        if ($cert) {
            if (is_string($cert)) {
                $c->setopt(CurlOpt::SSLCERT, $cert);
            } else {
                $c->setopt(CurlOpt::SSLCERT, (string) $cert[0]);
                $c->setopt(CurlOpt::SSLKEY, (string) $cert[1]);
            }
        }

        // http_version: before impersonation, which checks whether HTTP/3 was asked for
        $httpVersion = $o['http_version'] ?? $this->httpVersion;
        if ($httpVersion) {
            $c->setopt(CurlOpt::HTTP_VERSION, self::normalizeHttpVersion($httpVersion));
        }

        // impersonate: a built-in target, a downloaded one, or a Fingerprint
        $impersonate = $o['impersonate'] ?? $this->impersonate;
        $defaultHeaders = (bool) ($o['default_headers'] ?? $this->defaultHeaders);
        // Header names the request set, which fingerprint default headers must not replace.
        $existingHeaderNames = [];
        if ($defaultHeaders) {
            foreach ($lines as $line) {
                $existingHeaderNames[self::headerLineKey($line)] = true;
            }
        }
        try {
            if ($impersonate instanceof Fingerprint) {
                FingerprintOptions::applyFingerprint($c, $impersonate, $existingHeaderNames, $defaultHeaders);
            } elseif ($impersonate !== null && $impersonate !== '') {
                $name = $impersonate instanceof BrowserType ? $impersonate->value : (string) $impersonate;
                if (NativeTargets::isNative($name)) {
                    $target = BrowserType::resolve($name);
                    try {
                        $c->impersonate($target, $defaultHeaders);
                    } catch (CurlError $e) {
                        throw new ImpersonateError("Impersonating {$target} is not supported", $e->getCode(), null, $e);
                    }
                } else {
                    $all = FingerprintManager::loadFingerprints();
                    if (!isset($all[$name])) {
                        throw new ImpersonateError("Impersonating {$name} is not supported");
                    }
                    FingerprintOptions::applyFingerprint($c, $all[$name], $existingHeaderNames, $defaultHeaders);
                }
            }

            $ja3 = $o['ja3'] ?? $this->ja3;
            $extraFp = $o['extra_fp'] ?? $this->extraFp;
            if (is_array($extraFp)) {
                $extraFp = $extraFp === [] ? null : FingerprintOptions::extraFp($extraFp);
            }
            $akamai = $o['akamai'] ?? $this->akamai;
            $perk = $o['perk'] ?? $this->perk;
            $impersonated = $impersonate !== null;

            if ($ja3) {
                if ($impersonated) {
                    Warnings::warn('JA3 fingerprint was altered after impersonated version was set.');
                }
                FingerprintOptions::setJa3Options($c, $ja3, (bool) $extraFp?->tlsPermuteExtensions);
            }
            if ($extraFp !== null) {
                if ($impersonated) {
                    Warnings::warn('Extra fingerprints were altered after impersonated version was set.');
                }
                FingerprintOptions::setExtraFp($c, $extraFp);
            }
            if ($akamai) {
                if ($impersonated) {
                    Warnings::warn('Akamai fingerprint was altered after impersonated version was set.');
                }
                FingerprintOptions::setAkamaiOptions($c, $akamai);
            }
            if ($perk) {
                if ($impersonated) {
                    Warnings::warn('Perk fingerprint was altered after impersonated version was set.');
                }
                FingerprintOptions::setPerkOptions($c, $perk);
            }
        } catch (CurlError $e) {
            if ($e instanceof RequestException) {
                throw $e;
            }
            // Stock libcurl does not know curl-impersonate's fingerprint options.
            throw new ImpersonateError('Setting the fingerprint failed: ' . $e->getMessage()
                . (Library::supportsImpersonation() ? '' : ' (load libcurl-impersonate, see bin/curl-cffi-install)'), $e->getCode(), null, $e);
        }

        // body and header sinks
        if ($stream) {
            // StreamTransfer installs its own WRITEFUNCTION.
        } elseif (isset($o['content_callback'])) {
            $c->setopt(CurlOpt::WRITEFUNCTION, $o['content_callback']);
        } else {
            $c->setopt(CurlOpt::WRITEFUNCTION, static function (string $chunk) use ($buffers): void {
                $buffers->body .= $chunk;
            });
        }
        $c->setopt(CurlOpt::HEADERFUNCTION, static function (string $line) use ($buffers): void {
            $buffers->headers .= $line;
        });

        $interface = $o['interface'] ?? $this->interface;
        if ($interface) {
            $value = $interface;
            if (!str_contains($interface, '!') && filter_var($interface, FILTER_VALIDATE_IP) !== false) {
                $value = "host!{$interface}";
            }
            $c->setopt(CurlOpt::INTERFACE, $value);
        }

        $dns = array_key_exists('dns', $o) ? $o['dns'] : $this->dns;
        if ($dns) {
            $c->setopt(CurlOpt::DNS_SERVERS, is_array($dns) ? implode(',', $dns) : $dns);
        }
        $doh = $o['doh_url'] ?? $this->dohUrl;
        if ($doh) {
            $c->setopt(CurlOpt::DOH_URL, $doh);
        }

        $c->setopt(CurlOpt::MAX_RECV_SPEED_LARGE, (int) ($o['max_recv_speed'] ?? 0));

        if ($this->debug) {
            $c->debug();
        }

        // extra curl options last, so they can override everything above
        foreach ($this->curlOptions as $option => $value) {
            $c->setopt((int) $option, $value);
        }

        return [$request, $buffers, $ownedMime, $injected];
    }

    /** @param list<Cookie> $injected cookies loaded into curl before the transfer */
    protected function parseResponse(Curl $c, TransferBuffers $buffers, Request $request, mixed $defaultEncoding, bool $discardCookies, array $injected): Response
    {
        /** @var Response $rsp */
        $rsp = new ($this->responseClass)($c);
        $rsp->url = (string) $c->getinfo(CurlInfo::EFFECTIVE_URL);
        $rsp->content = $buffers->body;
        $rsp->httpVersion = (int) $c->getinfo(CurlInfo::HTTP_VERSION);
        $rsp->statusCode = (int) $c->getinfo(CurlInfo::RESPONSE_CODE);
        $rsp->ok = $rsp->statusCode >= 200 && $rsp->statusCode < 400;

        // Split the raw header data into one block per response (redirects, proxy CONNECT, 1xx).
        $blocks = [];
        $status = 0;
        $reason = '';
        $lines = [];
        foreach (preg_split("/\r\n|\n/", $buffers->headers) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with($line, 'HTTP/')) {
                if ($status) {
                    $blocks[] = [$status, $reason, $lines];
                }
                [, $status, $reason] = Curl::parseStatusLine($line);
                $lines = [];
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= $line;    // obsolete line folding
                continue;
            }
            $lines[] = $line;
        }
        if ($status) {
            $blocks[] = [$status, $reason, $lines];
        }
        if ($blocks !== []) {
            [, $rsp->reason, $lines] = $blocks[count($blocks) - 1];
        }
        $rsp->headers = Headers::fromLines($lines);

        $rsp->history = $this->buildHistory($c, $blocks, $request->url, $defaultEncoding);

        // Response cookies: only from this response's Set-Cookie headers.
        $rsp->cookies = Cookies::fromSetCookieHeaders($rsp->headers->getList('set-cookie'));

        // Session cookies: everything the transfer changed.
        if (!$discardCookies) {
            $this->updateSessionCookies($c, $injected);
        }

        $rsp->primaryIp = (string) $c->getinfo(CurlInfo::PRIMARY_IP);
        $rsp->primaryPort = (int) $c->getinfo(CurlInfo::PRIMARY_PORT);
        $rsp->localIp = (string) $c->getinfo(CurlInfo::LOCAL_IP);
        $rsp->localPort = (int) $c->getinfo(CurlInfo::LOCAL_PORT);
        $rsp->defaultEncoding = $defaultEncoding;
        $rsp->elapsed = (float) $c->getinfo(CurlInfo::TOTAL_TIME);
        $rsp->redirectCount = (int) $c->getinfo(CurlInfo::REDIRECT_COUNT);
        $rsp->redirectUrl = (string) $c->getinfo(CurlInfo::REDIRECT_URL);
        $rsp->downloadSize = (int) $c->getinfo(CurlInfo::SIZE_DOWNLOAD_T);
        $rsp->uploadSize = (int) $c->getinfo(CurlInfo::SIZE_UPLOAD_T);
        $rsp->headerSize = (int) $c->getinfo(CurlInfo::HEADER_SIZE);
        $rsp->requestSize = (int) $c->getinfo(CurlInfo::REQUEST_SIZE);
        $rsp->responseSize = $rsp->downloadSize + $rsp->headerSize;
        foreach ($this->curlInfos as $info) {
            $rsp->infos[$info] = $c->getinfo($info);
        }

        return $rsp;
    }

    /**
     * @param list<array{0: int, 1: string, 2: list<string>}> $blocks
     *
     * @return list<Response>
     */
    private function buildHistory(Curl $c, array $blocks, string $firstUrl, mixed $defaultEncoding): array
    {
        $entries = [];   // [status, url]
        try {
            // curl-impersonate reports "status\turl" for every redirect hop.
            foreach ((array) $c->getinfo(CurlInfo::REDIRECT_HISTORY) as $item) {
                $parts = explode("\t", (string) $item, 2);
                if (count($parts) === 2 && ctype_digit($parts[0])) {
                    $entries[] = [(int) $parts[0], $parts[1]];
                }
            }
        } catch (CurlError) {
            // Stock libcurl: rebuild the hops from the 3xx header blocks.
            $url = $firstUrl;
            for ($i = 0, $n = count($blocks) - 1; $i < $n; $i++) {
                [$status, , $lines] = $blocks[$i];
                $location = Headers::fromLines($lines)->get('location');
                if ($status < 300 || $status >= 400 || $location === null) {
                    continue;
                }
                $entries[] = [$status, $url];
                $url = Url::join($url, $location);
            }
        }

        $history = [];
        $blockIndex = 0;
        foreach ($entries as [$status, $url]) {
            $reason = '';
            $headers = new Headers();
            for ($i = $blockIndex, $n = count($blocks); $i < $n; $i++) {
                if ($blocks[$i][0] === $status) {
                    $reason = $blocks[$i][1];
                    $headers = Headers::fromLines($blocks[$i][2]);
                    $blockIndex = $i + 1;
                    break;
                }
            }
            /** @var Response $h */
            $h = new ($this->responseClass)(null);
            $h->url = $url;
            $h->statusCode = $status;
            $h->reason = $reason;
            $h->ok = $status >= 200 && $status < 400;
            $h->headers = $headers;
            $h->defaultEncoding = $defaultEncoding;
            $history[] = $h;
        }

        return $history;
    }

    /** @param list<Cookie> $injected */
    private function updateSessionCookies(Curl $c, array $injected): void
    {
        if ($this->hasCookieChanges !== false) {
            try {
                $changes = (array) $c->getinfo(CurlInfo::COOKIECHANGES);
                $this->hasCookieChanges = true;
                $this->cookieJar->updateFromCurlChanges(array_map('strval', $changes));

                return;
            } catch (CurlError $e) {
                if ($e->getCode() !== CurlECode::UNKNOWN_OPTION && $e->getCode() !== CurlECode::BAD_FUNCTION_ARGUMENT) {
                    throw $e;
                }
                $this->hasCookieChanges = false;
            }
        }

        // Stock libcurl: diff curl's cookie list against what was loaded.
        $before = [];
        foreach ($injected as $cookie) {
            $before[$cookie->toCurlFormat()] = true;
        }
        $after = [];
        foreach ((array) $c->getinfo(CurlInfo::COOKIELIST) as $line) {
            $after[(string) $line] = true;
        }
        $changes = [];
        foreach (array_keys($after) as $line) {
            if (!isset($before[$line])) {
                $changes[] = "SET\t{$line}";
            }
        }
        foreach (array_keys($before) as $line) {
            if (!isset($after[$line])) {
                $cookie = Cookie::fromCurlFormat($line);
                $stillThere = false;
                foreach (array_keys($after) as $other) {
                    $o = Cookie::fromCurlFormat($other);
                    if ($o->name === $cookie->name && $o->path === $cookie->path
                        && ltrim($o->domain, '.') === ltrim($cookie->domain, '.')) {
                        $stillThere = true;
                        break;
                    }
                }
                if (!$stillThere) {
                    $changes[] = "DELETE\t{$line}";
                }
            }
        }
        $this->cookieJar->updateFromCurlChanges($changes);
    }

    /** @param array<mixed> $files */
    private static function mimeFromFiles(array $files): CurlMime
    {
        $mime = new CurlMime();
        foreach ($files as $field => $spec) {
            if (is_string($spec)) {
                $mime->addpart(name: (string) $field, filename: basename($spec), localPath: $spec);
                continue;
            }
            if (!is_array($spec)) {
                throw new \TypeError("files[{$field}] must be a path or an array");
            }
            $path = $spec['path'] ?? $spec['local_path'] ?? $spec['localPath'] ?? null;
            $mime->addpart(
                name: (string) $field,
                contentType: $spec['content_type'] ?? $spec['contentType'] ?? null,
                filename: $spec['filename'] ?? ($path !== null ? basename($path) : null),
                localPath: $path,
                data: isset($spec['data']) ? (string) $spec['data'] : null,
            );
        }

        return $mime;
    }

    /** @param list<string> $lines */
    /** Lower-case name of a curl header line ("Name: value", "Name;" or "Name:"). */
    private static function headerLineKey(string $line): string
    {
        $cut = strcspn($line, ':;');

        return strtolower(trim(substr($line, 0, $cut)));
    }

    private static function updateHeaderLine(array &$lines, string $key, string $value, bool $replace = false): void
    {
        $prefix = strtolower($key) . ':';
        foreach ($lines as $i => $line) {
            if (str_starts_with(strtolower($line), $prefix)) {
                if ($replace) {
                    $lines[$i] = "{$key}: {$value}";
                }

                return;
            }
        }
        $lines[] = "{$key}: {$value}";
    }

    private static function normalizeHttpVersion(int|string $version): int
    {
        if (is_int($version)) {
            return $version;
        }

        return match ($version) {
            'v1' => CurlHttpVersion::V1_1,
            'v2' => CurlHttpVersion::V2_0,
            'v2tls' => CurlHttpVersion::V2TLS,
            'v2_prior_knowledge' => CurlHttpVersion::V2_PRIOR_KNOWLEDGE,
            'v3' => CurlHttpVersion::V3,
            'v3only' => CurlHttpVersion::V3ONLY,
            default => throw new \InvalidArgumentException("Unknown http_version '{$version}'"),
        };
    }

    protected function checkClosed(): void
    {
        if ($this->closed) {
            throw new SessionClosed('Session is closed, cannot send request.');
        }
    }
}
