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
        if (!isset($nha->deposits)) {
            $this->fail('NHA deposits repository is not set (is null).');
        }

        $result = wait(function (NHA $nha, $resolve) {
            $nha->deposits->getDeposits(['x' => 1.0, 'y' => 2.0, 'limit' => 50])
                ->then(
                    function ($result) use ($resolve) {
                        $resolve($result);
                    },
                    function ($error) use ($resolve) {
                        $resolve($error);
                    },
                );
        }, 10);

        if ($result instanceof \Throwable) {
            $this->fail('testGetDeposits failed with error: ' . $result->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

}
