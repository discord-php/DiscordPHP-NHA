<?php

declare(strict_types=1);

use NHA\Brain\Ladder;

/**
 * The deterministic fallback ladder lifted out of {@see \NHA\Brain\AgentBrain}.
 * {@see \NHA\Brain\PromptBuilder} surfaces its pick to the model and
 * {@see \NHA\Brain\AutoPlayer} drops to it when a pick is refused.
 */
class LadderTest extends NHAUnitTestCase
{
    /** A minimum combat kit, so the arm rung is satisfied and the ladder moves past it. */
    private const KIT = ['stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5];

    private static function ground(array $extraInventory, int $tick = 10): array
    {
        return ['tick' => $tick, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT + $extraInventory, 'nearby_deposits' => []];
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testSuggestionSpendsCreditsTowardATowerWhenStuck(): void
    {
        // On the ground, armed, rich in credits, no build materials, no research.
        $step1 = Ladder::suggestion(self::ground(['credits' => 5000]), [], [], false);
        $this->assertSame('buy', $step1['verb']);
        $this->assertSame('metal', $step1['args']['resource']);

        // Metal in hand → start buying the composite feedstock.
        $step2 = Ladder::suggestion(self::ground(['credits' => 5000, 'metal' => 10]), [], [], false);
        $this->assertSame('buy', $step2['verb']);
        $this->assertContains($step2['args']['resource'], ['aluminum', 'carbon']);

        // Feedstock in hand → combine into composite (a production recipe).
        $step3 = Ladder::suggestion(self::ground(['credits' => 5000, 'metal' => 10, 'aluminum' => 3, 'carbon' => 3]), [], [], false);
        $this->assertSame('combine', $step3['verb']);
        $this->assertSame(['aluminum' => 1, 'carbon' => 1], $step3['args']['ingredients']);

        // Composite + metal in hand → construct.
        $step4 = Ladder::suggestion(self::ground(['credits' => 5000, 'metal' => 10, 'composite' => 3]), [], [], false);
        $this->assertSame('construct', $step4['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testSuggestionSellsOnlyWhenCreditsAreBelowTheFloor(): void
    {
        $ground = fn(int $credits): array => self::ground(['credits' => $credits, 'wood' => 50]);

        // Below the 300 floor → sell the surplus (keep 10).
        $poor = Ladder::suggestion($ground(100), [], [], false);
        $this->assertSame('sell', $poor['verb']);
        $this->assertSame('wood', $poor['args']['resource']);
        $this->assertSame(20, $poor['args']['n']);

        // Healthy credits → it never reaches a sell (buys toward a tower instead).
        $rich = Ladder::suggestion($ground(1000), [], [], false);
        $this->assertNotSame('sell', $rich['verb'], 'raws are kept when the credits are not needed');
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testSuggestionHarvestsUpToTheStockpileTargetNotJustWhenShort(): void
    {
        $onDeposit = fn(int $held): array => [
            'tick' => 1, 'in_space' => false, 'altitude' => 0,
            'inventory' => self::KIT + ['credits' => 1000, 'wood' => $held],
            'nearby_deposits' => [['resource' => 'wood', 'amount' => 50, 'x' => 1, 'y' => 1, 'dist' => 0]],
        ];

        // Holding 20 — under the old "< 15" bar it would stop, now it tops up to 30.
        $s = Ladder::suggestion($onDeposit(20), [], [], false);
        $this->assertSame('chop', $s['verb']);
        $this->assertSame(10, $s['args']['n'], 'harvest exactly up to the 30 target');

        // Already at target — do not keep harvesting.
        $atTarget = Ladder::suggestion($onDeposit(30), [], [], false);
        $this->assertNotSame('chop', $atTarget['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::defensiveAction
     */
    public function testDefensiveActionHealsWhenBadlyHurtWithAMedicineOnHand(): void
    {
        $d = Ladder::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 20, 'hp_max' => 100,
            'inventory' => ['stimpack' => 1],
            'alerts' => [['tick' => 95, 'kind' => 'attacked', 'by' => 7, 'dmg' => 75]],
            'nearby_agents' => [['id' => 7, 'x' => 11, 'y' => 10, 'dist' => 1]],
        ]);

        $this->assertSame('heal', $d['verb']);
        $this->assertSame('stimpack', $d['args']['item']);
    }

    /**
     * @covers \NHA\Brain\Ladder::defensiveAction
     */
    public function testDefensiveActionShootsBackWhenArmedAndTheAttackerIsInRange(): void
    {
        $d = Ladder::defensiveAction([
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
     * @covers \NHA\Brain\Ladder::defensiveAction
     */
    public function testDefensiveActionBreaksContactWhenUnarmed(): void
    {
        $d = Ladder::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 70, 'hp_max' => 100,
            'inventory' => [],
            'alerts' => [['tick' => 98, 'kind' => 'attacked', 'by' => 7]],
            'nearby_agents' => [['id' => 7, 'x' => 12, 'y' => 10, 'dist' => 2]],
        ]);

        $this->assertSame('move', $d['verb']);
        $this->assertLessThan(10, $d['args']['x'], 'stepped away from the attacker at x=12');
    }

    /**
     * @covers \NHA\Brain\Ladder::defensiveAction
     */
    public function testDefensiveActionIsNullWithNoThreat(): void
    {
        $this->assertNull(Ladder::defensiveAction([
            'tick' => 100, 'position' => [10, 10], 'hp' => 100, 'hp_max' => 100,
            'inventory' => [], 'alerts' => [], 'nearby_agents' => [],
        ]));
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testSuggestionArmsItselfWhenUnequippedAndFunded(): void
    {
        $base = ['tick' => 1, 'in_space' => false, 'altitude' => 0, 'inventory' => ['credits' => 5000]];

        $noMed = Ladder::suggestion($base, [], [], false);
        $this->assertSame('buy', $noMed['verb']);
        $this->assertSame('stimpack', $noMed['args']['resource']);

        $hasMed = Ladder::suggestion(
            ['tick' => 1, 'in_space' => false, 'altitude' => 0, 'inventory' => ['credits' => 5000, 'stimpack' => 1]],
            [],
            [],
            false,
        );
        $this->assertSame('buy', $hasMed['verb']);
        $this->assertSame('kinetic_gun', $hasMed['args']['resource']);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testSuggestionDeploysAnIdleVehicleForPassiveIncome(): void
    {
        $suggestion = Ladder::suggestion([
            'tick' => 1,
            'inventory' => ['credits' => 100],
            'vehicles' => [['name' => 'rover', 'deployed' => false]],
        ], [], [], false);

        $this->assertSame('deploy', $suggestion['verb']);
    }

    // ── Expansionist stance — the Solar Accord flight chain ────────────

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistLandsOnceArrivedAtABody(): void
    {
        $inOrbitOfMars = [
            'tick' => 5, 'in_space' => true, 'altitude' => 320,
            'inventory' => self::KIT + ['credits' => 500],
            'expansion' => ['at_body' => 'mars'],
        ];
        $pick = Ladder::suggestion($inOrbitOfMars, [], [], false, 'expansionist');
        $this->assertSame('land_body', $pick['verb']);

        $atMoon = $inOrbitOfMars;
        $atMoon['expansion']['at_body'] = 'moon';
        $this->assertSame('land_moon', Ladder::suggestion($atMoon, [], [], false, 'expansionist')['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistFundsTheColonyOnTheBodySurface(): void
    {
        $onMars = [
            'tick' => 5, 'in_space' => true, 'altitude' => 0,
            'inventory' => self::KIT + ['credits' => 500, 'metal' => 10],
            'expansion' => [
                'at_body' => 'mars',
                'colony' => ['complete' => false, 'next_module' => 'ares_base'],
            ],
        ];
        $pick = Ladder::suggestion($onMars, [], [], false, 'expansionist');
        $this->assertSame('construct', $pick['verb']);
        $this->assertSame('colony', $pick['args']['shape']);
        $this->assertSame('mars', $pick['args']['body']);
        $this->assertSame('ares_base', $pick['args']['module']);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistDepartsFromEarthOrbitWhenReadyAndAWindowIsOpen(): void
    {
        $earthOrbit = [
            'tick' => 5, 'in_space' => true, 'altitude' => 320,
            'inventory' => self::KIT + ['ion_thruster' => 1, 'hydrogen' => 4, 'heat_shield' => 1],
            'expansion' => ['at_body' => null, 'windows' => [
                'mars' => ['open' => true], 'venus' => ['open' => false],
            ]],
        ];
        $pick = Ladder::suggestion($earthOrbit, [], [], false, 'expansionist');
        $this->assertSame('depart', $pick['verb']);
        $this->assertSame('mars', $pick['args']['dest']);

        // Without the heat_shield Mars is off the table — no depart.
        $noShield = $earthOrbit;
        unset($noShield['inventory']['heat_shield']);
        $pick2 = Ladder::suggestion($noShield, [], [], false, 'expansionist');
        $this->assertNotSame('depart', $pick2['verb'] ?? null);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistGearsTheShipBeforeRidingUp(): void
    {
        $base = static fn(array $extra): array => [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + $extra,
            'elevators' => [['x' => 10, 'y' => 10]], 'nearby_deposits' => [],
        ];

        // Loose parts in hold → assemble the ship.
        $parts = Ladder::suggestion(
            ['tick' => 5, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT, 'loose_parts' => ['a', 'b', 'c']],
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertSame('finalize', $parts['verb']);

        // Credits, no ion_thruster → buy the one the depot stocks.
        $thruster = Ladder::suggestion($base(['credits' => 4000]), [], [], false, 'expansionist');
        $this->assertSame('buy', $thruster['verb']);
        $this->assertSame('ion_thruster', $thruster['args']['resource']);

        // Have the thruster, still no fuel → buy cryo_fuel.
        $fuel = Ladder::suggestion($base(['credits' => 4000, 'ion_thruster' => 1]), [], [], false, 'expansionist');
        $this->assertSame('buy', $fuel['verb']);
        $this->assertSame('cryo_fuel', $fuel['args']['resource']);

        // Thruster + fuel → flight-ready; no heat_shield is needed for a
        // moon-first hop, so it heads for the elevator (it is standing on it).
        $ready = Ladder::suggestion($base(['credits' => 4000, 'ion_thruster' => 1, 'cryo_fuel' => 3]), [], [], false, 'expansionist');
        $this->assertSame('ride', $ready['verb']);

        // Low on credits, not fuelled → combine cryo_fuel from ice + an energy source.
        $poor = Ladder::suggestion($base(['ice' => 3, 'coal' => 3, 'ion_thruster' => 1]), [], [], false, 'expansionist');
        $this->assertSame('combine', $poor['verb']);
        $this->assertSame(['ice' => 1, 'coal' => 1], $poor['args']['ingredients']);
    }

    /**
     * The gate: an expansionist agent on the ground without a ship does NOT
     * `construct` a tower even when it holds the composite + metal for one —
     * the mission is the Accord, not a field of spires.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistWithoutAShipDoesNotBuildTowers(): void
    {
        $raw = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['composite' => 6, 'metal' => 20, 'credits' => 50],
            'nearby_deposits' => [], 'elevators' => [],
        ];
        $pick = Ladder::suggestion($raw, [], [], false, 'expansionist');
        $this->assertNotSame('construct', $pick['verb'] ?? null);

        // Homestead in the same spot still towers.
        $this->assertSame('construct', Ladder::suggestion($raw, [], [], false, 'homestead')['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistHeadsForTheElevatorWhenFlightReadyOnTheGround(): void
    {
        $onGround = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['ion_thruster' => 1, 'hydrogen' => 4],
            'elevators' => [['x' => 80, 'y' => 90]],
            'nearby_deposits' => [],
        ];
        $walk = Ladder::suggestion($onGround, [], [], false, 'expansionist');
        $this->assertSame('move', $walk['verb']);
        $this->assertSame(['x' => 80, 'y' => 90], $walk['args']);

        $onGround['position'] = [80, 90];
        $this->assertSame('ride', Ladder::suggestion($onGround, [], [], false, 'expansionist')['verb']);
    }
}
