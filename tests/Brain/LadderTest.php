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
     * The material hold window widens for what an upcoming craft needs and
     * relaxes once the parts are built.
     *
     * @covers \NHA\Brain\Ladder::shipMaterialPlan
     * @covers \NHA\Brain\Ladder::floorFor
     * @covers \NHA\Brain\Ladder::capFor
     */
    public function testTheHoldWindowTracksThePendingShipCraft(): void
    {
        $gearing = static fn(array $loose, array $inv): array => [
            'tick' => 1, 'in_space' => false, 'altitude' => 0,
            'stance' => 'expansionist', 'vehicles' => [],
            'loose_parts' => $loose, 'inventory' => $inv,
        ];

        // Not gearing (no ship objective) → defaults, no widening.
        $this->assertSame([], Ladder::shipMaterialPlan(['in_space' => false, 'altitude' => 0, 'inventory' => []]));
        $this->assertSame(Ladder::RESOURCE_TARGET, Ladder::floorFor('metal', []));
        $this->assertSame(Ladder::HOARD_CAP, Ladder::capFor('metal', []));

        // Gearing, ship barely started → metal floor climbs toward the bill (capped),
        // and the sell cap rises above the default so metal is not dumped.
        $early = Ladder::shipMaterialPlan($gearing(['frame'], ['credits' => 500]), true);
        $this->assertGreaterThan(Ladder::RESOURCE_TARGET, $early['metal']);
        $this->assertSame(Ladder::PLAN_FLOOR_CEIL, Ladder::floorFor('metal', $early));
        $this->assertGreaterThan(Ladder::HOARD_CAP, Ladder::capFor('metal', $early));

        // Most parts built + stock on hand → the bill shrinks, the floor relaxes.
        $late = Ladder::shipMaterialPlan($gearing(
            ['frame', 'cockpit', 'jet', 'engine', 'engine', 'engine', 'propeller', 'propeller', 'wing', 'wing', 'wing'],
            ['credits' => 500, 'metal' => 25, 'composite' => 3],
        ), true);
        $this->assertLessThan($early['metal'] ?? 0, $late['metal'] ?? 0);
        $this->assertSame(Ladder::RESOURCE_TARGET, Ladder::floorFor('metal', $late), 'floor back to default once enough metal is covered');
    }

    /**
     * A speculative research `combine` never spends a raw an upcoming ship
     * craft still needs, even when that raw sits above the research bar.
     *
     * @covers \NHA\Brain\Ladder::speculativeCombine
     */
    public function testSpeculativeCombineHoldsBackMaterialAPendingCraftNeeds(): void
    {
        $raws = ['metal' => 45, 'herb' => 45, 'lichen' => 45];

        // No plan → metal is fair game, the first pair wins.
        $free = Ladder::speculativeCombine($raws, [], []);
        $this->assertSame('combine', $free['verb']);
        $this->assertArrayHasKey('metal', $free['args']['ingredients']);

        // A flyer bill wants ~50 metal → metal drops out of the surplus and the
        // pair is drawn from the other two raws instead.
        $plan = ['metal' => 55];
        $held = Ladder::speculativeCombine($raws, [], [], $plan);
        $this->assertSame('combine', $held['verb']);
        $this->assertArrayNotHasKey('metal', $held['args']['ingredients'], 'metal is reserved for the ship');
        $this->assertSame(['herb' => 1, 'lichen' => 1], $held['args']['ingredients']);
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
            'vehicles' => [['name' => 'rover', 'deployed' => false, 'drives' => true]],
        ], [], [], false);

        $this->assertSame('deploy', $suggestion['verb']);

        // An inert hull (no drive, no flight) is NOT deployed — `deploy`
        // rejects it; the ladder moves on to other work.
        $dead = Ladder::suggestion([
            'tick' => 1,
            'inventory' => ['credits' => 100],
            'vehicles' => [['name' => 'ship_v1', 'drives' => false, 'flies' => false, 'fuel_cap' => 0]],
        ], [], [], false);
        $this->assertNotSame('deploy', $dead['verb'] ?? null);
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
            'inventory' => self::KIT + ['hydrogen' => 4, 'heat_shield' => 1],
            'vehicles' => [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => [
                'mars' => ['open' => true], 'venus' => ['open' => false],
            ]],
            'asteroids' => [],
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
        // Everything a flyer part could need on hand, so the craft chain is quiet.
        $stocked = ['metal' => 120, 'crystal' => 20, 'composite' => 10, 'chip' => 4, 'bearing' => 4, 'wire' => 8, 'ion_thruster' => 1, 'cryo_fuel' => 3, 'heat_shield' => 1, 'credits' => 8000];

        // A finished flyer bundle (cockpit + 3 engines + 2 propellers + 3 wings
        // + jet + fuel_tank) → finalize.
        $flyer = array_merge(
            ['frame', 'cockpit', 'jet', 'tail', 'fuel_tank', 'fuel_tank', 'landing_gear'],
            array_fill(0, 3, 'engine'),
            array_fill(0, 2, 'propeller'),
            array_fill(0, 3, 'wing'),
        );
        $fin = Ladder::suggestion(
            ['tick' => 5, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT, 'loose_parts' => $flyer],
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertSame('finalize', $fin['verb']);

        // Missing the propellers → NOT ready, keep building.
        $short = array_values(array_filter($flyer, static fn(string $p): bool => $p !== 'propeller'));
        $notReady = Ladder::suggestion(
            ['tick' => 5, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT + $stocked, 'loose_parts' => $short, 'position' => [10, 10], 'nearby_deposits' => []],
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertNotSame('finalize', $notReady['verb'] ?? null);

        // No `composite` on hand (wire already stocked) → the craft chain
        // combines composite for the light frame/wings.
        $needComposite = Ladder::suggestion(
            $base(['credits' => 4000, 'wire' => 8, 'aluminum' => 4, 'carbon' => 4]),
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertSame('combine', $needComposite['verb']);
        $this->assertSame(['aluminum' => 1, 'carbon' => 1], $needComposite['args']['ingredients']);

        // Fully stocked, empty bundle → build the composite `frame` FIRST.
        $frame = Ladder::suggestion(
            ['tick' => 5, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT + $stocked, 'loose_parts' => [], 'position' => [10, 10], 'nearby_deposits' => []],
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertSame('build', $frame['verb']);
        $this->assertSame('frame', $frame['args']['part']);
        $this->assertSame(['composite' => 1], $frame['args']['with']);

        // Frame + cockpit down → next is the ion_thruster jet.
        $jet = Ladder::suggestion(
            ['tick' => 5, 'in_space' => false, 'altitude' => 0, 'inventory' => self::KIT + $stocked, 'loose_parts' => ['frame', 'cockpit'], 'position' => [10, 10], 'nearby_deposits' => []],
            [],
            [],
            false,
            'expansionist',
        );
        $this->assertSame('build', $jet['verb']);
        $this->assertSame('jet', $jet['args']['part']);
        $this->assertSame(['ion_thruster' => 1], $jet['args']['with']);

        // Stocked but not fuelled → buy cryo_fuel.
        $fuel = Ladder::suggestion($base(['credits' => 4000, 'composite' => 8, 'chip' => 2, 'bearing' => 3, 'wire' => 6, 'ion_thruster' => 1]), [], [], false, 'expansionist');
        $this->assertSame('buy', $fuel['verb']);
        $this->assertSame('cryo_fuel', $fuel['args']['resource']);

        // A finalized flyer + a transfer-sized fuel reserve, standing on a tall
        // elevator → NOW ride up.
        $ready = $base(['credits' => 4000, 'cryo_fuel' => 95]);
        $ready['vehicles'] = [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]];
        $ready['elevators'] = [['x' => 10, 'y' => 10, 'height' => 500]];
        $this->assertSame('ride', Ladder::suggestion($ready, [], [], false, 'expansionist')['verb']);
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

        // A shipless expansionist NEVER builds towers — not even when ship
        // assembly has stalled (17 inert hulls and counting). The mission is
        // research + the flight, not a field of spires.
        $stalled = $raw;
        $stalled['vehicles'] = array_fill(0, 6, ['name' => 'hull', 'drives' => false, 'flies' => false, 'fuel_cap' => 0]);
        $this->assertNotSame('construct', Ladder::suggestion($stalled, [], [], false, 'expansionist')['verb'] ?? null);

        // Homestead in the same spot still towers (it is not the mission stance).
        $this->assertSame('construct', Ladder::suggestion($raw, [], [], false, 'homestead')['verb']);
    }

    /**
     * A `finalize`d ship counts as flight-ready even though its `ion_thruster`
     * was consumed into the vehicle — and a ship in orbit with no open window
     * HOLDS instead of landing back to Earth.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     * @covers \NHA\Brain\Ladder::hasOrbitalShip
     */
    public function testAFinalizedShipInOrbitDepartsOnAWindowAndOtherwiseHolds(): void
    {
        $orbit = static fn(array $windows): array => [
            'tick' => 5, 'in_space' => true, 'altitude' => 400,
            'inventory' => self::KIT + ['cryo_fuel' => 4],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => $windows],
            'asteroids' => [],
        ];

        // Deimos window open → depart (no ion_thruster in inventory).
        $go = Ladder::suggestion($orbit(['deimos' => ['open' => true]]), [], [], false, 'expansionist');
        $this->assertSame('depart', $go['verb']);
        $this->assertSame('deimos', $go['args']['dest']);

        // No window → it must NOT `land` (rung 2b is suppressed for a ship holding orbit).
        $wait = Ladder::suggestion($orbit(['deimos' => ['open' => false]]), [], [], false, 'expansionist');
        $this->assertNotSame('land', $wait['verb'] ?? null);
    }

    /**
     * The "wait for a launch window" hold: a depart-capable ship parked in
     * Earth orbit with every window shut stocks the transfer fuel first, then a
     * shield, then idles — it never thrashes mine/move/land up there.
     *
     * @covers \NHA\Brain\Ladder::isHoldingForWindow
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testFlightReadyShipInOrbitHoldsProductivelyForAWindow(): void
    {
        $orbit = static fn(array $inv): array => [
            'tick' => 5, 'in_space' => true, 'altitude' => 420,
            'inventory' => self::KIT + $inv,
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => ['deimos' => ['open' => false], 'mars' => ['open' => false]]],
            'asteroids' => [],
        ];

        // isHoldingForWindow: true here, false the moment a window opens.
        $held = $orbit(['credits' => 4000, 'cryo_fuel' => 2, 'heat_shield' => 1]);
        $this->assertTrue(Ladder::isHoldingForWindow($held, 'expansionist'));
        $open = $held;
        $open['expansion']['windows']['deimos'] = ['open' => true];
        $this->assertFalse(Ladder::isHoldingForWindow($open, 'expansionist'));
        $this->assertFalse(Ladder::isHoldingForWindow($held, 'homestead'));

        // Under-fuelled + credits → stock cryo_fuel toward the transfer reserve.
        $buyFuel = Ladder::suggestion($held, [], [], false, 'expansionist');
        $this->assertSame('buy', $buyFuel['verb']);
        $this->assertSame('cryo_fuel', $buyFuel['args']['resource']);

        // Fuelled + shielded + no asteroid → a real idle (deposit), never land / mine.
        $idle = Ladder::suggestion($orbit(['credits' => 4000, 'cryo_fuel' => 120, 'heat_shield' => 1]), [], [], false, 'expansionist');
        $this->assertContains($idle['verb'], ['deposit', 'move']);
    }

    /**
     * A ship on the ground that is under-fuelled tops the tank up before it
     * rides the elevator — reaching orbit on fumes just parks it.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testGroundedShipStocksFuelBeforeRidingUp(): void
    {
        $onGround = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['credits' => 4000, 'cryo_fuel' => 5],
            'vehicles' => [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]],
            'elevators' => [['x' => 10, 'y' => 10, 'height' => 500]],
            'nearby_deposits' => [],
        ];
        $pick = Ladder::suggestion($onGround, [], [], false, 'expansionist');
        $this->assertSame('buy', $pick['verb']);
        $this->assertSame('cryo_fuel', $pick['args']['resource']);
    }

    /**
     * @covers \NHA\Brain\Ladder::departTarget
     */
    public function testDepartTargetPicksTheCheapestOpenShieldedWindow(): void
    {
        $orbit = static fn(array $windows, array $inv): array => [
            'in_space' => true, 'altitude' => 420,
            'inventory' => $inv,
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'windows' => $windows],
        ];

        // deimos + mars open, fuelled → deimos (cheapest, no shield needed).
        $this->assertSame('deimos', Ladder::departTarget($orbit(
            ['deimos' => ['open' => true], 'mars' => ['open' => true]],
            ['cryo_fuel' => 80],
        )));

        // only mars open, but no heat_shield → null (cannot service it).
        $this->assertNull(Ladder::departTarget($orbit(
            ['deimos' => ['open' => false], 'mars' => ['open' => true]],
            ['cryo_fuel' => 80],
        )));
        // …with the shield → mars.
        $this->assertSame('mars', Ladder::departTarget($orbit(
            ['mars' => ['open' => true]],
            ['cryo_fuel' => 80, 'heat_shield' => 1],
        )));

        // no fuel, or no open window, or on the ground → null.
        $this->assertNull(Ladder::departTarget($orbit(['deimos' => ['open' => true]], ['cryo_fuel' => 0])));
        $this->assertNull(Ladder::departTarget($orbit(['deimos' => ['open' => false]], ['cryo_fuel' => 80])));
        $ground = $orbit(['deimos' => ['open' => true]], ['cryo_fuel' => 80]);
        $ground['in_space'] = false;
        $ground['altitude'] = 0;
        $this->assertNull(Ladder::departTarget($ground));
    }

    /**
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testExpansionistHeadsForTheElevatorWhenFlightReadyOnTheGround(): void
    {
        $onGround = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['hydrogen' => 95],
            'vehicles' => [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]],
            // A 120 m spire does NOT reach orbit — only the tall one counts.
            'elevators' => [['x' => 12, 'y' => 12, 'height' => 120], ['x' => 80, 'y' => 90, 'height' => 620]],
            'nearby_deposits' => [],
        ];
        $walk = Ladder::suggestion($onGround, [], [], false, 'expansionist');
        $this->assertSame('move', $walk['verb']);
        $this->assertSame(['x' => 80, 'y' => 90], $walk['args']);

        $onGround['position'] = [80, 90];
        $this->assertSame('ride', Ladder::suggestion($onGround, [], [], false, 'expansionist')['verb']);
    }

    /**
     * With a finalized ship + fuel but no elevator that reaches orbit, the
     * expansionist `launch`es toward 300+ rather than stalling on the ground.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     * @covers \NHA\Brain\Ladder::orbitElevator
     */
    public function testExpansionistLaunchesWhenNoElevatorReachesOrbit(): void
    {
        $onGround = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['hydrogen' => 95],
            'vehicles' => [['name' => 'runner', 'flies' => true, 'orbital_engine' => true]],
            'elevators' => [['x' => 12, 'y' => 12, 'height' => 120]],
            'nearby_deposits' => [],
        ];
        $this->assertSame('launch', Ladder::suggestion($onGround, [], [], false, 'expansionist')['verb']);
    }

    /**
     * In space with only a loose `ion_thruster` resource and no finalized ship,
     * the agent does NOT `land` (always rejected) — it rides the elevator down
     * or waits out orbital decay to gear up on the ground.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     * @covers \NHA\Brain\Ladder::descentWithoutShip
     */
    public function testShiplessInSpaceComesDownInsteadOfLanding(): void
    {
        $inSpace = [
            'tick' => 5, 'in_space' => true, 'altitude' => 120, 'position' => [33, 114],
            'inventory' => self::KIT + ['ion_thruster' => 2, 'cryo_fuel' => 3],
            'elevators' => [['x' => 33, 'y' => 114, 'height' => 120]],
            'expansion' => ['at_body' => null],
            'asteroids' => [],
        ];
        $pick = Ladder::suggestion($inSpace, [], [], false, 'expansionist');
        $this->assertSame('ride', $pick['verb']);

        // Off the elevator base → walk to it, still never `land`.
        $inSpace['position'] = [40, 120];
        $this->assertContains(Ladder::suggestion($inSpace, [], [], false, 'expansionist')['verb'], ['move', 'wait']);
    }
}
