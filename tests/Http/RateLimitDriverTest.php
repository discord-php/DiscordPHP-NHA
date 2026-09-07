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

use Discord\Http\DriverInterface;
use Discord\Http\Endpoint;
use Discord\Http\Request;
use NHA\Http\RateLimitDriver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class RateLimitDriverTest extends TestCase
{
    /** @var list<float> delays passed to the fake loop's addTimer, in call order */
    private array $timerDelays = [];

    private function loop(): object
    {
        $delays = &$this->timerDelays;

        return new class ($delays) implements \React\EventLoop\LoopInterface {
            /** @param list<float> $delays */
            public function __construct(private array &$delays) {}

            public function addTimer($interval, $callback): TimerInterface
            {
                $this->delays[] = (float) $interval;
                $callback(); // fire immediately so the test stays synchronous

                return new class implements TimerInterface {
                    public function getInterval(): float
                    {
                        return 0.0;
                    }

                    public function getCallback(): callable
                    {
                        return static fn() => null;
                    }

                    public function isPeriodic(): bool
                    {
                        return false;
                    }
                };
            }

            public function addPeriodicTimer($interval, $callback): TimerInterface
            {
                return $this->addTimer($interval, $callback);
            }

            public function cancelTimer(TimerInterface $timer): void {}

            public function futureTick($listener): void
            {
                $listener();
            }

            public function addSignal($signal, $listener): void {}

            public function removeSignal($signal, $listener): void {}

            public function addReadStream($stream, $listener): void {}

            public function addWriteStream($stream, $listener): void {}

            public function removeReadStream($stream): void {}

            public function removeWriteStream($stream): void {}

            public function run(): void {}

            public function stop(): void {}
        };
    }

    /**
     * @param PromiseInterface<ResponseInterface> $result
     */
    private function driverReturning(PromiseInterface $result): DriverInterface
    {
        return new class ($result) implements DriverInterface {
            public function __construct(private PromiseInterface $result) {}

            public function runRequest(Request $request): PromiseInterface
            {
                return $this->result;
            }
        };
    }

    private function request(string $method = 'GET'): Request
    {
        return new Request(new Deferred(), $method, new Endpoint('observe/1'), '', []);
    }

    private function execute(RateLimitDriver $driver): ?ResponseInterface
    {
        $out = null;
        $driver->runRequest($this->request())->then(function ($r) use (&$out) {
            $out = $r;
        }, function ($e) use (&$out) {
            $out = $e;
        });

        return $out instanceof ResponseInterface ? $out : null;
    }

    public function testHappyResponsePassesThroughUntouched(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(200, ['X-Foo' => 'bar'], '{}'))), $this->loop(), new NullLogger());

        $res = $this->execute($driver);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('bar', $res->getHeaderLine('X-Foo'));
        self::assertFalse($res->hasHeader('X-RateLimit-Global'));
    }

    public function testBare429GetsAFallbackRetryAfterAndAPerBucketGlobalFlag(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(429, [], ''))), $this->loop(), new NullLogger(), 0.25, 3.0);

        $res = $this->execute($driver);

        self::assertSame(429, $res->getStatusCode());
        self::assertSame('3', $res->getHeaderLine('Retry-After'));
        self::assertSame('false', $res->getHeaderLine('X-RateLimit-Global'), 'HttpTrait will treat it as a per-bucket limit and retry');
    }

    public function testExistingRetryAfterIsKept(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(429, ['Retry-After' => '7'], ''))), $this->loop(), new NullLogger());

        $res = $this->execute($driver);

        self::assertSame('7', $res->getHeaderLine('Retry-After'));
        self::assertSame('false', $res->getHeaderLine('X-RateLimit-Global'));
    }

    public function testAGenuineGlobalFlagIsNotOverwritten(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(429, ['Retry-After' => '2', 'X-RateLimit-Global' => 'true'], ''))), $this->loop(), new NullLogger());

        $res = $this->execute($driver);

        self::assertSame('true', $res->getHeaderLine('X-RateLimit-Global'));
    }

    public function testInnerRejectionPropagates(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(reject(new \RuntimeException('boom'))), $this->loop(), new NullLogger());

        $err = null;
        $driver->runRequest($this->request())->then(null, function ($e) use (&$err) {
            $err = $e;
        });

        self::assertInstanceOf(\RuntimeException::class, $err);
        self::assertSame('boom', $err->getMessage());
    }

    public function testRequestsAfterTheFirstArePaced(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(200, [], '{}'))), $this->loop(), new NullLogger(), 0.5);

        $this->execute($driver); // first goes out immediately (no timer)
        $this->execute($driver); // second is scheduled ~one interval behind

        self::assertCount(1, $this->timerDelays, 'only the second request needed pacing');
        self::assertEqualsWithDelta(0.5, $this->timerDelays[0], 0.05, 'paced by roughly the min interval');
    }

    public function testABurstIsSpacedOutCumulatively(): void
    {
        $driver = new RateLimitDriver($this->driverReturning(resolve(new Response(200, [], '{}'))), $this->loop(), new NullLogger(), 0.5);

        for ($i = 0; $i < 4; $i++) {
            $this->execute($driver);
        }

        // Requests 2..4 are pushed to ~0.5s, ~1.0s, ~1.5s behind now.
        self::assertCount(3, $this->timerDelays);
        self::assertEqualsWithDelta(0.5, $this->timerDelays[0], 0.05);
        self::assertEqualsWithDelta(1.5, end($this->timerDelays), 0.05);
    }
}
