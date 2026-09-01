<?php

declare(strict_types=1);

use NHA\NHA;
use NHA\Http\Http;
use NHA\Http\Endpoint;

use function React\Promise\resolve;

class NHARepositoryTest extends NHAUnitTestCase
{
    protected function getNha(): NHA
    {
        return getMockNha();
    }

    /**
     * Replaces the NHA transport with a resolved fixture response.
     *
     * @param mixed $response
     */
    private function withHttpResponse($response): NHA
    {
        $http = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $http->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($endpoint) use ($response) {
                $this->assertTrue(
                    is_string($endpoint) || $endpoint instanceof Endpoint,
                    'Repository requests must use a route string or a bound endpoint.',
                );

                return resolve($response);
            });

        $nha = $this->getNha();
        $property = new \ReflectionProperty(NHA::class, 'nha_http');
        $property->setValue($nha, $http);

        return $nha;
    }

    public function testGetWorld()
    {
        $nha = $this->withHttpResponse([
            'tick' => 1,
            'tick_seconds' => 5,
            'entities' => [],
            'last_state_hash' => 'test-state',
            'visitors' => 0,
        ]);

        $world = null;
        $nha->world->getWorld()->then(function ($resolvedWorld) use (&$world) {
            $world = $resolvedWorld;
        });

        $this->assertInstanceOf(\NHA\Parts\World::class, $world);
    }

    public function testGetDeposits()
    {
        $nha = $this->withHttpResponse((object) [
            'deposits' => [
                (object) [
                    'id' => 'deposit-1',
                    'resource' => 'metal',
                    'amount' => 10,
                    'x' => 1,
                    'y' => 1,
                    'dist' => 0,
                ],
            ],
        ]);

        $deposits = null;
        $nha->deposits->getDeposits(['x' => 1, 'y' => 1])->then(function ($resolvedDeposits) use (&$deposits) {
            $deposits = $resolvedDeposits;
        });

        $this->assertIsArray($deposits);
        $this->assertCount(1, $deposits);
        $this->assertInstanceOf(\NHA\Parts\Deposits::class, $deposits[0]);
    }
}
