<?php

declare(strict_types=1);

use NHA\Brain\AgentBrain;
use NHA\Brain\OllamaClient;
use NHA\Parts\AgentObservation;

use function React\Promise\resolve;

class AgentBrainTest extends NHAUnitTestCase
{
    private function brainReturning(string $modelContent): AgentBrain
    {
        $ollama = new OllamaClient(
            'http://x',
            'gemma3:27b',
            fn() => resolve(json_encode(['message' => ['role' => 'assistant', 'content' => $modelContent], 'done' => true])),
        );

        return new AgentBrain($ollama);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testParseDecisionAcceptsPlainJson(): void
    {
        $d = AgentBrain::parseDecision('{"verb":"move","args":{"dx":1,"dy":0},"reason":"head to wood"}');

        $this->assertSame('move', $d['verb']);
        $this->assertSame(['dx' => 1, 'dy' => 0], $d['args']);
        $this->assertSame('head to wood', $d['reason']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testParseDecisionStripsCodeFence(): void
    {
        $d = AgentBrain::parseDecision("```json\n{\"verb\":\"mine\",\"args\":{\"n\":3}}\n```");

        $this->assertSame('mine', $d['verb']);
        $this->assertSame(['n' => 3], $d['args']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testParseDecisionExtractsJsonFromProse(): void
    {
        $d = AgentBrain::parseDecision('Sure! I think you should {"verb":"gather","args":{"n":1}} because loot is near.');

        $this->assertSame('gather', $d['verb']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testParseDecisionReturnsNullForWaitOrUnknownOrGarbage(): void
    {
        $this->assertNull(AgentBrain::parseDecision('{"verb":"wait"}'));
        $this->assertNull(AgentBrain::parseDecision('{"verb":"teleport","args":{}}'));
        $this->assertNull(AgentBrain::parseDecision('not json at all'));
        $this->assertNull(AgentBrain::parseDecision('{"reason":"no verb"}'));
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testParseDecisionDefaultsMissingArgsToEmptyArray(): void
    {
        $d = AgentBrain::parseDecision('{"verb":"launch"}');

        $this->assertSame('launch', $d['verb']);
        $this->assertSame([], $d['args']);
        $this->assertSame('', $d['reason']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testSummarizeIncludesKeyFacts(): void
    {
        $obs = new AgentObservation(142287, [
            'tick' => 1099632,
            'position' => [30, 118],
            'hp' => 100, 'hp_max' => 100,
            'altitude' => 0, 'in_space' => false,
            'expansion' => ['era' => 'expansion', 'location' => 'earth'],
            'inventory' => ['wood' => 1, 'metal' => 40],
            'nearby_deposits' => [['resource' => 'wood', 'x' => 30, 'y' => 118, 'amount' => 22]],
            'nearby_agents' => [['id' => 142269, 'name' => 'rival', 'x' => 31, 'y' => 119, 'hp' => 100, 'dist' => 2]],
            'system_notices' => [['text' => 'SPACE ERA -- BUILD THE STATION']],
            'messages' => [['sender_name' => 'Barbarian', 'text' => 'blood and steel']],
        ]);

        $summary = (new AgentBrain(new OllamaClient('http://x', 'm', fn() => resolve(''))))->summarize($obs);

        $this->assertStringContainsString('agent #142287', $summary);
        $this->assertStringContainsString('(30, 118)', $summary);
        $this->assertStringContainsString('HP: 100/100', $summary);
        $this->assertStringContainsString('expansion (earth)', $summary);
        $this->assertStringContainsString('wood@(30,118) x22', $summary);
        $this->assertStringContainsString('#142269 rival@(31,119)', $summary);
        $this->assertStringContainsString('SPACE ERA', $summary);
        $this->assertStringContainsString('JSON only', $summary);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testSummarizeHandlesObjectNestedPayload(): void
    {
        // The real NHA HTTP client decodes to stdClass, not arrays.
        $raw = json_decode(json_encode([
            'tick' => 1099632,
            'position' => [30, 118],
            'hp' => 100, 'hp_max' => 100,
            'expansion' => ['era' => 'expansion', 'location' => 'earth'],
            'inventory' => ['wood' => 1],
            'nearby_deposits' => [['resource' => 'wood', 'x' => 30, 'y' => 118, 'amount' => 22]],
            'nearby_agents' => [['id' => 1, 'name' => 'rival', 'x' => 31, 'y' => 119, 'hp' => 100, 'dist' => 2]],
            'system_notices' => [['text' => 'SPACE ERA -- BUILD']],
            'messages' => [['sender_name' => 'Barbarian', 'text' => 'hi']],
        ]));
        $obs = new AgentObservation(142287, (array) $raw);

        $summary = (new AgentBrain(new OllamaClient('http://x', 'm', fn() => resolve(''))))->summarize($obs);

        $this->assertStringContainsString('(30, 118)', $summary);
        $this->assertStringContainsString('expansion (earth)', $summary);
        $this->assertStringContainsString('wood@(30,118) x22', $summary);
        $this->assertStringContainsString('#1 rival@(31,119)', $summary);
        $this->assertStringContainsString('SPACE ERA', $summary);
        $this->assertStringContainsString('[Barbarian] hi', $summary);
    }

    /**
     * A stale free-text `reason` from a previous turn must not be replayed into
     * the digest — the model would otherwise keep re-asserting "I have 9 wood"
     * against a live inventory that says otherwise. Only the verb, args and the
     * server's outcome carry forward.
     *
     * @covers \NHA\Brain\AgentBrain
     */
    public function testSummarizeDoesNotEchoThePreviousReasonButKeepsLiveInventory(): void
    {
        $obs = new AgentObservation(142285, [
            'tick' => 5000,
            'position' => [33, 114],
            'hp' => 100, 'hp_max' => 100,
            'inventory' => ['credits' => 12], // no wood at all
        ]);
        $last = [
            'verb' => 'chop',
            'args' => ['n' => 15],
            'reason' => 'I have 9 wood and am standing on a wood deposit; planting ensures supply',
            'tick' => 4990,
            'outcome' => ['status' => 'applied', 'result' => 'chopped 1 wood'],
        ];

        $summary = (new AgentBrain(new OllamaClient('http://x', 'm', fn() => resolve(''))))->summarize($obs, $last);

        $this->assertStringNotContainsString('I have 9 wood', $summary, 'the previous reason string is not replayed');
        $this->assertStringContainsString('Last turn: you chose chop {"n":15}', $summary);
        $this->assertStringContainsString('It APPLIED', $summary, 'the server outcome still carries forward');
        $this->assertStringContainsString('credits 12', $summary, 'the live inventory is present');
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testDecideResolvesValidatedDecision(): void
    {
        $brain = $this->brainReturning('{"verb":"mine","args":{"n":2},"reason":"wood underfoot"}');

        $decision = 'unset';
        $brain->decide(new AgentObservation(1, ['tick' => 5, 'position' => [1, 1]]))->then(function ($d) use (&$decision) {
            $decision = $d;
        });

        $this->assertSame(['verb' => 'mine', 'args' => ['n' => 2], 'reason' => 'wood underfoot'], $decision);
    }

    /**
     * @covers \NHA\Brain\AgentBrain
     */
    public function testDecideResolvesNullWhenModelSaysWait(): void
    {
        $brain = $this->brainReturning('{"verb":"wait","reason":"nothing to do"}');

        $decision = 'unset';
        $brain->decide(new AgentObservation(1, ['tick' => 5]))->then(function ($d) use (&$decision) {
            $decision = $d;
        });

        $this->assertNull($decision);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::suggestion
     */
    /** A minimum combat kit, so the arm rung is satisfied and the ladder moves past it. */
    private const KIT = ['stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5];

    private static function ground(array $extraInventory, int $tick = 10): array
    {
        return ['tick' => $tick, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT + $extraInventory, 'nearby_deposits' => []];
    }

    public function testSuggestionSpendsCreditsTowardATowerWhenStuck(): void
    {
        // On the ground, armed, rich in credits, no build materials, no research.
        $step1 = AgentBrain::suggestion(self::ground(['credits' => 5000]), [], [], false);
        $this->assertSame('buy', $step1['verb']);
        $this->assertSame('metal', $step1['args']['resource']);

        // Metal in hand → start buying the composite feedstock.
        $step2 = AgentBrain::suggestion(self::ground(['credits' => 5000, 'metal' => 10]), [], [], false);
        $this->assertSame('buy', $step2['verb']);
        $this->assertContains($step2['args']['resource'], ['aluminum', 'carbon']);

        // Feedstock in hand → combine into composite (a production recipe).
        $step3 = AgentBrain::suggestion(self::ground(['credits' => 5000, 'metal' => 10, 'aluminum' => 3, 'carbon' => 3]), [], [], false);
        $this->assertSame('combine', $step3['verb']);
        $this->assertSame(['aluminum' => 1, 'carbon' => 1], $step3['args']['ingredients']);

        // Composite + metal in hand → construct.
        $step4 = AgentBrain::suggestion(self::ground(['credits' => 5000, 'metal' => 10, 'composite' => 3]), [], [], false);
        $this->assertSame('construct', $step4['verb']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::suggestion
     */
    public function testSuggestionSellsOnlyWhenCreditsAreBelowTheFloor(): void
    {
        $ground = fn(int $credits): array => self::ground(['credits' => $credits, 'wood' => 50]);

        // Below the 300 floor → sell the surplus (keep 10).
        $poor = AgentBrain::suggestion($ground(100), [], [], false);
        $this->assertSame('sell', $poor['verb']);
        $this->assertSame('wood', $poor['args']['resource']);
        $this->assertSame(20, $poor['args']['n']);

        // Healthy credits → it never reaches a sell (buys toward a tower instead).
        $rich = AgentBrain::suggestion($ground(1000), [], [], false);
        $this->assertNotSame('sell', $rich['verb'], 'raws are kept when the credits are not needed');
    }

    /**
     * @covers \NHA\Brain\AgentBrain::suggestion
     */
    public function testSuggestionHarvestsUpToTheStockpileTargetNotJustWhenShort(): void
    {
        $onDeposit = fn(int $held): array => [
            'tick' => 1, 'in_space' => false, 'altitude' => 0,
            'inventory' => self::KIT + ['credits' => 1000, 'wood' => $held],
            'nearby_deposits' => [['resource' => 'wood', 'amount' => 50, 'x' => 1, 'y' => 1, 'dist' => 0]],
        ];

        // Holding 20 — under the old "< 15" bar it would stop, now it tops up to 30.
        $s = AgentBrain::suggestion($onDeposit(20), [], [], false);
        $this->assertSame('chop', $s['verb']);
        $this->assertSame(10, $s['args']['n'], 'harvest exactly up to the 30 target');

        // Already at target — do not keep harvesting.
        $atTarget = AgentBrain::suggestion($onDeposit(30), [], [], false);
        $this->assertNotSame('chop', $atTarget['verb']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::defensiveAction
     */
    public function testDefensiveActionHealsWhenBadlyHurtWithAMedicineOnHand(): void
    {
        $d = AgentBrain::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 20, 'hp_max' => 100,
            'inventory' => ['stimpack' => 1],
            'alerts' => [['tick' => 95, 'kind' => 'attacked', 'by' => 7, 'dmg' => 75]],
            'nearby_agents' => [['id' => 7, 'x' => 11, 'y' => 10, 'dist' => 1]],
        ]);

        $this->assertSame('heal', $d['verb']);
        $this->assertSame('stimpack', $d['args']['item']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::defensiveAction
     */
    public function testDefensiveActionShootsBackWhenArmedAndTheAttackerIsInRange(): void
    {
        $d = AgentBrain::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 80, 'hp_max' => 100,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6],
            'last_robbed_by' => 7,
            'nearby_agents' => [['id' => 7, 'x' => 13, 'y' => 10, 'dist' => 3]],
        ]);

        $this->assertSame('attack', $d['verb']);
        $this->assertSame('kinetic_gun', $d['args']['weapon']);
        $this->assertSame(7, $d['args']['target']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::defensiveAction
     */
    public function testDefensiveActionBreaksContactWhenUnarmed(): void
    {
        $d = AgentBrain::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 70, 'hp_max' => 100,
            'inventory' => [],
            'alerts' => [['tick' => 98, 'kind' => 'attacked', 'by' => 7]],
            'nearby_agents' => [['id' => 7, 'x' => 12, 'y' => 10, 'dist' => 2]],
        ]);

        $this->assertSame('move', $d['verb']);
        $this->assertLessThan(10, $d['args']['x'], 'stepped away from the attacker at x=12');
    }

    /**
     * @covers \NHA\Brain\AgentBrain::defensiveAction
     */
    public function testDefensiveActionIsNullWithNoThreat(): void
    {
        $this->assertNull(AgentBrain::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 100, 'hp_max' => 100,
            'inventory' => [], 'alerts' => [], 'nearby_agents' => [],
        ]));
    }

    /**
     * @covers \NHA\Brain\AgentBrain::suggestion
     */
    public function testSuggestionArmsItselfWhenUnequippedAndFunded(): void
    {
        $base = ['tick' => 1, 'in_space' => false, 'altitude' => 0, 'inventory' => ['credits' => 5000]];

        $noMed = AgentBrain::suggestion($base, [], [], false);
        $this->assertSame('buy', $noMed['verb']);
        $this->assertSame('stimpack', $noMed['args']['resource']);

        $hasMed = AgentBrain::suggestion(
            ['tick' => 1, 'in_space' => false, 'altitude' => 0, 'inventory' => ['credits' => 5000, 'stimpack' => 1]],
            [],
            [],
            false,
        );
        $this->assertSame('buy', $hasMed['verb']);
        $this->assertSame('kinetic_gun', $hasMed['args']['resource']);
    }

    /**
     * @covers \NHA\Brain\AgentBrain::suggestion
     */
    public function testSuggestionDeploysAnIdleVehicleForPassiveIncome(): void
    {
        $suggestion = AgentBrain::suggestion([
            'tick' => 1,
            'inventory' => ['credits' => 100],
            'vehicles' => [['name' => 'rover', 'deployed' => false]],
        ], [], [], false);

        $this->assertSame('deploy', $suggestion['verb']);
    }
}
