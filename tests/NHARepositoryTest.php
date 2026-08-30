<?php

declare(strict_types=1);

namespace Tests\NHA;

use DiscordTestCase;
use NHA\NHARepository;
use NHA\NHA;
use NHA\Http\Http;
use NHA\Http\Endpoint;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

class NHARepositoryTest extends DiscordTestCase
{
    protected function createMockNha(): NHA
    {
        $nha = $this->getMockBuilder(NHA::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch', 'getNhaHttpClient'])
            ->getMock();

        return $nha;
    }

    public function testGetWorld(): void
    {
        $nha = $this->createMockNha();
        $repo = new NHARepository($nha);

        $nha->expects($this->once())
            ->method('fetch')
            ->with(Endpoint::WORLD)
            ->willReturn(resolve(['world_data' => true]));

        $promise = $repo->getWorld();
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame(['world_data' => true], $result);
    }

    public function testGetAgentInfo(): void
    {
        $nha = $this->createMockNha();
        $repo = new NHARepository($nha);

        $nha->expects($this->once())
            ->method('fetch')
            ->with($this->callback(function ($endpoint) {
                return str_contains((string)$endpoint, 'agent');
            }))
            ->willReturn(resolve(['agent_id' => 123]));

        $promise = $repo->getAgentInfo(123);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame(['agent_id' => 123], $result);
    }

    public function testGetDeposits(): void
    {
        $nha = $this->createMockNha();
        $http = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $nha->method('getNhaHttpClient')->willReturn($http);

        $repo = new NHARepository($nha);

        $http->expects($this->once())
            ->method('get')
            ->with(
                $this->callback(function ($endpoint) {
                    return str_contains((string)$endpoint, 'deposits');
                }),
                $this->callback(function ($args) {
                    return $args['x'] === 1.0 && $args['y'] === 2.0 && $args['radius'] === 50;
                })
            )
            ->willReturn(resolve([['resource' => 'metal']]));

        $promise = $repo->getDeposits(1.0, 2.0, 50);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame([['resource' => 'metal']], $result);
    }
}
