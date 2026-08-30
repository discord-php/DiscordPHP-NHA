<?php

declare(strict_types=1);

namespace Tests\NHA;

use DiscordTestCase;
use NHA\NHARepository;
use NHA\NHA;
use NHA\Http\Http;
use NHA\Http\Endpoint;
use NHA\Repositories\AgentRepository;
use NHA\Repositories\DiscoveryRepository;
use NHA\Repositories\WorldRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class NHARepositoryTest extends DiscordTestCase
{
    protected function createMockNha(): NHA
    {
        $nha = $this->getMockBuilder(NHA::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch', 'getNhaHttpClient', 'getWorldRepo', 'getEconomyRepo', 'getSocialRepo', 'getAgentRepo', 'getDiscoveryRepo', 'getIntentRepo'])
            ->getMock();

        return $nha;
    }


    public function testGetWorld(): void
    {
        $nha = $this->createMockNha();
        $worldRepo = $this->getMockBuilder(WorldRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorld'])
            ->getMock();

        $nha->method('getWorldRepo')->willReturn($worldRepo);
        $worldRepo->expects($this->once())
            ->method('getWorld')
            ->willReturn(resolve(['world_data' => true]));

        $repo = new NHARepository($nha);

        $promise = $repo->getWorld();
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame(['world_data' => true], $result);
    }

    public function testGetAgentInfo(): void
    {
        $nha = $this->createMockNha();
        $agentRepo = $this->getMockBuilder(AgentRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAgentInfo'])
            ->getMock();

        $nha->method('getAgentRepo')->willReturn($agentRepo);
        $agentRepo->expects($this->once())
            ->method('getAgentInfo')
            ->with(123)
            ->willReturn(resolve(['agent_id' => 123]));

        $repo = new NHARepository($nha);

        $promise = $repo->getAgentInfo(123);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame(['agent_id' => 123], $result);
    }

    public function testGetDeposits(): void
    {
        $nha = $this->createMockNha();
        $discoveryRepo = $this->getMockBuilder(DiscoveryRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDeposits'])
            ->getMock();

        $nha->method('getDiscoveryRepo')->willReturn($discoveryRepo);
        $discoveryRepo->expects($this->once())
            ->method('getDeposits')
            ->with(1.0, 2.0, 50)
            ->willReturn(resolve([['resource' => 'metal']]));

        $repo = new NHARepository($nha);

        $promise = $repo->getDeposits(1.0, 2.0, 50);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($discord, $resolve) => $promise->then($resolve));
        $this->assertSame([['resource' => 'metal']], $result);
    }
}
