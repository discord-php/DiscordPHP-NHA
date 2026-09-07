<?php

declare(strict_types=1);

use Discord\Http\Exceptions\NotFoundException;
use Discord\Http\Exceptions\RequestFailedException;
use NHA\Http\Http;
use NHA\NHA;

use function React\Promise\reject;
use function React\Promise\resolve;

class IntentRepositoryTest extends NHAUnitTestCase
{
    private function nhaWithIntentGet(callable $get): NHA
    {
        $http = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $http->method('get')->willReturnCallback($get);

        $nha = getMockNha();
        (new \ReflectionProperty(NHA::class, 'nha_http'))->setValue($nha, $http);

        return $nha;
    }

    /**
     * @covers \NHA\Repository\IntentRepository
     */
    public function testGetIntentStatusHydratesTheOutcome(): void
    {
        $nha = $this->nhaWithIntentGet(static fn() => resolve([
            'id' => 42,
            'agent' => 7,
            'verb' => 'combine',
            'status' => 'applied',
            'result' => 'herb+wood -> poultice',
        ]));

        $status = null;
        $nha->intents->getIntentStatus(42)->then(function ($s) use (&$status) {
            $status = $s;
        });

        $this->assertSame('applied', $status->status);
        $this->assertSame('herb+wood -> poultice', $status->result);
    }

    /**
     * @covers \NHA\Repository\IntentRepository
     * @dataProvider agedOutResponses
     */
    public function testGetIntentStatusReportsGoneWhenTheIdHasAgedOut(\Throwable $rejection): void
    {
        $nha = $this->nhaWithIntentGet(static fn() => reject($rejection));

        $status = null;
        $rejected = false;
        $nha->intents->getIntentStatus(3168877)->then(
            function ($s) use (&$status) {
                $status = $s;
            },
            function () use (&$rejected) {
                $rejected = true;
            },
        );

        $this->assertFalse($rejected, 'a retention-window miss resolves, it does not reject');
        $this->assertSame('gone', $status->status);
        $this->assertSame(3168877, $status->id);
    }

    /** @return array<string, array{\Throwable}> */
    public static function agedOutResponses(): array
    {
        return [
            '410 Gone' => [new RequestFailedException('Gone - {"detail": "intent expired"}', 410)],
            '404 Not Found' => [new NotFoundException('Not Found', 404)],
        ];
    }

    /**
     * @covers \NHA\Repository\IntentRepository
     */
    public function testGetIntentStatusStillRejectsOnATransientFailure(): void
    {
        $nha = $this->nhaWithIntentGet(static fn() => reject(new RequestFailedException('Internal Server Error', 500)));

        $status = null;
        $error = null;
        $nha->intents->getIntentStatus(42)->then(
            function ($s) use (&$status) {
                $status = $s;
            },
            function (\Throwable $e) use (&$error) {
                $error = $e;
            },
        );

        $this->assertNull($status, 'a 5xx is not swallowed as "gone"');
        $this->assertInstanceOf(RequestFailedException::class, $error);
    }
}
