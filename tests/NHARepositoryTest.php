<?php

declare(strict_types=1);

use NHA\NHA;

use function React\Promise\resolve;

class NHARepositoryTest extends NHAUnitTestCase
{
    protected function getNha(): NHA
    {
        return NHASingleton::get();
    }

    public function testGetWorld()
    {
        $nha = $this->getNha();

        $world = wait(function (NHA $nha, $resolve) {
            $nha->world->getWorld()
                ->then(
                    function ($world) use ($resolve) {
                        $resolve($world);
                    },
                    function ($error) use ($resolve) {
                        $resolve($error);
                    },
                );
        }, 10);

        if ($world instanceof \Throwable) {
            $this->fail('testGetWorld failed with error: ' . $world->getMessage() . PHP_EOL . $world->getTraceAsString());
        }

        $this->assertInstanceOf(\NHA\Parts\World::class, $world);
    }

    public function testGetDeposits()
    {
        $nha = $this->getNha();

        $deposits = wait(function (NHA $nha, $resolve) {
            $nha->deposits->getDeposits(['x' => 1, 'y' => 1])
                ->then(
                    function ($deposits) use ($resolve) {
                        $resolve($deposits);
                    },
                    function ($error) use ($resolve) {
                        $resolve($error);
                    },
                );
        }, 10);

        if ($deposits instanceof \Throwable) {
            $this->fail('testGetDeposits failed with error: ' . $deposits->getMessage() . PHP_EOL . $deposits->getTraceAsString());
        }

        $this->assertIsArray($deposits);
    }
}
