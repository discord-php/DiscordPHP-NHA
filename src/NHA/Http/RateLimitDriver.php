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
use Discord\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * A {@see DriverInterface} decorator that makes DiscordPHP's rate-limit
 * machinery usable against the NHA world API, which (unlike Discord) sends **no**
 * `X-RateLimit-*` headers on success and answers an over-limit request with a
 * bare `429` — often just a `Retry-After`, sometimes nothing — instead of
 * Discord's `{global, retry_after}` contract.
 *
 * Without this, {@see \Discord\Http\HttpTrait} sees a 429 it cannot parse
 * ("does not contain global rate-limit value"), drops the request with no
 * retry, and the bucket immediately fires the next one — a 429 storm.
 *
 * This decorator does two things:
 *
 *  1. **Paces** every outbound request by at least `minInterval` seconds, since
 *     the server never tells the bucket a budget to respect.
 *  2. **Normalises** a 429 so `HttpTrait` treats it as a per-bucket limit
 *     (`X-RateLimit-Global: false`) with a real `Retry-After` — which makes the
 *     bucket re-queue the request and retry it after the back-off, exactly as it
 *     would for a Discord 429.
 *
 * @since 0.2.0
 */
final class RateLimitDriver implements DriverInterface
{
    /** Earliest `microtime(true)` at which the next request may be dispatched. */
    private float $nextSlot = 0.0;

    /**
     * @param DriverInterface $inner              The real driver (Guzzle/React).
     * @param float           $minInterval        Minimum seconds between any two NHA requests.
     * @param float           $fallbackRetryAfter Back-off used when a 429 carries no `Retry-After`.
     */
    public function __construct(
        private readonly DriverInterface $inner,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly float $minInterval = 0.25,
        private readonly float $fallbackRetryAfter = 2.0,
    ) {}

    public function runRequest(Request $request): PromiseInterface
    {
        $deferred = new Deferred();

        $now = microtime(true);
        $wait = max(0.0, $this->nextSlot - $now);
        $this->nextSlot = max($now, $this->nextSlot) + $this->minInterval;

        $fire = function () use ($request, $deferred): void {
            $this->inner->runRequest($request)->then(
                function (ResponseInterface $response) use ($request, $deferred): void {
                    $deferred->resolve(
                        $response->getStatusCode() === 429
                            ? $this->normalise($response, $request)
                            : $response,
                    );
                },
                static fn(\Throwable $e) => $deferred->reject($e),
            );
        };

        $wait > 0.0 ? $this->loop->addTimer($wait, $fire) : $fire();

        return $deferred->promise();
    }

    /**
     * Rewrites an NHA 429 into the shape {@see \Discord\Http\HttpTrait} expects,
     * and pushes the local dispatch schedule past the back-off so nothing else
     * races the limit either.
     */
    private function normalise(ResponseInterface $response, Request $request): ResponseInterface
    {
        if (! $response->hasHeader('Retry-After')) {
            $response = $response->withHeader('Retry-After', (string) $this->fallbackRetryAfter);
        }

        // `HttpTrait` reads a body `global` field first, so injecting the header
        // only supplies the value when the server (or a future NHA release)
        // hasn't. `false` => the bucket re-queues and retries after `Retry-After`
        // rather than freezing the whole client.
        if (! $response->hasHeader('X-RateLimit-Global')) {
            $response = $response->withHeader('X-RateLimit-Global', 'false');
        }

        $retryAfter = max(0.1, (float) $response->getHeaderLine('Retry-After'));
        $this->nextSlot = max($this->nextSlot, microtime(true) + $retryAfter);

        $this->logger->warning(sprintf(
            'NHA rate-limited: %s %s -> 429; backing off %.1fs (bucket will retry).',
            strtoupper($request->getMethod()),
            (string) $request->getUrl(),
            $retryAfter,
        ));

        return $response;
    }
}
