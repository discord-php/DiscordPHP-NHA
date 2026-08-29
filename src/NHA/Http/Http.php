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

use Discord\Http\DriverInterface;
use Discord\Http\Endpoint;
use Discord\Http\HttpInterface;
use Discord\Http\HttpTrait;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SplQueue;

/**
 * HTTP client for the NHA (https://nha.recluse.lol) agent sandbox.
 *
 * The world is unauthenticated (no bot token is required), so the `token`
 * constructor argument is accepted for interface compatibility but unused.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class Http implements HttpInterface
{
    use HttpTrait;

    /**
     * DiscordPHP-NHA version.
     *
     * @var string
     */
    public const VERSION = 'v1.0.0';

    /**
     * NHA world base URL.
     *
     * @var string
     */
    public const BASE_URL = 'https://nha.recluse.lol';

    /**
     * Authentication token (unused, the NHA API is unauthenticated).
     *
     * @var string
     */
    private $token;

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
     * @var SplQueue
     */
    protected $queue;

    /**
     * Request queue used for unbound (fire-and-forget) endpoints.
     *
     * @var SplQueue
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
     * @param string               $token  Unused, kept for interface compatibility.
     * @param LoopInterface        $loop
     * @param LoggerInterface      $logger
     * @param DriverInterface|null $driver
     */
    public function __construct(string $token, LoopInterface $loop, LoggerInterface $logger, ?DriverInterface $driver = null)
    {
        $this->token = $token;
        $this->loop = $loop;
        $this->logger = $logger;
        $this->driver = $driver;
        $this->queue = new SplQueue();
        $this->unboundQueue = new SplQueue();
    }

    /**
     * Builds and queues a request. The NHA API needs no `Authorization`
     * header, so we skip the header set that `HttpTrait::queueRequest()`
     * assumes for Discord requests.
     *
     * @param string   $method
     * @param Endpoint $url
     * @param mixed    $content
     * @param array    $headers
     *
     * @return PromiseInterface
     */
    public function queueRequest(string $method, Endpoint $url, $content, array $headers = []): PromiseInterface
    {
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
                $this->guessContent($content)
            );
        }

        $headers = array_merge($baseHeaders, $headers);

        $request = new Request($deferred, $method, $url, $content ?? '', $headers);
        $this->sortIntoBucket($request);

        return $deferred->promise();
    }

    /**
     * @inheritDoc
     */
    public function getUserAgent(): string
    {
        return 'DiscordPHP-NHA (https://github.com/discord-php/DiscordPHP-NHA, '.self::VERSION.')';
    }
}
