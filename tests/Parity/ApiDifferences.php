<?php

declare(strict_types=1);

namespace CurlCffi\Tests\Parity;

/**
 * Every name in Python curl_cffi's public API that the PHP port does not
 * have, with the reason. ApiParityTest fails on any other missing name, and
 * on an entry here that PHP has gained (so it must be removed).
 *
 * Keys:
 *  - "Call(option)"     an option of a call in python_api.json "options"
 *  - "Class.member"     a member of a class in "classes"
 *  - "Class"            a whole class
 *  - "exception Name"   an exception class
 *  - "export name"      a name in curl_cffi's or curl_cffi.requests' __all__
 */
final class ApiDifferences
{
    private const THREADS = 'eventlet/gevent integration: PHP has no green threads';
    private const ASYNCIO = 'asyncio plumbing: AsyncSession runs its own CurlCffi\Async\Loop, see loop()';
    private const POOL = 'handle pool internals: the PHP pool is private';
    private const WS_QUEUES = 'tunes the background asyncio reader and writer tasks; '
        . 'AsyncWebSocket reads and writes when a recv()/send() promise is waiting instead';
    private const ASYNC_BODY = 'PHP has no async iterators: read AsyncSession responses with '
        . 'content, text, iterContent(), iterLines() and close()';
    private const STREAM_INTERNALS = "internals of Python's thread- and task-based streaming";
    private const COOKIE_PLUMBING = 'plumbing between the jar and libcurl; PHP uses forCurl() and updateFromCurlChanges()';
    private const TYPE_ALIAS = 'a type alias for type checkers; PHP documents these shapes in docblocks';
    private const WARNING_CLASS = 'a Python warning category; PHP warnings are E_USER_WARNING, see CurlCffi\Warnings';

    /** @var array<string, string> */
    public const NOT_PORTED = [
        'Session.__init__(thread)' => self::THREADS,
        'requests.request(thread)' => self::THREADS,
        'Session.__init__(use_thread_local_curl)' => 'one PHP request runs on one thread, so a session has one handle',
        'Session.executor' => self::THREADS,

        'AsyncSession.__init__(loop)' => self::ASYNCIO,
        'AsyncSession.__init__(async_curl)' => self::ASYNCIO,
        'AsyncSession.acurl' => self::ASYNCIO,
        'AsyncCurl' => 'asyncio glue around curl_multi; PHP has CurlCffi\Curl\CurlMulti and CurlCffi\Async\Loop',
        'export AsyncCurl' => 'asyncio glue around curl_multi; PHP has CurlCffi\Curl\CurlMulti and CurlCffi\Async\Loop',
        'AsyncSession.init_pool' => self::POOL,
        'AsyncSession.pop_curl' => self::POOL,
        'AsyncSession.push_curl' => self::POOL,
        'AsyncSession.release_curl' => self::POOL,

        'AsyncSession.ws_connect(recv_queue_size)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(send_queue_size)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(max_send_batch_size)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(coalesce_frames)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(recv_time_slice)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(send_time_slice)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(drain_on_error)' => self::WS_QUEUES,
        'AsyncSession.ws_connect(block_on_recv_queue_full)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(recv_queue_size)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(send_queue_size)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(max_send_batch_size)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(coalesce_frames)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(recv_time_slice)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(send_time_slice)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(drain_on_error)' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(block_on_recv_queue_full)' => self::WS_QUEUES,
        'AsyncWebSocket.drain_on_error' => self::WS_QUEUES,
        'AsyncWebSocket.__init__(session)' => 'takes the session\'s Loop instead: new AsyncWebSocket($session->loop(), $curl)',
        'AsyncWebSocket.session' => 'holds the Loop instead, see loop()',
        'AsyncWebSocket.close_event' => 'an asyncio.Event; wait on the Promise close() returns instead',

        'Response.aclose' => self::ASYNC_BODY,
        'Response.acontent' => self::ASYNC_BODY,
        'Response.aiter_content' => self::ASYNC_BODY,
        'Response.aiter_lines' => self::ASYNC_BODY,
        'Response.atext' => self::ASYNC_BODY,
        'Response.astream_task' => self::STREAM_INTERNALS,
        'Response.stream_task' => self::STREAM_INTERNALS,
        'Response.queue' => self::STREAM_INTERNALS,
        'Response.quit_now' => self::STREAM_INTERNALS,

        'Cookies.get_cookies_for_curl' => self::COOKIE_PLUMBING,
        'Cookies.update_cookies_from_curl' => self::COOKIE_PLUMBING,
        'Cookies.update_cookies_from_curl_changes' => self::COOKIE_PLUMBING,

        'exception RequestsWarning' => self::WARNING_CLASS,
        'exception FileModeWarning' => self::WARNING_CLASS,
        'exception RequestsDependencyWarning' => self::WARNING_CLASS,
        'export RequestsError' => 'a deprecated alias of RequestException kept for curl_cffi 0.5; use RequestException',

        'export BrowserTypeLiteral' => self::TYPE_ALIAS,
        'export CookieTypes' => self::TYPE_ALIAS,
        'export HeaderTypes' => self::TYPE_ALIAS,
        'export ProxySpec' => self::TYPE_ALIAS,
    ];
}
