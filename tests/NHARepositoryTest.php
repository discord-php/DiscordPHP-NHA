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
        $world = wait(function (NHA $nha, $resolve) {
            $nha->world->getWorld()
                ->then(function ($world) use ($resolve) {
                    $resolve($world);
                });
        }, 10);

        $this->assertInstanceOf(\NHA\Parts\World::class, $world);
    }

    public function testGetDeposits()
    {
        $result = wait(function (NHA $nha, $resolve) {
            $nha->discovery->getDeposits(['x' => 1.0, 'y' => 2.0, 'limit' => 50])
                ->then(function ($result) use ($resolve) {
                    $resolve($result);
                });
        }, 10);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

}
