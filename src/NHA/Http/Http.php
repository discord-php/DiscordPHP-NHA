<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace NHA\Http;

use Discord\Http\Bucket;
use Discord\Http\Http as DiscordHttp;
use Discord\Http\RateLimit;
use Discord\Http\DriverInterface;
use Discord\Http\Endpoint;
use Discord\Http\HttpInterface;
use Discord\Http\HttpTrait;
use NHA\Http\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Psr\Log\LogLevel;

/**
 * HTTP client for the NHA agent sandbox, built the same way DiscordPHP talks
 * to `discord.com` (rate-limit buckets, driver, retry) but pointed at the NHA
 * world API. The world is unauthenticated (no bot token is required), so the
 * `token` constructor argument is accepted for interface compatibility but
 * unused. A 422 response is surfaced as {@see ValidationException}.
 *
 * @see \Discord\Http\Http The DiscordPHP transport this extends
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class Http extends DiscordHttp implements HttpInterface
{
    use HttpTrait;

    /**
     * DiscordPHP-NHA version. The major tracks the NHA world API version
     * (`openapi.json` `info.version`); minor/patch follow SemVer for this
     * library. Sent in the `User-Agent`.
     *
     * @var string
     */
    public const VERSION = '3.1.18';

    /**
     * Default NHA world base URL. Override per client via the constructor
     * (`nha_base_url` option / `NHA_BASE_URL` env), e.g. to point at a local
     * instance; the constant stays the fallback.
     *
     * @var string
     */
    public const BASE_URL = 'https://nha.recluse.lol';

    /**
     * Authentication token (unused, the NHA API is unauthenticated).
     *
     * @var string
     */
    protected $token;

    /**
     * The base URL every {@see Request} built here is sent against.
     *
     * @var string
     */
    protected string $baseUrl = self::BASE_URL;

    /**
     * Logger for HTTP requests.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * HTTP driver.
     *
     * @var DriverInterface
     */
    protected $driver;

    /**
     * ReactPHP event loop.
     *
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Array of request buckets.
     *
     * @var Bucket[]
     */
    protected $buckets = [];

    /**
     * The current rate-limit.
     *
     * @var RateLimit
     */
    protected $rateLimit;

    /**
     * Timer that resets the current global rate-limit.
     *
     * @var TimerInterface
     */
    protected $rateLimitReset;

    /**
     * Request queue to prevent API overload.
     *
     * @var \SplQueue
     */
    protected $queue;

    /**
     * Request queue used for unbound (fire-and-forget) endpoints.
     *
     * @var \SplQueue
     */
    protected $unboundQueue;

    /**
     * Number of requests that are waiting for a response.
     *
     * @var int
     */
    protected $waiting = 0;

    /**
     * Whether react/promise v3 is used, if false, using v2.
     *
     * @var bool
     */
    protected $promiseV3 = true;

    /**
     * Http wrapper constructor.
     *
     * @param string               $token   Unused, kept for interface compatibility.
     * @param LoopInterface        $loop
     * @param LoggerInterface      $logger
     * @param DriverInterface|null $driver
     * @param string               $baseUrl World base URL; empty falls back to {@see self::BASE_URL}.
     */
    public function __construct(string $token, LoopInterface $loop, LoggerInterface $logger, ?DriverInterface $driver = null, string $baseUrl = self::BASE_URL)
    {
        $this->token = $token;
        $this->loop = $loop;
        $this->logger = $logger;
        $this->driver = $driver;
        $this->baseUrl = rtrim($baseUrl, '/') ?: self::BASE_URL;
        $this->queue = new \SplQueue();
        $this->unboundQueue = new \SplQueue();
    }

    /**
     * The base URL requests from this client are sent against.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @inheritDoc
     */
    public function queueRequest(string $method, Endpoint $url, $content, array $headers = []): PromiseInterface
    {
        $this->logger->debug('NHA HTTP Request: ' . strtoupper($method) . ' ' . (string) $url);

        $deferred = new Deferred();

        if (is_null($this->driver)) {
            $deferred->reject(new \Exception('HTTP driver is missing.'));

            return $deferred->promise();
        }

        $baseHeaders = [
            'User-Agent' => $this->getUserAgent(),
        ];

        if (! is_null($content) && ! isset($headers['Content-Type'])) {
            $baseHeaders = array_merge(
                $baseHeaders,
                $this->guessContent($content),
            );
        }

        $headers = array_merge($baseHeaders, $headers);

        $request = new Request($deferred, $method, $url, $content ?? '', $headers);
        $request->setBaseUrl($this->baseUrl);
        $this->sortIntoBucket($request);

        return $deferred->promise();
    }

    /**
     * @inheritDoc
     */
    public function getUserAgent(): string
    {
        return 'DiscordPHP-NHA (https://github.com/discord-php/DiscordPHP-NHA, ' . self::VERSION . ')';
    }

    /**
     * A 422 means the NHA API rejected the request body/query as malformed or
     * schema-mismatched — this is a bug in the request we sent, not a transient
     * failure, so it is logged loudly and surfaced as a {@see ValidationException}
     * rather than the generic `RequestFailedException`.
     *
     * @inheritDoc
     */
    public function handleError(ResponseInterface $response): \Throwable
    {
        if ($response->getStatusCode() === 422) {
            // FastAPI 422 bodies echo the offending request back under `input`,
            // which includes the agent token — scrub it before it reaches a log
            // sink or an exception message.
            $body = self::redactTokens((string) $response->getBody());
            $this->logger->error('NHA API rejected request as unprocessable (422)', ['body' => $body]);

            return new ValidationException("NHA API returned 422 Unprocessable Entity: {$body}");
        }

        return parent::handleError($response);
    }

    /**
     * Replaces the value of any `token` / `nha_token` JSON field with `***` so a
     * response body can be logged or surfaced without leaking a live credential.
     */
    private static function redactTokens(string $body): string
    {
        return preg_replace('/("(?:nha_)?token"\s*:\s*)"[^"]*"/i', '$1"***"', $body) ?? $body;
    }
}
