<?php

declare(strict_types=1);

use NHA\NHA;
use NHA\Repository\DiscoveryRepository;
use NHA\Repository\WorldRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class NHARepositoryTest extends NHAUnitTestCase
{
    protected function getNha(): NHA
    {
        return NHASingleton::get();
    }

    public function testGetWorld()
    {
        return wait(function (NHA $nha, $resolve) {
            return $nha->world->getWorld()
                ->then(function ($world) use ($resolve) {
                    $this->assertInstanceOf(\NHA\Parts\World::class, $world);
                    $resolve($world);
                });
        }, 10);
    }

    public function testGetDeposits(): void
    {
        $nha = $this->getNha();

        $http = $this->getMockBuilder(\NHA\Http\Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $mockNha = $this->getMockBuilder(NHA::class)
            ->setConstructorArgs([['token' => 'mock', 'loop' => $nha->getLoop(), 'logger' => $nha->getLogger()]])
            ->onlyMethods(['getNhaHttpClient'])
            ->getMock();

        $mockNha->method('getNhaHttpClient')->willReturn($http);

        $discoveryRepo = new DiscoveryRepository($mockNha);

        $http->expects($this->once())
            ->method('get')
            ->with($this->callback(fn($endpoint) => str_contains((string) $endpoint, 'x=1.0') && str_contains((string) $endpoint, 'y=2.0')))
            ->willReturn(resolve([['resource' => 'metal']]));

        $promise = $discoveryRepo->getDeposits(['x' => 1.0, 'y' => 2.0, 'limit' => 50]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $result = \wait(fn($resolve) => $promise->then($resolve));

        $this->assertSame([['resource' => 'metal']], $result);
    }

}
