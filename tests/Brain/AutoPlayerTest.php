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
     * @covers \NHA\Repository\IntentRepository
     */
    public function testStepForgetsLastIntentIdOnceItsOutcomeIsTerminal(): void
    {
        // The world no longer retains last turn's intent: getIntentStatus() maps
        // the 404/410 to a `gone` status, and the loop must drop the id so it is
        // not re-polled forever.
        $nha = $this->nhaWith(['tick' => 42, 'downed_until' => 0, 'position' => [1, 1], 'status' => 'gone', 'result' => '']);
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'combine', 'args' => [], 'reason' => 'x', 'queued_intent' => 3168877, 'tick' => 1]);

        // Brain waits, so nothing new is recorded over the cleared id.
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"wait"}'), $state);
        $player->step(142287, 'tok');

        $last = $state->getLastDecision(142287);
        $this->assertNull($last['queued_intent'], 'the aged-out id is forgotten');
        $this->assertSame('combine', $last['verb'], 'the decision itself is left intact');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\AgentBrain
     */
    public function testStepDropsACombineTheWorldAlreadyInventedAndFallsToInfrastructure(): void
    {
        // GET /rules (same mock as observe) reports glass+wood as a known recipe;
        // the agent holds the composite + metal a tower needs.
        $nha = $this->nhaWith([
            'tick' => 42, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['composite' => 3, 'metal' => 12],
            'dynamic' => [['sig' => 'glass,wood']],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"glass":1,"wood":1}}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertCount(1, $this->posts, 'one intent went out');
        $this->assertSame('construct', $this->posts[0][1]['verb'], 'the spent research combine became infrastructure work');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testStepDropsACombineThisAgentAlreadyTriedThisRun(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordCombineSignature(142287, 'iron+wood');

        // No build materials, but a real glut — the ladder sells it for credits.
        $nha = $this->nhaWith([
            'tick' => 7, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['metal' => 40],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"wood":1,"iron":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('sell', $this->posts[0][1]['verb'], 'a set already tried this run is not resubmitted');
        $this->assertSame('metal', $this->posts[0][1]['args']['resource']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\AgentBrain
     */
    public function testWhenResearchIsPayingASpentComboIsSwappedForAFreshPairNotInfrastructure(): void
    {
        // In space (no construct), and inventor points just rose (40 → 50) — the
        // fallback should pick a fresh untried pair rather than idling.
        $state = new StateStore($this->statePath);
        $state->noteInventorPoints(142287, 40); // baseline before this turn

        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'in_space' => true, 'altitude' => 60, 'inventor_points' => 50,
            'inventory' => ['water' => 18, 'wood' => 17, 'crystal' => 17],
            'dynamic' => [['sig' => 'glass,wood']],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"glass":1,"wood":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('combine', $this->posts[0][1]['verb']);
        $sent = array_keys($this->posts[0][1]['args']['ingredients']);
        sort($sent);
        $this->assertNotSame(['glass', 'wood'], $sent, 'the spent pair was replaced with a fresh one');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\AgentBrain
     */
    public function testFallbackLandsWhenStuckOffTheGroundWithNothingToDo(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordCombineSignature(142287, 'glass+lens');

        // In orbit, points stalled, no asteroid, no build materials, nothing to
        // sell — the ladder should send it back to the ground, not skip.
        $nha = $this->nhaWith([
            'tick' => 9, 'downed_until' => 0, 'position' => [32, 114],
            'in_space' => true, 'altitude' => 16, 'inventor_points' => 72,
            'inventory' => ['salt' => 8, 'lens' => 4],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"lens":1,"glass":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('land', $this->posts[0][1]['verb']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepBreaksTheRideBounceLoop(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'ride', 'args' => [], 'reason' => '', 'queued_intent' => null, 'tick' => 1]);

        $nha = $this->nhaWith([
            'tick' => 9, 'downed_until' => 0, 'position' => [32, 114],
            'in_space' => true, 'altitude' => 30,
            'inventory' => ['salt' => 20],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"ride","args":{}}'), $state);

        $player->step(142287, 'tok');

        $this->assertNotSame('ride', $this->posts[0][1]['verb'], 'a second ride straight after riding is swapped out');
        $this->assertSame('land', $this->posts[0][1]['verb']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepAllowsAProductionCombineEvenWhenWorldKnown(): void
    {
        // aluminium+carbon → composite is a production recipe: known, but you
        // re-craft it every time you want to build, so it is never blocked.
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['aluminium' => 4, 'carbon' => 4],
            'dynamic' => [['sig' => 'aluminium,carbon']],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"aluminium":1,"carbon":1}}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertSame('combine', $this->posts[0][1]['verb'], 'a production recipe is not treated as spent research');
        $this->assertSame(['aluminium' => 1, 'carbon' => 1], $this->posts[0][1]['args']['ingredients']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testStepRecordsEveryCombineSignatureItSubmits(): void
    {
        $state = new StateStore($this->statePath);
        $nha = $this->nhaWith(['tick' => 1, 'downed_until' => 0, 'position' => [1, 1], 'inventory' => ['herb' => 2, 'salt' => 2]]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"salt":1,"herb":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('combine', $this->posts[0][1]['verb'], 'a fresh set still goes through');
        $this->assertSame(['herb+salt'], $state->getTriedCombineSignatures(142287));
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
