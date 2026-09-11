<?php

declare(strict_types=1);

use NHA\Brain\AgentBrain;
use NHA\Brain\AutoPlayer;
use NHA\Brain\Ladder;
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
        // not re-polled forever. (clearQueuedIntent itself is covered in
        // StateStoreTest; here we just check the turn wires it up and does not
        // re-poll a stale id.)
        $polled = [];
        $http = $this->getMockBuilder(Http::class)->disableOriginalConstructor()->onlyMethods(['get', 'post'])->getMock();
        $http->method('get')->willReturnCallback(function ($e) use (&$polled) {
            $polled[] = (string) $e;

            return resolve(['tick' => 42, 'downed_until' => 0, 'position' => [1, 1], 'status' => 'gone']);
        });
        $http->method('post')->willReturnCallback(fn() => resolve(['queued_intent' => 999]));
        $nha = getMockNha();
        (new \ReflectionProperty(NHA::class, 'nha_http'))->setValue($nha, $http);

        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'combine', 'args' => [], 'reason' => 'x', 'queued_intent' => 3168877, 'tick' => 1]);

        (new AutoPlayer($nha, $this->brainReturning('{"verb":"wait"}'), $state))->step(142287, 'tok');
        $this->assertNull($state->getLastDecision(142287)['queued_intent'], 'the aged-out id is forgotten this turn');

        // Next turn must NOT poll intent/3168877 again.
        $polled = [];
        (new AutoPlayer($nha, $this->brainReturning('{"verb":"wait"}'), $state))->step(142287, 'tok');
        $this->assertEmpty(array_filter($polled, static fn(string $e): bool => str_contains($e, '3168877')), 'the stale id is never polled again');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\AgentBrain
     */
    public function testStepDropsACombineTheWorldAlreadyInventedAndFallsToInfrastructure(): void
    {
        // GET /rules (same mock as observe) reports glass+wood as a known recipe.
        // Broke, no ship, no research surplus — the spent combine is dropped and
        // the agent does real work toward the mission (sell to bank credits),
        // not a re-submit and not `wait`.
        $nha = $this->nhaWith([
            'tick' => 42, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['composite' => 3, 'metal' => 12],
            'dynamic' => [['sig' => 'glass,wood']],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"glass":1,"wood":1}}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertCount(1, $this->posts, 'one intent went out');
        $this->assertNotSame('combine', $this->posts[0][1]['verb'], 'a world-known set is not re-submitted');
        $this->assertContains($this->posts[0][1]['verb'], ['sell', 'buy', 'mine', 'chop', 'gather', 'build'], 'it does real work instead');
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
        // In space (no construct), points just rose (40 → 50), AND a genuine
        // material surplus (2 raws at 60+) — the fallback picks a fresh untried
        // pair rather than idling.
        $state = new StateStore($this->statePath);
        $state->noteInventorPoints(142287, 40); // baseline before this turn

        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'in_space' => true, 'altitude' => 60, 'inventor_points' => 50,
            'inventory' => ['water' => 70, 'wood' => 65, 'crystal' => 17],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
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
    public function testFallbackComesDownWhenStuckOffTheGroundWithNothingToDo(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordCombineSignature(142287, 'glass+lens');

        // In space, points stalled, no asteroid, no build materials, nothing to
        // sell, and NO vehicle — the ladder should send it back to the ground.
        // `land` needs a ship, so with none it rides the elevator down instead.
        $nha = $this->nhaWith([
            'tick' => 9, 'downed_until' => 0, 'position' => [32, 114],
            'in_space' => true, 'altitude' => 16, 'inventor_points' => 72,
            'inventory' => ['salt' => 8, 'lens' => 4],
            'elevators' => [['x' => 32, 'y' => 114, 'height' => 120]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"lens":1,"glass":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('ride', $this->posts[0][1]['verb']);
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
            'elevators' => [['x' => 32, 'y' => 114, 'height' => 120]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"ride","args":{}}'), $state);

        $player->step(142287, 'tok');

        // No ship in space is a dead end: the only sane move is down. `ride`
        // from the base cell toggles the elevator DOWN; the agent must never be
        // sent to `land` (rejected without a vehicle) or left idling in orbit.
        $this->assertContains($this->posts[0][1]['verb'], ['ride', 'wait']);
        $this->assertNotSame('land', $this->posts[0][1]['verb']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepDefersAFreshElevatorTripUntilLocalWorkIsDone(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'land', 'args' => [], 'reason' => '', 'queued_intent' => null, 'tick' => 40]);

        // Landed 4 turns ago, standing on an iron deposit, brain wants straight back up.
        $nha = $this->nhaWith([
            'tick' => 44, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => [],
            'nearby_deposits' => [['resource' => 'iron', 'dist' => 0, 'amount' => 20]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"launch","args":{}}'), $state);

        $player->step(142287, 'tok');

        $this->assertNotSame('launch', $this->posts[0][1]['verb'], 'leaving a spot worked only 4 turns is swapped for local work');
        $this->assertSame('mine', $this->posts[0][1]['verb']);
    }

    /**
     * An expansionist agent on the ground with no ship that the model steers
     * into another vanity spire (or a `ride` to an empty orbit) is swapped for
     * the gear-up move — buying a ship input here.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepKeepsAGroundedExpansionistGearingInsteadOfTowering(): void
    {
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            // armed + medicine → ranks expansionist; credits fund the gear-up buy;
            // composite + metal would normally trigger a tower.
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6, 'stimpack' => 1, 'credits' => 4000, 'composite' => 6, 'metal' => 20],
            'nearby_deposits' => [], 'elevators' => [['x' => 10, 'y' => 10]],
        ]);
        (new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"box","size":8,"height":40}}'), new StateStore($this->statePath)))
            ->step(142287, 'tok');

        $this->assertNotSame('construct', $this->posts[0][1]['verb'], 'the vanity spire is swapped for gearing the ship');
        $this->assertSame('buy', $this->posts[0][1]['verb']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testStepAllowsAnElevatorTripOnceTheDwellHasPassed(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'land', 'args' => [], 'reason' => '', 'queued_intent' => null, 'tick' => 1]);

        // Last transit was 19 turns ago — the agent has earned the trip. It is
        // flight-ready (a finalized orbital ship + a transfer-sized fuel
        // reserve), so `launch` is not swapped for gearing or fuel-stocking;
        // only the dwell gate is under test here.
        $nha = $this->nhaWith([
            'tick' => 20, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['cryo_fuel' => 95],
            'vehicles' => [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]],
            'nearby_deposits' => [['resource' => 'iron', 'dist' => 0, 'amount' => 20]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"launch","args":{}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('launch', $this->posts[0][1]['verb'], 'a long-dwelt agent may ride the elevator');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testAGuildRejectionIsRecordedDeadAndNeverResubmitted(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'combine', 'args' => ['ingredients' => ['glass' => 1, 'wood' => 1]], 'reason' => '', 'queued_intent' => 999, 'tick' => 1]);

        // Last turn's combine comes back rejected; the brain immediately tries it again.
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['wood' => 40],
            'status' => 'rejected',
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"glass":1,"wood":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertContains('glass+wood', $state->getDeadCombines(142287), 'the rejection was recorded');
        $this->assertSame('sell', $this->posts[0][1]['verb'], 'the dead set was not resubmitted');
    }

    /**
     * A production recipe (aluminium+carbon → composite) is known-good and
     * re-craftable; a stale `dead` entry — almost always from one turn the agent
     * was short an ingredient — must not block it. This is the wedge that stuck
     * the composite build.
     *
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testAProductionRecipeIsNeverBlockedByAStaleDeadEntry(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDeadCombine(142287, 'aluminium+carbon');

        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['aluminium' => 40, 'carbon' => 40],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"aluminium":1,"carbon":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('combine', $this->posts[0][1]['verb'], 'the production recipe still goes out');
    }

    /**
     * A combine rejected for lack of ingredients ("not enough carbon") is
     * transient, not proven-dead — recording it would permanently kill a recipe
     * over one bad turn.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testARejectionForInsufficientStockIsNotRecordedDead(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'combine', 'args' => ['ingredients' => ['crystal' => 1, 'lens' => 1]], 'reason' => '', 'queued_intent' => 999, 'tick' => 1]);

        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['wood' => 40],
            'status' => 'rejected', 'result' => 'not enough crystal to combine',
        ]);
        (new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":5}}'), $state))->step(142287, 'tok');

        $this->assertNotContains('crystal+lens', $state->getDeadCombines(142287), 'a stock shortage does not blacklist the set');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\Ladder::defensiveAction
     */
    public function testStepDefendsBeforeConsultingTheBrain(): void
    {
        // The brain would mine; a fresh attack alert overrides it.
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [40, 40], 'hp' => 22, 'hp_max' => 100,
            'inventory' => ['stimpack' => 1, 'iron' => 30],
            'alerts' => [['tick' => 495, 'kind' => 'attacked', 'by' => 9, 'dmg' => 78]],
            'nearby_agents' => [['id' => 9, 'x' => 41, 'y' => 40, 'dist' => 1]],
        ]);
        $line = null;
        (new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":5}}'), new StateStore($this->statePath)))
            ->step(142287, 'tok')->then(function ($l) use (&$line) {
                $line = $l;
            });

        $this->assertSame('heal', $this->posts[0][1]['verb'], 'defends instead of mining');
        $this->assertStringContainsString('🛡️', (string) $line);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsADominantAction(): void
    {
        $recent = array_fill(0, 8, ['verb' => 'chop', 'args' => ['n' => 1], 'tick' => 0]);

        $this->assertStringContainsString('repeating chop', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsAShortCycle(): void
    {
        $recent = [];
        for ($i = 0; $i < 3; $i++) {
            $recent[] = ['verb' => 'ride', 'args' => [], 'tick' => 0];
            $recent[] = ['verb' => 'move', 'args' => ['x' => 33, 'y' => 114], 'tick' => 0];
        }

        $this->assertStringContainsString('cycle', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsRepeatedMoveTarget(): void
    {
        $recent = [
            ['verb' => 'chop', 'args' => ['n' => 5], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 33, 'y' => 114], 'tick' => 0],
            ['verb' => 'mine', 'args' => ['n' => 1], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 33, 'y' => 114], 'tick' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 33, 'y' => 114], 'tick' => 0],
        ];

        $this->assertStringContainsString('move loop to (33,114)', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopIgnoresAMultiTurnDescentThatIsStillDropping(): void
    {
        $recent = [];
        for ($a = 100; $a >= 20; $a -= 10) {
            $recent[] = ['verb' => 'land', 'args' => [], 'tick' => $a, 'alt' => $a];
        }

        $this->assertNull(AutoPlayer::detectLoop($recent), 'altitude is still falling — a real descent');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsAStuckLandRunWhereAltitudeIsNotMoving(): void
    {
        $recent = array_fill(0, 6, ['verb' => 'land', 'args' => [], 'tick' => 1, 'alt' => 2]);

        $this->assertStringContainsString('stuck land at altitude 2', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsATraversalOnlyStretch(): void
    {
        $recent = [
            ['verb' => 'move', 'args' => ['x' => 1, 'y' => 1], 'tick' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 2, 'y' => 2], 'tick' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 3, 'y' => 3], 'tick' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 4, 'y' => 4], 'tick' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 0],
        ];

        $this->assertStringContainsString('no productive action', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopPassesVariedPlayAndShortHistory(): void
    {
        $this->assertNull(AutoPlayer::detectLoop([
            ['verb' => 'chop', 'args' => ['n' => 15], 'tick' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'wood', 'n' => 20], 'tick' => 0],
            ['verb' => 'move', 'args' => ['x' => 10, 'y' => 10], 'tick' => 0],
            ['verb' => 'mine', 'args' => ['n' => 5], 'tick' => 0],
            ['verb' => 'combine', 'args' => ['ingredients' => ['a' => 1, 'b' => 1]], 'tick' => 0],
            ['verb' => 'construct', 'args' => ['shape' => 'box'], 'tick' => 0],
        ]));
        $this->assertNull(AutoPlayer::detectLoop(array_fill(0, 4, ['verb' => 'chop', 'args' => [], 'tick' => 0])));
    }

    /**
     * The real-world wedge: mine one raw, sell another, forever. Both verbs are
     * "work", so the old checks (dominant fingerprint, tail cycle, traversal
     * window) all passed it.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsAMineSellOscillationWithNoBuild(): void
    {
        // Varied args (different raws each turn) so the pristine 2-cycle check
        // misses it — only the verb-level churn check catches this.
        $raws = ['iron', 'crystal', 'copper', 'nickel', 'silicon', 'cobalt'];
        $recent = [];
        foreach ($raws as $i => $res) {
            $recent[] = ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => $res], 'tick' => $i * 2, 'alt' => 0];
            $recent[] = ['verb' => 'sell', 'args' => ['resource' => $raws[($i + 3) % 6], 'n' => 20], 'tick' => $i * 2 + 1, 'alt' => 0];
        }

        $this->assertStringContainsString('churn: mine/sell', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsBuySellChurn(): void
    {
        $recent = [
            ['verb' => 'buy', 'args' => ['resource' => 'carbon', 'n' => 20], 'tick' => 0, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'carbon', 'n' => 20], 'tick' => 1, 'alt' => 0],
            ['verb' => 'buy', 'args' => ['resource' => 'aluminum', 'n' => 40], 'tick' => 2, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'aluminum', 'n' => 20], 'tick' => 3, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'aluminum', 'n' => 20], 'tick' => 4, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 5, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'brine', 'n' => 20], 'tick' => 6, 'alt' => 0],
            ['verb' => 'buy', 'args' => ['resource' => 'carbon', 'n' => 20], 'tick' => 7, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'carbon', 'n' => 20], 'tick' => 8, 'alt' => 0],
            ['verb' => 'buy', 'args' => ['resource' => 'aluminum', 'n' => 20], 'tick' => 9, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'aluminum', 'n' => 20], 'tick' => 10, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 11, 'alt' => 0],
        ];

        $this->assertStringContainsString('buy/sell churn', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * A dozen turns of harvesting, trading and riding with not one build /
     * assemble / combine / deploy — the exact shape of the reported wedge.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsNoAdvancingActionOverALongWindow(): void
    {
        $recent = [
            ['verb' => 'sell', 'args' => ['resource' => 'brine', 'n' => 20], 'tick' => 0, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 1, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'brine', 'n' => 20], 'tick' => 2, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 3, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'brine', 'n' => 20], 'tick' => 4, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 5, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'brine', 'n' => 20], 'tick' => 6, 'alt' => 0],
            ['verb' => 'ride', 'args' => [], 'tick' => 7, 'alt' => 0],
            ['verb' => 'dock', 'args' => [], 'tick' => 8, 'alt' => 594],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'crystal'], 'tick' => 9, 'alt' => 584],
            ['verb' => 'sell', 'args' => ['resource' => 'crystal', 'n' => 28], 'tick' => 10, 'alt' => 572],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 11, 'alt' => 562],
        ];

        $this->assertNotNull(AutoPlayer::detectLoop($recent));
        $this->assertMatchesRegularExpression('/churn|no advancing action/', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * Guard against the new check over-firing: a stockpiling agent that does
     * reach a build / combine inside the window is not looping.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopAllowsHarvestingThatStillReachesABuild(): void
    {
        $recent = [
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 0, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 1, 'alt' => 0],
            ['verb' => 'buy', 'args' => ['resource' => 'carbon', 'n' => 3], 'tick' => 2, 'alt' => 0],
            ['verb' => 'buy', 'args' => ['resource' => 'aluminum', 'n' => 3], 'tick' => 3, 'alt' => 0],
            ['verb' => 'combine', 'args' => ['ingredients' => ['aluminum' => 1, 'carbon' => 1]], 'tick' => 4, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 5, 'alt' => 0],
            ['verb' => 'gather', 'args' => ['n' => 10], 'tick' => 6, 'alt' => 0],
            ['verb' => 'construct', 'args' => ['shape' => 'box', 'size' => 8], 'tick' => 7, 'alt' => 0],
            ['verb' => 'mine', 'args' => ['n' => 15, 'resource' => 'iron'], 'tick' => 8, 'alt' => 0],
            ['verb' => 'move', 'args' => ['x' => 40, 'y' => 90], 'tick' => 9, 'alt' => 0],
            ['verb' => 'chop', 'args' => ['n' => 12], 'tick' => 10, 'alt' => 0],
            ['verb' => 'sell', 'args' => ['resource' => 'wood', 'n' => 15], 'tick' => 11, 'alt' => 0],
        ];

        $this->assertNull(AutoPlayer::detectLoop($recent));
    }

    /**
     * `construct spire → move → construct spire → move …` — builder-points spam
     * with no `finalize`/`build`/`depart`. `construct` counts as advancing so
     * the churn check misses it; the two-verb-domination check catches it.
     *
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testDetectLoopFlagsConstructMoveSpireSpam(): void
    {
        $recent = [];
        $shapes = ['box', 'cylinder', 'pyramid', 'cone', 'sphere'];
        for ($i = 0; $i < 6; $i++) {
            $recent[] = ['verb' => 'construct', 'args' => ['shape' => $shapes[$i % 5], 'size' => 8, 'height' => 28, 'name' => "spire-{$i}"], 'tick' => $i * 2, 'alt' => 0];
            $recent[] = ['verb' => 'move', 'args' => ['x' => 32 + ($i % 3), 'y' => 114], 'tick' => $i * 2 + 1, 'alt' => 0];
        }

        $this->assertStringContainsString('spinning on', (string) AutoPlayer::detectLoop($recent));
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testStepOverridesABrainLoopWithARotatedObjective(): void
    {
        $state = new StateStore($this->statePath);
        for ($i = 1; $i <= 8; $i++) {
            $state->recordDecision(142287, ['verb' => 'chop', 'args' => ['n' => 1], 'reason' => '', 'queued_intent' => null, 'tick' => $i]);
        }

        $nha = $this->nhaWith(['tick' => 50, 'downed_until' => 0, 'position' => [40, 40], 'inventory' => ['wood' => 25]]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"chop","args":{"n":1}}'), $state);

        $player->step(142287, 'tok');

        $this->assertCount(1, $this->posts);
        $this->assertNotSame('chop', $this->posts[0][1]['verb'], 'the loop was broken with a different action');
        $this->assertNotNull($state->getForcedObjective(142287, 50), 'an objective was forced');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testLoopBreakResearchDoesNotSpendAReserveMaterial(): void
    {
        $state = new StateStore($this->statePath);
        // Rotate the cursor so the next forced objective is `research`.
        $state->bumpForcedObjective(142287, 1); // explore
        $state->bumpForcedObjective(142287, 1); // wealth
        $state->bumpForcedObjective(142287, 1); // build
        for ($i = 1; $i <= 8; $i++) {
            $state->recordDecision(142287, ['verb' => 'chop', 'args' => ['n' => 1], 'reason' => '', 'queued_intent' => null, 'tick' => $i]);
        }

        // composite sits exactly at its reserve (2); wood/iron are spare.
        $nha = $this->nhaWith([
            'tick' => 200, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['composite' => 2, 'wood' => 20, 'iron' => 20],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"chop","args":{"n":1}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('research', $state->getForcedObjective(142287, 200));
        $this->assertSame('combine', $this->posts[0][1]['verb']);
        $this->assertArrayNotHasKey('composite', $this->posts[0][1]['args']['ingredients'], 'the composite reserve is left alone');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\StateStore
     */
    public function testLoopBreakIsSuppressedDuringItsCooldownThenResumes(): void
    {
        $seedLoop = static function (StateStore $s): void {
            for ($i = 1; $i <= 8; $i++) {
                $s->recordDecision(142287, ['verb' => 'chop', 'args' => ['n' => 1], 'reason' => '', 'queued_intent' => null, 'tick' => $i]);
            }
        };

        // A break already happened at tick 50; the agent is still looping now.
        $state = new StateStore($this->statePath);
        $state->bumpForcedObjective(142287, 50);
        $seedLoop($state);

        $withinCooldown = $this->nhaWith(['tick' => 55, 'downed_until' => 0, 'position' => [40, 40], 'inventory' => ['wood' => 25]]);
        (new AutoPlayer($withinCooldown, $this->brainReturning('{"verb":"chop","args":{"n":1}}'), $state))->step(142287, 'tok');
        $this->assertSame('chop', $this->posts[0][1]['verb'], 'no re-break inside the cooldown');

        // Far enough past the break — the guard fires again and rotates on.
        $this->posts = [];
        $afterCooldown = $this->nhaWith(['tick' => 90, 'downed_until' => 0, 'position' => [40, 40], 'inventory' => ['wood' => 25]]);
        (new AutoPlayer($afterCooldown, $this->brainReturning('{"verb":"chop","args":{"n":1}}'), $state))->step(142287, 'tok');
        $this->assertNotSame('chop', $this->posts[0][1]['verb'], 'cooldown over — loop broken again');
        $this->assertSame('wealth', $state->getForcedObjective(142287, 90), 'rotated explore → wealth');
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     * @covers \NHA\Brain\AgentBrain
     */
    public function testACombineThatDipsBelowTheTowerMaterialReserveIsRefused(): void
    {
        // metal held == reserve (8): spending 1 on research would drop under it.
        // (combat kit present so the arm rung is already satisfied.)
        $state = new StateStore($this->statePath);
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['credits' => 5000, 'metal' => 8, 'water' => 5, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"metal":1,"water":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertNotSame('combine', $this->posts[0][1]['verb'], 'the metal reserve is protected');
        $this->assertSame('buy', $this->posts[0][1]['verb'], 'credits go toward the mission instead');
        // Mission-first: a credit-rich grounded agent gears a flyer, so the
        // spare credits buy a ship-craft input (copper/silicon/aluminium/oil/
        // ion_thruster), not tower feedstock.
        $this->assertContains($this->posts[0][1]['args']['resource'], ['copper', 'silicon', 'aluminum', 'carbon', 'oil', 'ion_thruster', 'crystal', 'metal']);
    }

    /**
     * @covers \NHA\Brain\AutoPlayer
     */
    public function testACombineIsAllowedToSpendMaterialSurplusAboveTheReserve(): void
    {
        // 20 metal, reserve 8 — a research combine may spend the surplus.
        $state = new StateStore($this->statePath);
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['credits' => 50, 'metal' => 20, 'water' => 5],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"metal":1,"water":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('combine', $this->posts[0][1]['verb'], 'surplus above the reserve is fair game for research');
        $this->assertSame(['metal' => 1, 'water' => 1], $this->posts[0][1]['args']['ingredients']);
    }

    /**
     * The reserve is not static: once ship parts are on the bench, the metal a
     * half-built flyer still needs is protected from a research `combine` even
     * though it sits well above the 8-unit base reserve.
     *
     * @covers \NHA\Brain\AutoPlayer::reserveFor
     */
    public function testACombineIsRefusedWhenItDipsBelowTheLiveShipBillReserve(): void
    {
        $state = new StateStore($this->statePath);
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'loose_parts' => ['frame', 'cockpit'],
            'inventory' => ['credits' => 40, 'metal' => 15, 'water' => 5, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"metal":1,"water":1}}}'), $state);

        $player->step(142287, 'tok');

        $this->assertNotSame('combine', $this->posts[0][1]['verb'], 'the flyer still needs that metal — reserve raised above the base 8');
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

    /** A depart-capable ship parked in orbit with no open window (combat kit on hand). */
    private function shipHoldingOrbit(array $extraInv = []): NHA
    {
        return $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 560,
            'inventory' => $extraInv + ['credits' => 4000, 'cryo_fuel' => 2, 'heat_shield' => 1, 'acid_skin' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => false], 'mars' => ['open' => false]]],
            'asteroids' => [],
        ]);
    }

    /**
     * Holding for a window: whatever the model picks up there (mine / move /
     * ride / land), the turn is rewritten to the deterministic hold — never a
     * wasted `mine` in orbit.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testAShipHoldingForAWindowIsForcedOntoTheHoldAction(): void
    {
        $player = new AutoPlayer($this->shipHoldingOrbit(), $this->brainReturning('{"verb":"mine","args":{"n":15,"resource":"ice"}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $verb = $this->posts[0][1]['verb'];
        $this->assertNotSame('mine', $verb, 'no mining in orbit while holding');
        $this->assertContains($verb, ['buy', 'dock', 'deposit', 'ride'], 'a real hold action (stock fuel / dock / idle)');
        if ($verb === 'buy') {
            $this->assertSame('cryo_fuel', $this->posts[0][1]['args']['resource']);
        }
    }

    /**
     * Repeating the hold action must NOT trip loop-break — objective rotation
     * up here only burns the combat kit and the stockpile.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testHoldingForAWindowSuppressesLoopBreak(): void
    {
        $state = new StateStore($this->statePath);
        for ($i = 1; $i <= 10; $i++) {
            $state->recordDecision(142287, ['verb' => 'deposit', 'args' => ['resource' => 'ice', 'n' => 1], 'reason' => '', 'queued_intent' => null, 'tick' => $i, 'alt' => 560]);
        }
        $player = new AutoPlayer($this->shipHoldingOrbit(['cryo_fuel' => 150]), $this->brainReturning('{"verb":"deposit","args":{"resource":"ice","n":1}}'), $state);

        $player->step(142287, 'tok');

        // A loop-break would rotate to combine / sell / forced-land. The hold
        // keeps it to an idle / dock instead.
        $this->assertContains($this->posts[0][1]['verb'], ['deposit', 'move', 'dock']);
    }

    /**
     * With a flying ship already finalized, a model `build` is a wasted turn on
     * a second hull — it is swapped for the fallback.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testBuildIsSuppressedOnceAFlyingShipExists(): void
    {
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 4000, 'metal' => 40, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'loose_parts' => ['frame', 'wing'],
            'nearby_deposits' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"build","args":{"part":"wing"}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertNotSame('build', $this->posts[0][1]['verb'], 'no second ship while one already flies');
    }

    /**
     * An open transfer window with a fuelled, shielded ship in orbit → DEPART,
     * whatever the model picked.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testAnOpenWindowForcesDepartOverAModelMine(): void
    {
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95, 'heat_shield' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => true], 'venus' => ['open' => false]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":15,"resource":"crystal"}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertSame('depart', $this->posts[0][1]['verb']);
        $this->assertSame('deimos', $this->posts[0][1]['args']['dest']);
    }

    /**
     * A body this agent has already funded its colony share on is skipped for
     * destination SELECTION — even with its window open and cheaper — so the
     * mission spreads across the whole system instead of camping on the first
     * body reached.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testADepartWindowForAColonyDoneBodyIsSkippedInFavourOfTheNextOne(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordColonyDone(142287, 'deimos');

        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95, 'heat_shield' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            // deimos (already funded) AND mars both have an open window; the
            // hard-coded cheapest-first order would normally pick deimos.
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => true], 'mars' => ['open' => true]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":15,"resource":"crystal"}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('depart', $this->posts[0][1]['verb']);
        $this->assertSame('mars', $this->posts[0][1]['args']['dest'], 'deimos is skipped — its colony share is already funded');
    }

    /**
     * On the ground with a finished ship, the model's mine/sell wandering is
     * swapped for the get-to-orbit move (stock fuel / head for the elevator).
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testGroundedWithAShipIsForcedTowardOrbit(): void
    {
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 10,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5, 'iron' => 60],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'nearby_deposits' => [['resource' => 'iron', 'dist' => 0, 'amount' => 30]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":15,"resource":"iron"}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $verb = $this->posts[0][1]['verb'];
        $this->assertNotSame('mine', $verb, 'no mining while a finished ship waits to fly');
        $this->assertContains($verb, ['buy', 'move', 'ride', 'launch']);
        if ($verb === 'buy') {
            $this->assertSame('cryo_fuel', $this->posts[0][1]['args']['resource']);
        }
    }

    /**
     * The model must not `combine` away the transfer fuel.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testACombineThatEatsFlightFuelIsRefused(): void
    {
        $nha = $this->nhaWith([
            'tick' => 5, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['cryo_fuel' => 80, 'silicon' => 20,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"combine","args":{"ingredients":{"cryo_fuel":1,"silicon":1}}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertNotSame('combine', $this->posts[0][1]['verb'], 'the transfer fuel is protected from a combine');
    }

    /**
     * A model `depart` to a body whose window is NOT open right now is not
     * serviceable — it would just be rejected. The sanity guard swaps it for the
     * deterministic hold instead of letting the agent spam it.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testAModelDepartToAClosedWindowIsSwappedForTheHold(): void
    {
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95, 'heat_shield' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => false], 'mars' => ['open' => false]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"deimos"}}'), new StateStore($this->statePath));

        $player->step(142287, 'tok');

        $this->assertNotSame('depart', $this->posts[0][1]['verb'], 'no depart is submitted while every window is closed');
    }

    /**
     * A `depart` that came back rejected arms a short retry cooldown: even if the
     * window flickers back open the next tick (an observe/intent race), the agent
     * backs off instead of re-sending the same failing intent every tick.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testARejectedDepartArmsARetryCooldown(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'depart', 'args' => ['dest' => 'deimos'], 'reason' => '', 'queued_intent' => 999, 'tick' => 498]);

        // Last turn's depart comes back rejected (closed window); the window is
        // open again this tick and the model immediately re-picks it.
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'status' => 'rejected', 'result' => 'the Deimos launch window is CLOSED',
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95, 'heat_shield' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => true], 'mars' => ['open' => false]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"deimos"}}'), $state);

        $player->step(142287, 'tok');

        $this->assertTrue($state->departRetryCooldownActive(142287, 500), 'the rejection armed a retry cooldown');
        $this->assertNotSame('depart', $this->posts[0][1]['verb'], 'the depart is held back while the cooldown is active');
    }

    /**
     * A `depart` rejected for a thrust-to-weight / capability reason is not a
     * timing problem — the ship simply cannot make that hop. The destination is
     * parked as unreachable for the run so {@see \NHA\Brain\Ladder::departTarget()}
     * stops offering it even with the window wide open.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testATwrRejectedDepartMarksTheDestinationUnreachable(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'depart', 'args' => ['dest' => 'venus'], 'reason' => '', 'queued_intent' => 999, 'tick' => 498]);

        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'status' => 'rejected',
            'result' => 'need a controllable, flying ship with an ion_thruster (orbital drive) and thrust/(mass*4) >= 0.9',
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95, 'heat_shield' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['venus' => ['open' => true]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"venus"}}'), $state);

        $player->step(142287, 'tok');

        $this->assertContains('venus', $state->departUnreachable(142287), 'the TWR rejection parked venus as unreachable');
        $this->assertNull(Ladder::departTarget([
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['cryo_fuel' => 95, 'heat_shield' => 1, 'acid_skin' => 1],
            'expansion' => ['at_body' => null, 'windows' => ['venus' => ['open' => true]]],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
        ], $state->departUnreachable(142287)), 'departTarget no longer offers venus even fully equipped');
    }

    /**
     * A `depart` rejected because the ship has no `landing_gear` part is
     * permanent for that hull (finalize cannot amend a vehicle), so the
     * destination is parked as unreachable — no more spamming it every window.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testALandingGearRejectionMarksTheDestinationUnreachable(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordDecision(142287, ['verb' => 'depart', 'args' => ['dest' => 'deimos'], 'reason' => '', 'queued_intent' => 999, 'tick' => 498]);

        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'status' => 'rejected',
            'result' => 'Deimos needs LANDING GEAR on the ship (build a landing_gear part before finalizing the rocket)',
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => true]]],
            'asteroids' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"deimos"}}'), $state);

        $player->step(142287, 'tok');

        $unreachable = $state->departUnreachable(142287);
        $this->assertContains('deimos', $unreachable, 'the landing-gear rejection parked deimos');
        $this->assertContains('phobos', $unreachable, 'a gearless hull fails identically for phobos');
        $this->assertContains('mars', $unreachable, 'and for mars — parked in one shot, no window burned on each');
    }

    /**
     * A rejection that landed on the world's own activity feed between intent
     * polls is not lost: {@see \NHA\Brain\AutoPlayer::reviewCapabilityFeed()}
     * folds it into the capability ledger, and that verdict merges into the
     * depart-unreachable set the decision phase honours.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testARejectionOnlySeenInTheWorldFeedStillParksTheDestination(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 3000, 'cryo_fuel' => 95,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => true]]],
            'asteroids' => [],
            // The activity feed the bot never saw an intent outcome for.
            'recent' => [
                ['tick' => 1288, 'kind' => 'act', 'data' => [
                    'verb' => 'depart', 'status' => 'rejected',
                    'result' => 'Deimos needs LANDING GEAR on the ship before you can depart',
                ]],
                ['tick' => 1290, 'kind' => 'act', 'data' => [
                    'verb' => 'construct', 'status' => 'rejected',
                    'result' => 'module must be one of: anchor_truss/cracker/depot/mass_driver',
                ]],
            ],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"deimos"}}'), $state);

        $player->step(142287, 'tok');

        $this->assertContains('deimos', $state->capabilityTargets(142287, 'depart'), 'the feed rejection parked deimos');
        $this->assertTrue($state->capabilityBlocked(142287, 'construct:module'), 'the bad-enum construct is on the ledger too');
        $this->assertSame(1290, $state->capabilityReviewTick(142287), 'the review mark advanced to the newest entry walked');
        $this->assertNotSame('depart', $this->posts[0][1]['verb'], 'and the decision phase no longer departs for deimos');
    }

    /**
     * On a body surface with credits in the bank, a colony `construct` the
     * engine keeps refusing for missing materials is swapped for the step that
     * acquires them — not re-issued every tick (the live Deimos spin).
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testABaseProjectBlockedOnMaterialsIsRoutedToAcquisition(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [27, 111],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 25000, 'metal' => 33, 'chip' => 0, 'silicon' => 60, 'copper' => 60,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            // A depart-capable ship on the surface — the "get to orbit" force
            // used to revert the acquisition step back to the doomed construct.
            'vehicles' => [['name' => 'lander', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => 'deimos', 'colony' => ['complete' => false, 'next_module' => 'cregolith_cracker']],
            'recent' => [
                ['tick' => 1298, 'kind' => 'act', 'data' => [
                    'verb' => 'construct', 'status' => 'rejected',
                    'result' => "insufficient for a Regolith Cracker (need {'metal': 80, 'chip': 10})",
                ]],
            ],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"colony","body":"deimos","module":"cregolith_cracker"}}'), $state);

        $player->step(142287, 'tok');

        $verb = $this->posts[0][1]['verb'];
        $this->assertNotSame('construct', $verb, 'the doomed construct is not re-issued');
        $this->assertContains($verb, ['buy', 'combine'], 'it acquires the missing metal / chips instead');
    }

    /**
     * On a body with an unfinished colony, the model's "build another personal
     * extractor" is overridden by funding the next incomplete module — the live
     * Deimos failure (12 redundant crackers while Mass Driver sat at 1/160).
     * The `nhaWith` mock returns one payload for every GET, so the observation
     * here doubles as `GET /colony/deimos`.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testOnABodyItFundsTheNextColonyModuleRatherThanBuildingExtractors(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [37, 114],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 15000, 'superalloy' => 30,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'expansion' => ['at_body' => 'deimos'],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => false,
            'modules' => [
                ['module' => 'depot', 'complete' => true, 'need' => [], 'remaining' => [], 'contrib' => []],
                [
                    'module' => 'mass_driver', 'label' => 'Mass Driver', 'complete' => false,
                    'need' => ['superalloy' => 160, 'nickel' => 120],
                    'remaining' => ['superalloy' => 159, 'nickel' => 120],
                    'contrib' => ['142285' => ['superalloy' => 1]],
                ],
            ],
            'extractors' => array_fill(0, 12, ['id' => 1, 'kind' => 'cregolith_cracker', 'owner' => 142285]),
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"extractor","kind":"cregolith_cracker","body":"deimos"}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertSame('construct', $post['verb']);
        $this->assertSame('colony', $post['args']['shape']);
        $this->assertSame('mass_driver', $post['args']['module']);
    }

    /**
     * Colony complete + already running the extractor cap → a further
     * `construct extractor` is dropped for an earning / holding move.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testItStopsSpammingExtractorsOnceTheColonyIsFinished(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [37, 114],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 15000, 'crystal' => 90,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'expansion' => ['at_body' => 'deimos'],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => true,
            'modules' => [['module' => 'mass_driver', 'complete' => true, 'need' => [], 'remaining' => [], 'contrib' => []]],
            'extractors' => array_fill(0, 12, ['id' => 1, 'kind' => 'cregolith_cracker', 'owner' => 142285]),
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"extractor","kind":"cregolith_cracker","body":"deimos"}}'), $state);

        $player->step(142285, 'tok');

        $this->assertNotSame('construct', $this->posts[0][1]['verb'], 'a 13th extractor is churn — do something else');
    }

    /**
     * Colony share funded and the agent is in the body's ORBIT with a
     * flight-ready ship and the window open → depart for Earth, not another
     * doomed body `construct` (no ladder rung covers the return leg).
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testFromABodyOrbitWithTheShareFundedItDepartsForHome(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 12000, 'cryo_fuel' => 90,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            // In Deimos orbit (at_body null, at_body_orbit set), return window open.
            'expansion' => [
                'at_body' => null, 'at_body_orbit' => 'deimos',
                'windows' => ['deimos' => ['open' => true]],
            ],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => false,
            'modules' => [[
                'module' => 'mass_driver', 'complete' => false,
                'need' => ['superalloy' => 160], 'remaining' => ['superalloy' => 40],
                'contrib' => ['142285' => ['superalloy' => 96]],  // 96 = the 60% cap → no headroom
            ]],
            'extractors' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"colony","body":"deimos","module":"habitat"}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertSame('depart', $post['verb']);
        $this->assertSame('earth', $post['args']['dest']);
    }

    /**
     * Share funded, on the moon's surface, low on return fuel → stock cryo_fuel.
     * And a model `depart {dest:'deimos'}` (departing to where it already is —
     * the fuel-burning loop) is never let through.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testShareFundedOnTheSurfaceLowOnFuelStocksItAndNeverDepartsToTheCurrentBody(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 440,
            'inventory' => ['credits' => 11000, 'cryo_fuel' => 19,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => [
                'location' => 'on_deimos', 'place' => ['where' => 'body_surface', 'body' => 'deimos'],
                'at_body' => 'deimos', 'return_dv' => 45,
                'windows' => ['deimos' => ['open' => false, 'opens_in' => 300]],
            ],
            'elevators' => [['x' => 30, 'y' => 110, 'height' => 680]],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => true,
            'modules' => [['module' => 'mass_driver', 'complete' => true, 'need' => [], 'remaining' => [], 'contrib' => []]],
            'extractors' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"depart","args":{"dest":"deimos"}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertNotSame('depart', $post['verb'], 'never depart to the body you are already on');
        $this->assertSame('buy', $post['verb']);
        $this->assertSame('cryo_fuel', $post['args']['resource']);
    }

    /**
     * The `agent_going_home` latch keeps the return state machine active on a
     * tick where `expansion.at_body` / `at_body_orbit` / `location` all glitch
     * to empty — without it the guardrail goes dormant and the agent drifts
     * back into model/ladder churn on exactly those ticks.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testTheGoingHomeLatchSurvivesAnExpansionFieldGlitch(): void
    {
        $state = new StateStore($this->statePath);
        $state->setGoingHome(142285, 'deimos');

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 480,
            'inventory' => ['credits' => 12000, 'cryo_fuel' => 90,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            // The glitch: no at_body / at_body_orbit / location at all — only
            // the window (keyed by the LATCHED body) is still readable.
            'expansion' => ['windows' => ['deimos' => ['open' => true]]],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => true,
            'modules' => [['module' => 'mass_driver', 'complete' => true, 'need' => [], 'remaining' => [], 'contrib' => []]],
            'extractors' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":15}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertSame('depart', $post['verb'], 'the latch keeps driving the return trip through the glitch');
        $this->assertSame('earth', $post['args']['dest']);
    }

    /**
     * Ridden up to the elevator top on a moon (alt ~600, still reporting
     * `place.where: body_surface` since the moon's own fields don't change)
     * with the window shut → HOLD in the band. Must not `ride` back down
     * (the live sawtooth: ride up, window still shut, ride down, ride up, …).
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testAtTheElevatorTopWithTheWindowShutItHoldsRatherThanRidingBackDown(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 600,
            'inventory' => ['credits' => 9000, 'cryo_fuel' => 125,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => [
                'location' => 'on_deimos', 'place' => ['where' => 'body_surface', 'body' => 'deimos'],
                'at_body' => 'deimos', 'return_dv' => 45,
                'windows' => ['deimos' => ['open' => false, 'opens_in' => 300]],
            ],
            'elevators' => [['x' => 30, 'y' => 110, 'height' => 680]],
            'body' => 'deimos', 'cap_pct_per_agent' => 60, 'complete' => true,
            'modules' => [['module' => 'mass_driver', 'complete' => true, 'need' => [], 'remaining' => [], 'contrib' => []]],
            'extractors' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"ride","args":{}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertNotSame('ride', $post['verb'], 'holding in the band, not bouncing back to the ground');
        $this->assertNotSame('depart', $post['verb'], 'window is shut');
    }

    /**
     * Live near Earth, orbital decay crossed the 300 depart floor within a
     * single autoplay turn, so the v3.2.35 station-keep bounce (ride down,
     * ride back up) fired on almost every turn instead of the rare reset it
     * was designed for — 35+ consecutive `ride`s over 9 minutes, never once
     * holding to top fuel/shield/acid_skin or sitting in the depart band. A
     * `ride` arriving before its cooldown must be rewritten to a hold.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testARideWithinItsCooldownIsRewrittenToAHoldInsteadOfBouncingEveryTurn(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordRide(142285, 1295); // 5 ticks ago — still cooling down.

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 288,
            'inventory' => ['credits' => 9000, 'cryo_fuel' => 125,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => [
                'location' => 'earth', 'place' => ['where' => 'earth_space'],
                'windows' => ['deimos' => ['open' => false, 'opens_in' => 160]],
            ],
            'elevators' => [['x' => 30, 'y' => 110, 'height' => 680]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"ride","args":{}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertNotSame('ride', $post['verb'], 'still cooling down from the last bounce — hold instead');
    }

    /**
     * Once the cooldown has elapsed the bounce is allowed through again — and
     * issuing it re-arms the cooldown for the next one.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testARideOnceItsCooldownHasElapsedIsAllowedAndReArmsIt(): void
    {
        $state = new StateStore($this->statePath);
        $state->recordRide(142285, 1280); // 20 ticks ago — the 12-tick cooldown has elapsed.

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [30, 110],
            'in_space' => true, 'altitude' => 288,
            'inventory' => ['credits' => 9000, 'cryo_fuel' => 125,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => [
                'location' => 'earth', 'place' => ['where' => 'earth_space'],
                'windows' => ['deimos' => ['open' => false, 'opens_in' => 160]],
            ],
            'elevators' => [['x' => 30, 'y' => 110, 'height' => 680]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"ride","args":{}}'), $state);

        $player->step(142285, 'tok');

        $post = $this->posts[0][1];
        $this->assertSame('ride', $post['verb'], 'cooldown elapsed — the bounce is allowed');
        $this->assertTrue($state->rideCooldownActive(142285, 1301), 'issuing the ride re-arms the cooldown');
    }

    /**
     * The engine's named buildable `kind` for the body is adopted when the feed
     * has no materials shortfall to act on — the model's bad `shape`/`kind` is
     * replaced with exactly what the engine asked for.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testTheEngineNamedBuildableKindIsAdoptedOnTheBodySurface(): void
    {
        $state = new StateStore($this->statePath);

        $nha = $this->nhaWith([
            'tick' => 1300, 'downed_until' => 0, 'position' => [27, 111],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 25000, 'metal' => 200, 'chip' => 40,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'lander', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => 'deimos', 'colony' => ['complete' => false, 'next_module' => 'cregolith_cracker']],
            'recent' => [
                ['tick' => 1298, 'kind' => 'act', 'data' => [
                    'verb' => 'construct', 'status' => 'rejected',
                    'result' => 'kind must be one buildable on Deimos: cregolith_cracker',
                ]],
            ],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"construct","args":{"shape":"extractor","kind":"mine","body":"deimos"}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('construct', $this->posts[0][1]['verb']);
        $this->assertSame('cregolith_cracker', $this->posts[0][1]['args']['kind'], 'the bad kind:mine is replaced');
        $this->assertArrayNotHasKey('module', $this->posts[0][1]['args']);
    }

    /**
     * `finalize`-ing a new hull wipes the previous ship's depart verdicts, so
     * the fresh (gear-carrying) flyer is judged on its own merits.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testFinalizingANewShipClearsTheStaleDepartVerdicts(): void
    {
        $state = new StateStore($this->statePath);
        foreach (['deimos', 'phobos', 'mars'] as $d) {
            $state->recordDepartRejection(142287, $d, 400, true);
        }
        $state->recordDecision(142287, ['verb' => 'finalize', 'args' => [], 'reason' => '', 'queued_intent' => 999, 'tick' => 498]);
        $this->assertSame(['deimos', 'phobos', 'mars'], $state->departUnreachable(142287));

        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0, 'status' => 'applied',
            'inventory' => ['credits' => 3000, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'flyer2', 'flies' => true, 'orbital_engine' => true]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"mine","args":{"n":1}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame([], $state->departUnreachable(142287), 'the new hull starts with a clean slate');
    }

    /**
     * Committing a `finalize` clears the depart verdicts immediately — not only
     * when its outcome is polled (a double-finalize loses that).
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testCommittingAFinalizeClearsDepartVerdictsAtDecisionTime(): void
    {
        $state = new StateStore($this->statePath);
        foreach (['deimos', 'phobos', 'mars'] as $d) {
            $state->recordDepartRejection(142287, $d, 400, true);
        }

        // A completed geared bundle on the ground; the model asks to finalize.
        $bundle = array_merge(
            ['frame', 'cockpit', 'jet', 'tail', 'fuel_tank', 'fuel_tank', 'landing_gear'],
            array_fill(0, 3, 'engine'),
            array_fill(0, 2, 'propeller'),
            array_fill(0, 3, 'wing'),
        );
        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 3000, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'deadend', 'flies' => true, 'orbital_engine' => true]],
            'loose_parts' => $bundle, 'nearby_deposits' => [],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"finalize","args":{}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('finalize', $this->posts[0][1]['verb']);
        $this->assertSame([], $state->departUnreachable(142287));
    }

    /**
     * A dead-end hull (every gear body rejected) on the ground: the model's
     * mine/sell wandering is overridden by the deterministic rebuild step, not
     * left to churn.
     *
     * @covers \NHA\Brain\AutoPlayer::step
     */
    public function testAStrandedHullIsDrivenToRebuildNotLeftToChurn(): void
    {
        $state = new StateStore($this->statePath);
        foreach (['deimos', 'phobos', 'mars'] as $d) {
            $state->recordDepartRejection(142287, $d, 400, true);
        }

        $nha = $this->nhaWith([
            'tick' => 500, 'downed_until' => 0, 'position' => [10, 10],
            'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 8000, 'metal' => 40, 'composite' => 6, 'chip' => 2, 'ion_thruster' => 1,
                'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'vehicles' => [['name' => 'deadend', 'flies' => true, 'orbital_engine' => true]],
            'loose_parts' => [], 'nearby_deposits' => [], 'elevators' => [['x' => 10, 'y' => 10, 'height' => 500]],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"sell","args":{"resource":"iron","n":80}}'), $state);

        $player->step(142287, 'tok');

        $verb = $this->posts[0][1]['verb'];
        $this->assertNotSame('sell', $verb, 'not left day-trading — the hull is a dead end');
        $this->assertContains($verb, ['build', 'combine', 'buy'], 'driven toward a fresh geared flyer');
    }

    /**
     * A loop-break research pass must never `combine` away survival gear.
     *
     * @covers \NHA\Brain\AutoPlayer::loopBreakDecision
     */
    public function testLoopBreakResearchNeverBurnsTheCombatKit(): void
    {
        $state = new StateStore($this->statePath);
        $state->bumpForcedObjective(142287, 1); // explore
        $state->bumpForcedObjective(142287, 1); // wealth
        $state->bumpForcedObjective(142287, 1); // build
        for ($i = 1; $i <= 8; $i++) {
            $state->recordDecision(142287, ['verb' => 'chop', 'args' => ['n' => 1], 'reason' => '', 'queued_intent' => null, 'tick' => $i]);
        }
        $nha = $this->nhaWith([
            'tick' => 200, 'downed_until' => 0, 'position' => [1, 1],
            'inventory' => ['slug' => 22, 'stimpack' => 3, 'wood' => 40, 'iron' => 40],
        ]);
        $player = new AutoPlayer($nha, $this->brainReturning('{"verb":"chop","args":{"n":1}}'), $state);

        $player->step(142287, 'tok');

        $this->assertSame('research', $state->getForcedObjective(142287, 200));
        $this->assertSame('combine', $this->posts[0][1]['verb']);
        $ingredients = array_keys($this->posts[0][1]['args']['ingredients']);
        $this->assertSame([], array_intersect($ingredients, ['slug', 'stimpack']), 'the weapon ammo and medicine are left alone');
    }
}
