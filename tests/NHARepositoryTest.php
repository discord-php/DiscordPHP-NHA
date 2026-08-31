<?php

declare(strict_types=1);

use NHA\NHA;
use NHA\Repository\DiscoveryRepository;
use NHA\Repository\WorldRepository;
use React\Promise\PromiseInterface;
use Discord\Factory\Factory;

use function React\Promise\resolve;

class NHARepositoryTest extends NHAUnitTestCase
{
    protected function createMockNha(): NHA
    {
        // We need to mock NHA so that it doesn't try to connect to a real gateway during unit tests,
        // but we must ensure that when its methods are called, they return what we expect.
        // Since the repositories use getNhaHttpClient() and getFactory(), we must mock those.

        $nha = $this->getMockBuilder(NHA::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getNhaHttpClient', 'getFactory'])
            ->getMock();

        $http = $this->getMockBuilder(\NHA\Http\Http::class)
            ->disableOriginalConstructor()
            ->getMock();

        $nha->method('getNhaHttpClient')->willReturn($http);

        /** @var Factory|\PHPUnit\Framework\MockObject\MockObject $factory */
        $factory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $nha->method('getFactory')->willReturn($factory);

        return $nha;
    }


    public function testGetWorld(): void
    {
        $nha = $this->createMockNha();

        $http = $this->getMockBuilder(\NHA\Http\Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $nha->method('getNhaHttpClient')->willReturn($http);

        $worldRepo = new WorldRepository($nha);

        $http->expects($this->once())
            ->method('get')
            ->with(\NHA\Http\Endpoint::WORLD)
            ->willReturn(resolve(['world_data' => true]));

        $promise = $worldRepo->getWorld();
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($nha, $resolve) => $promise->then($resolve));

        $this->assertInstanceOf(\NHA\Parts\World::class, $result);
    }

    public function testGetDeposits(): void
    {
        $nha = $this->createMockNha();

        $http = $this->getMockBuilder(\NHA\Http\Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $nha->method('getNhaHttpClient')->willReturn($http);

        $discoveryRepo = new DiscoveryRepository($nha);

        $http->expects($this->once())
            ->method('get')
            ->with($this->callback(fn($endpoint) => str_contains((string) $endpoint, 'x=1.0') && str_contains((string) $endpoint, 'y=2.0')))
            ->willReturn(resolve([['resource' => 'metal']]));

        $promise = $discoveryRepo->getDeposits(['x' => 1.0, 'y' => 2.0, 'limit' => 50]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($nha, $resolve) => $promise->then($resolve));

        $this->assertSame([['resource' => 'metal']], $result);
    }

}
