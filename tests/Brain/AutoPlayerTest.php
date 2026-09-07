<?php

declare(strict_types=1);

use NHA\Brain\AgentBrain;
use NHA\Brain\AutoPlayer;
use NHA\Brain\OllamaClient;
use NHA\Http\Http;
use NHA\NHA;
use NHA\StateStore;

use function React\Promise\resolve;

class AutoPlayerTest extends NHAUnitTestCase
{
    /** @var list<array{0:string,1:mixed}> */
    private array $posts = [];

    private string $statePath;

    protected function setUp(): void
    {
        $this->statePath = sys_get_temp_dir() . '/nha-autoplay-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @rmdir(dirname($this->statePath));
    }

    private function nhaWith(array $observePayload, array $intentReply = ['queued_intent' => 555, 'tick' => 42]): NHA
    {
        $http = $this->getMockBuilder(Http::class)->disableOriginalConstructor()
            ->onlyMethods(['get', 'post'])->getMock();
        $http->method('get')->willReturnCallback(fn($e) => resolve($observePayload));
        $http->method('post')->willReturnCallback(function ($e, $c = null) use ($intentReply) {
            // Normalise through the wire encoding: `args` is cast to an object
            // (so an empty set serialises as `{}`), decode it back for asserts.
            $this->posts[] = [(string) $e, json_decode(json_encode($c), true)];

            return resolve($intentReply);
        });

        $nha = getMockNha();
        (new \ReflectionProperty(NHA::class, 'nha_http'))->setValue($nha, $http);

        return $nha;
    }

    private function brainReturning(string $modelJson): AgentBrain
    {
        return new AgentBrain(new OllamaClient('http://x', 'm', fn() => resolve(
            json_encode(['message' => ['content' => $modelJson], 'done' => true]),
        )));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepObservesDecidesAndQueuesTheIntent(): void
    {
        $nha = $this->nhaWith(['tick' => 42, 'position' => [30, 118], 'downed_until' => 0]);
        $state = new StateStore($this->statePath);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":2},"reason":"wood here"}'), $state);

        $line = null;
        $player->step(142287, 'tok')->then(function ($l) use (&$line) {
            $line = $l;
        });

        // Intent was POSTed with the model's verb + args + token.
        $this->assertCount(1, $this->posts);
        [$endpoint, $body] = $this->posts[0];
        $this->assertStringContainsString('intent', $endpoint);
        $this->assertSame('mine', $body['verb']);
        $this->assertSame(['n' => 2], $body['args']);
        $this->assertSame('tok', $body['token']);

        $this->assertStringContainsString('mine', $line);
        $this->assertStringContainsString('#555', $line);

        // Decision was persisted.
        $decision = $state->getLastDecision(142287);
        $this->assertSame('mine', $decision['verb']);
        $this->assertSame(555, $decision['queued_intent']);
        $this->assertSame(42, $decision['tick']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepSkipsWhenDowned(): void
    {
        $nha = $this->nhaWith(['tick' => 42, 'downed_until' => 99, 'position' => [1, 1]]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{}}'), new StateStore($this->statePath));

        $line = null;
        $player->step(1, 'tok')->then(function ($l) use (&$line) {
            $line = $l;
        });

        $this->assertSame([], $this->posts, 'no intent while downed');
        $this->assertStringContainsString('downed', strtolower($line));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepDoesNothingWhenBrainWaits(): void
    {
        $nha = $this->nhaWith(['tick' => 42, 'downed_until' => 0]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"wait"}'), new StateStore($this->statePath));

        $line = null;
        $player->step(1, 'tok')->then(function ($l) use (&$line) {
            $line = $l;
        });

        $this->assertSame([], $this->posts);
        $this->assertStringContainsString('wait', strtolower($line));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepFallsBackToClientTokenWhenNoneGiven(): void
    {
        $nha = $this->nhaWith(['tick' => 1, 'downed_until' => 0]);
        $nha->setAgentToken('client-tok');
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"gather","args":{"n":1}}'), new StateStore($this->statePath));

        $player->step(7);

        $this->assertSame('client-tok', $this->posts[0][1]['token']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testStepWithALeaseSkipsWhenAnotherDriverHoldsIt(): void
    {
        $state = new StateStore($this->statePath);
        $state->acquireAutoplayLease('autoplay.php:999', 15); // another process is already driving

        $nha = $this->nhaWith(['tick' => 1, 'downed_until' => 0]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"gather","args":{"n":1}}'), $state);

        $line = null;
        $player->step(7, 'tok', 'bot.php:1')->then(function ($l) use (&$line) {
            $line = $l;
        });

        $this->assertSame([], $this->posts, 'no intent submitted while another driver holds the lease');
        $this->assertStringContainsString('skipped', strtolower((string) $line));
        $this->assertStringContainsString('autoplay.php:999', (string) $line);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testStepWithALeaseRunsWhenItCanTakeTheLease(): void
    {
        $nha = $this->nhaWith(['tick' => 1, 'downed_until' => 0]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"gather","args":{"n":1}}'), new StateStore($this->statePath));

        $player->step(7, 'tok', 'bot.php:1');

        $this->assertNotSame([], $this->posts, 'the turn runs and queues an intent');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testAOneOffStepIsNeverLeaseGated(): void
    {
        $state = new StateStore($this->statePath);
        $state->acquireAutoplayLease('autoplay.php:999', 15);

        $nha = $this->nhaWith(['tick' => 1, 'downed_until' => 0]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"gather","args":{"n":1}}'), $state);

        $player->step(7, 'tok'); // no lease arg -> `!nha think`

        $this->assertNotSame([], $this->posts, 'a manual one-off turn is not blocked by the loop lease');
    }
}
