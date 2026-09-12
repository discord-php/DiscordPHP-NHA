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
        // Arrived: `at_body_orbit` set, `at_body` still null (until land_body).
        $inOrbitOfMars = [
            'tick' => 5, 'in_space' => true, 'altitude' => 320,
            'inventory' => self::KIT + ['credits' => 500],
            'expansion' => ['at_body' => null, 'at_body_orbit' => 'mars'],
        ];
        $pick = Ladder::suggestion($inOrbitOfMars, [], [], false, 'expansionist');
        $this->assertSame('land_body', $pick['verb']);

        $atMoon = $inOrbitOfMars;
        $atMoon['expansion']['at_body_orbit'] = 'moon';
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
     * @covers \NHA\Brain\Ladder::parseNeed
     */
    public function testParseNeedPullsTheRequirementMapOutOfARejection(): void
    {
        $this->assertSame(
            ['metal' => 80, 'chip' => 10],
            Ladder::parseNeed("insufficient for a Regolith Cracker (need {'metal': 80, 'chip': 10})"),
        );
        $this->assertSame(['metal' => 80, 'chip' => 10], Ladder::parseNeed('need 80 metal, 10 chip'));
        $this->assertSame(['metal' => 80], Ladder::parseNeed('need metal 80'));
        $this->assertSame([], Ladder::parseNeed('a structure already stands on this cell'));
    }

    /**
     * @covers \NHA\Brain\Ladder::bodyBuildStep
     */
    public function testBodyBuildStepBuysTheDepotRawItIsShortOn(): void
    {
        $step = Ladder::bodyBuildStep(
            ['credits' => 25000, 'metal' => 33, 'chip' => 10, 'silicon' => 60, 'copper' => 60],
            ['metal' => 80, 'chip' => 10],
        );
        $this->assertNotNull($step);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('metal', $step['args']['resource']);
    }

    /**
     * @covers \NHA\Brain\Ladder::bodyBuildStep
     */
    public function testBodyBuildStepCraftsChipsFromSiliconAndAConductor(): void
    {
        $step = Ladder::bodyBuildStep(
            ['credits' => 25000, 'metal' => 200, 'chip' => 0, 'silicon' => 60, 'copper' => 60],
            ['metal' => 80, 'chip' => 10],
        );
        $this->assertNotNull($step);
        $this->assertSame('combine', $step['verb']);
        $this->assertSame(['silicon' => 1, 'copper' => 1], $step['args']['ingredients']);
        $this->assertSame(10, $step['args']['n'], 'a known recipe crafts the whole shortfall in one intent');
    }

    /**
     * @covers \NHA\Brain\Ladder::bodyBuildStep
     */
    public function testBodyBuildStepIsNullWhenEveryRequirementIsMet(): void
    {
        $this->assertNull(Ladder::bodyBuildStep(
            ['credits' => 25000, 'metal' => 90, 'chip' => 12],
            ['metal' => 80, 'chip' => 10],
        ));
    }

    // ── Colony board — fund the next module, cap the extractors ────────

    /** A trimmed Deimos "Forward Base" board: 3 modules done, Mass Driver open. */
    private static function deimosBoard(): array
    {
        return [
            'body' => 'deimos',
            'cap_pct_per_agent' => 60,
            'complete' => false,
            'modules' => [
                ['module' => 'depot', 'complete' => true, 'need' => ['titanium' => 120], 'remaining' => [], 'contrib' => []],
                [
                    'module' => 'mass_driver', 'label' => 'Mass Driver', 'complete' => false,
                    'need' => ['superalloy' => 160, 'nickel' => 120, 'chip' => 80, 'c_regolith' => 100],
                    'have' => ['superalloy' => 1, 'nickel' => 0, 'chip' => 80, 'c_regolith' => 100],
                    'remaining' => ['superalloy' => 159, 'nickel' => 120],
                    'contrib' => ['142285' => ['superalloy' => 1, 'c_regolith' => 40]],
                ],
            ],
            'extractors' => array_fill(0, 12, ['id' => 1, 'kind' => 'cregolith_cracker', 'owner' => 142285]),
        ];
    }

    /**
     * @covers \NHA\Brain\Ladder::colonyNextModule
     * @covers \NHA\Brain\Ladder::colonyAgentHeadroom
     */
    public function testColonyHeadroomIsRemainingCappedByThePerAgentShareMinusOwnContribution(): void
    {
        $module = Ladder::colonyNextModule(self::deimosBoard());
        $this->assertSame('mass_driver', $module['module']);

        // 60% of 160 = 96 cap, already funded 1 → 95 left; 60% of 120 = 72, funded 0 → 72.
        $this->assertSame(['superalloy' => 95, 'nickel' => 72], Ladder::colonyAgentHeadroom($module, 142285, 60));

        // An agent who has already maxed its 60% share has no headroom.
        $module['contrib']['999'] = ['superalloy' => 96, 'nickel' => 72];
        $this->assertSame([], Ladder::colonyAgentHeadroom($module, 999, 60));
    }

    /**
     * @covers \NHA\Brain\Ladder::colonyFundStep
     */
    public function testColonyFundStepFundsWithAHeldMaterialElseBuysTheOutstandingOne(): void
    {
        $board = self::deimosBoard();

        // Holds superalloy → construct the colony module (consumes the stock).
        $held = Ladder::colonyFundStep($board, 142285, ['credits' => 15000, 'superalloy' => 30, 'nickel' => 0]);
        $this->assertSame('construct', $held['verb']);
        $this->assertSame(['shape' => 'colony', 'body' => 'deimos', 'module' => 'mass_driver'], $held['args']);

        // Holds neither, has credits → buy the one with the most headroom (superalloy, 95 > 72).
        $buy = Ladder::colonyFundStep($board, 142285, ['credits' => 15000, 'superalloy' => 0, 'nickel' => 0]);
        $this->assertSame('buy', $buy['verb']);
        $this->assertSame('superalloy', $buy['args']['resource']);
        $this->assertLessThanOrEqual(40, $buy['args']['n']);
    }

    /**
     * @covers \NHA\Brain\Ladder::colonyFundStep
     */
    public function testColonyFundStepIsNullOnACompleteColonyOrACappedAgent(): void
    {
        $done = self::deimosBoard();
        $done['complete'] = true;
        $this->assertNull(Ladder::colonyFundStep($done, 142285, ['credits' => 9999]));

        $capped = self::deimosBoard();
        $capped['modules'][1]['contrib']['142285'] = ['superalloy' => 96, 'nickel' => 72];
        $this->assertNull(Ladder::colonyFundStep($capped, 142285, ['credits' => 9999, 'superalloy' => 50]));
    }

    /**
     * The co-op ask only goes out when the board is blocked ON SOMEONE ELSE:
     * an outstanding line this agent has no cap headroom left on. While we can
     * still fund it ourselves, funding beats asking.
     *
     * @covers \NHA\Brain\Ladder::colonyCallForHelp
     */
    public function testColonyCallForHelpOnlyFiresOnceOurOwnShareIsSpent(): void
    {
        // Headroom left (contributed 1 of a 96 cap) → fund it, don't ask.
        $this->assertNull(Ladder::colonyCallForHelp(self::deimosBoard(), 142285));

        // Complete board → nothing to ask for.
        $done = self::deimosBoard();
        $done['complete'] = true;
        $this->assertNull(Ladder::colonyCallForHelp($done, 142285));

        // Capped on both outstanding lines → only another funder can close it.
        $capped = self::deimosBoard();
        $capped['modules'][1]['contrib']['142285'] = ['superalloy' => 96, 'nickel' => 72];
        $capped['modules'][1]['remaining'] = ['superalloy' => 64, 'nickel' => 48];
        $call = Ladder::colonyCallForHelp($capped, 142285);

        $this->assertNotNull($call);
        $this->assertSame('say', $call['verb']);
        $text = (string) $call['args']['text'];
        $this->assertLessThanOrEqual(280, mb_strlen($text), 'the engine caps `say` at 280 chars');
        // It has to be actionable by another AGENT, so it names the body, the
        // module, the exact shortfall and both verbs that close it.
        $this->assertStringContainsString('mass_driver', $text);
        $this->assertStringContainsString('64 superalloy', $text);
        $this->assertStringContainsString('48 nickel', $text);
        $this->assertStringContainsString('construct{shape:colony,body:deimos', $text);
        $this->assertStringContainsString('invest{body:deimos', $text);
    }

    /**
     * A line we are NOT capped on is our own job — it must not be begged for
     * even when a sibling line on the same module is capped.
     *
     * @covers \NHA\Brain\Ladder::colonyCallForHelp
     */
    public function testColonyCallForHelpAsksOnlyForTheLinesItCannotFundItself(): void
    {
        $board = self::deimosBoard();
        // Capped on superalloy, untouched on nickel.
        $board['modules'][1]['contrib']['142285'] = ['superalloy' => 96];
        $board['modules'][1]['remaining'] = ['superalloy' => 64, 'nickel' => 120];

        $call = Ladder::colonyCallForHelp($board, 142285);

        $this->assertNotNull($call);
        $text = (string) $call['args']['text'];
        $this->assertStringContainsString('64 superalloy', $text);
        $this->assertStringNotContainsString('nickel', $text, 'we still have nickel headroom — fund it, do not ask');
    }

    /**
     * @covers \NHA\Brain\Ladder::ownedExtractors
     */
    public function testOwnedExtractorsCountsOnlyThisAgents(): void
    {
        $board = self::deimosBoard();
        $board['extractors'][] = ['id' => 2, 'kind' => 'cregolith_cracker', 'owner' => 999];
        $this->assertSame(12, Ladder::ownedExtractors($board, 142285));
        $this->assertSame(1, Ladder::ownedExtractors($board, 999));
        $this->assertSame(0, Ladder::ownedExtractors([], 142285));
    }

    /**
     * The live bankruptcy this replaces: `buy {cryo_fuel, n:30}` behind a flat
     * `$credits >= 60` guard. cryo_fuel is 16/unit, so that order costs 480 —
     * with 158 credits the agent cleared the guard, the engine refused, and the
     * identical buy re-fired 419 times in three hours (after the ~15 that DID
     * land drained ~7,000 credits).
     *
     * @covers \NHA\Brain\Ladder::affordableBuy
     */
    public function testAffordableBuyIsSizedToCreditsAtTheRealDepotPrice(): void
    {
        // 158 credits at 16/unit → 9 units, not a 480-credit order that fails.
        $step = Ladder::affordableBuy('cryo_fuel', 30, 158);
        $this->assertNotNull($step);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('cryo_fuel', $step['args']['resource']);
        $this->assertSame(9, $step['args']['n']);

        // Never more than asked for, however rich.
        $this->assertSame(30, Ladder::affordableBuy('cryo_fuel', 30, 999999)['args']['n']);

        // Cannot afford a single unit → null, so the caller falls through to
        // earning instead of spinning on a rejected order.
        $this->assertNull(Ladder::affordableBuy('cryo_fuel', 30, 15));
        $this->assertNull(Ladder::affordableBuy('cryo_fuel', 30, 0));

        // Unknown price → null rather than a guess.
        $this->assertNull(Ladder::affordableBuy('c_regolith', 10, 9999));
    }

    // ── acquire() — get a resource by where it actually is ────────────

    /**
     * @covers \NHA\Brain\Ladder::acquire
     */
    public function testAcquireMinesADepositUnderfootBeforeSpendingCredits(): void
    {
        $raw = ['position' => [10, 10], 'nearby_deposits' => [['resource' => 'nickel', 'dist' => 0, 'amount' => 30]]];
        $step = Ladder::acquire('nickel', 48, $raw, ['credits' => 20000]);

        $this->assertSame('mine', $step['verb'], 'nickel is underfoot — mine it, do not buy it');
        $this->assertLessThanOrEqual(15, $step['args']['n']);
    }

    /**
     * @covers \NHA\Brain\Ladder::acquire
     */
    public function testAcquireWalksToADepositThatIsNearbyButNotUnderfoot(): void
    {
        $raw = ['position' => [10, 10], 'nearby_deposits' => [['resource' => 'iron', 'dist' => 6, 'x' => 16, 'y' => 10, 'amount' => 40]]];
        $step = Ladder::acquire('iron', 20, $raw, ['credits' => 20000]);

        $this->assertSame('move', $step['verb']);
        $this->assertSame(['x' => 16, 'y' => 10], $step['args']);
    }

    /**
     * @covers \NHA\Brain\Ladder::acquire
     */
    public function testAcquireFallsBackToTheDepotWhenNothingIsMinableLocally(): void
    {
        $step = Ladder::acquire('superalloy', 90, ['position' => [0, 0]], ['credits' => 5000]);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('superalloy', $step['args']['resource']);
        $this->assertSame(40, $step['args']['n'], 'capped per turn');

        // …and a deposit too far to walk to is ignored in favour of the depot.
        $far = ['position' => [0, 0], 'nearby_deposits' => [['resource' => 'superalloy', 'dist' => 80, 'x' => 80, 'y' => 0]]];
        $this->assertSame('buy', Ladder::acquire('superalloy', 90, $far, ['credits' => 5000])['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::acquire
     */
    public function testAcquireDocksAnAsteroidForAMetalWithNoDepositAndNoDepotCredits(): void
    {
        $raw = ['position' => [0, 0], 'asteroids' => [['dist' => 1]]];
        $step = Ladder::acquire('iridium', 10, $raw, ['credits' => 0]);
        $this->assertSame('dock', $step['verb']);
    }

    /**
     * @covers \NHA\Brain\Ladder::onBodySurface
     */
    public function testOnBodySurfaceUsesPlaceWhereNotAltitudeForMoons(): void
    {
        // A moon: the engine reports in_space + a non-zero altitude on the ground.
        $this->assertTrue(Ladder::onBodySurface([
            'in_space' => true, 'altitude' => 480,
            'expansion' => ['at_body' => 'deimos', 'place' => ['where' => 'body_surface']],
        ]));
        // `location: on_<body>` says the same.
        $this->assertTrue(Ladder::onBodySurface(['expansion' => ['location' => 'on_mars']]));
        // …and an older observation with just `at_body` still resolves.
        $this->assertTrue(Ladder::onBodySurface(['expansion' => ['at_body' => 'phobos']]));

        // In a body's ORBIT (at_body_orbit, at_body unset) is NOT the surface.
        $this->assertFalse(Ladder::onBodySurface(['expansion' => ['at_body' => null, 'at_body_orbit' => 'deimos']]));
        // Nor is mid-transit, nor Earth's ground.
        $this->assertFalse(Ladder::onBodySurface(['expansion' => ['transit' => ['to' => 'mars'], 'at_body' => 'mars']]));
        $this->assertFalse(Ladder::onBodySurface(['in_space' => false, 'altitude' => 0, 'expansion' => []]));
    }

    /**
     * @covers \NHA\Brain\Ladder::acquire
     */
    public function testAcquireIsNullWhenTheResourceCannotBeGotHere(): void
    {
        $this->assertNull(Ladder::acquire('void_pumice', 10, ['position' => [0, 0]], ['credits' => 0]));
        $this->assertNull(Ladder::acquire('nickel', 0, ['position' => [0, 0]], ['credits' => 9999]), 'already have enough');
    }

    // ── acidSkinStep() — the Venus arrival-item chain ──────────────────

    /**
     * @covers \NHA\Brain\Ladder::acidSkinStep
     */
    public function testAcidSkinStepIsNullOnceHeld(): void
    {
        $this->assertNull(Ladder::acidSkinStep(['acid_skin' => 1], 9999));
    }

    /**
     * @covers \NHA\Brain\Ladder::acidSkinStep
     */
    public function testAcidSkinStepClimbsTheChainBottomUpAsPrecursorsArrive(): void
    {
        // Nothing at all, but credits → buy oil first (plastic's first missing raw).
        $step = Ladder::acidSkinStep([], 9999);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('oil', $step['args']['resource']);

        // oil in hand, carbon still missing → buy carbon.
        $step = Ladder::acidSkinStep(['oil' => 6], 9999);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('carbon', $step['args']['resource']);

        // oil + carbon on hand → combine plastic.
        $step = Ladder::acidSkinStep(['oil' => 6, 'carbon' => 6], 9999);
        $this->assertSame('combine', $step['verb']);
        $this->assertSame(['oil' => 1, 'carbon' => 1], $step['args']['ingredients']);

        // plastic made, no sulfur yet → buy sulfur (for rubber).
        $step = Ladder::acidSkinStep(['plastic' => 6], 9999);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('sulfur', $step['args']['resource']);

        // sulfur + plastic → combine rubber.
        $step = Ladder::acidSkinStep(['plastic' => 6, 'sulfur' => 6], 9999);
        $this->assertSame('combine', $step['verb']);
        $this->assertSame(['sulfur' => 1, 'plastic' => 1], $step['args']['ingredients']);

        // rubber made, no water yet (for acid) → buy water.
        $step = Ladder::acidSkinStep(['rubber' => 6, 'sulfur' => 6], 9999);
        $this->assertSame('buy', $step['verb']);
        $this->assertSame('water', $step['args']['resource']);

        // sulfur + water → combine acid.
        $step = Ladder::acidSkinStep(['rubber' => 6, 'sulfur' => 6, 'water' => 6], 9999);
        $this->assertSame('combine', $step['verb']);
        $this->assertSame(['sulfur' => 1, 'water' => 1], $step['args']['ingredients']);

        // acid + rubber both held → the final combine.
        $step = Ladder::acidSkinStep(['acid' => 1, 'rubber' => 1], 9999);
        $this->assertSame('combine', $step['verb']);
        $this->assertSame(['acid' => 1, 'rubber' => 1], $step['args']['ingredients']);
    }

    /**
     * @covers \NHA\Brain\Ladder::acidSkinStep
     */
    public function testAcidSkinStepIsNullWhenBrokeAndMissingRaws(): void
    {
        $this->assertNull(Ladder::acidSkinStep([], 0));
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
    /**
     * `bearing` can't be obtained in the live world, so nothing chases it:
     * propellers have no upgrade and `shipCraftStep` never returns a bearing
     * step even when the bundle wants propellers.
     *
     * @covers \NHA\Brain\Ladder::shipCraftStep
     */
    /**
     * A departed agent riding the transfer: no hold, no re-depart — just wait.
     *
     * @covers \NHA\Brain\Ladder::inTransit
     */
    /**
     * Arrived in a body's ORBIT (`at_body_orbit`, `at_body` still null) — land,
     * don't keep holding for an Earth transfer window.
     *
     * @covers \NHA\Brain\Ladder::atBody
     */
    public function testArrivedInBodyOrbitLandsRatherThanHolds(): void
    {
        $arrived = [
            'tick' => 5, 'in_space' => true, 'altitude' => 600,
            'inventory' => self::KIT + ['cryo_fuel' => 90, 'heat_shield' => 1],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'at_body_orbit' => 'deimos', 'location' => 'orbit_deimos',
                'windows' => ['deimos' => ['open' => true]]],
        ];

        $this->assertSame('deimos', Ladder::atBody($arrived));
        $this->assertFalse(Ladder::isHoldingForWindow($arrived, 'expansionist'));
        $this->assertNull(Ladder::departTarget($arrived));
        $this->assertSame('land_body', Ladder::suggestion($arrived, [], [], false, 'expansionist')['verb']);
    }

    public function testInTransitStopsTheHoldAndDepartGates(): void
    {
        $transit = [
            'tick' => 5, 'in_space' => true, 'altitude' => 600,
            'inventory' => self::KIT + ['cryo_fuel' => 90, 'heat_shield' => 1],
            'vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]],
            'expansion' => ['at_body' => null, 'transit' => ['to' => 'deimos', 'eta_tick' => 99, 'eta_in' => 30],
                'windows' => ['deimos' => ['open' => true]]],
        ];

        $this->assertTrue(Ladder::inTransit($transit));
        $this->assertNull(Ladder::departTarget($transit), 'cannot depart mid-crossing');
        $this->assertFalse(Ladder::isHoldingForWindow($transit, 'expansionist'), 'not holding — already left');

        $pick = Ladder::suggestion($transit, [], [], false, 'expansionist');
        $this->assertNotSame('depart', $pick['verb'] ?? null);
        $this->assertStringContainsString('deimos', (string) ($pick['why'] ?? ''));
    }

    public function testTheUnobtainableBearingUpgradeIsNotChased(): void
    {
        $this->assertArrayNotHasKey('propeller', Ladder::SHIP_PART_UPGRADE);

        // Everything else satisfied, no bearing → craft step is done (null),
        // never a bearing combine or buy.
        $step = Ladder::shipCraftStep(['composite' => 6, 'chip' => 2, 'wire' => 6, 'ion_thruster' => 1, 'bearing' => 0, 'metal' => 20, 'oil' => 10, 'credits' => 5000]);
        $this->assertNull($step);
    }

    public function testExpansionistGearsTheShipBeforeRidingUp(): void
    {
        $base = static fn(array $extra): array => [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + $extra,
            'elevators' => [['x' => 10, 'y' => 10]], 'nearby_deposits' => [],
        ];
        // Everything a flyer part could need on hand, so the craft chain is quiet.
        $stocked = ['metal' => 120, 'crystal' => 20, 'composite' => 10, 'chip' => 4, 'bearing' => 4, 'wire' => 8, 'ion_thruster' => 1, 'cryo_fuel' => 3, 'heat_shield' => 1, 'acid_skin' => 1, 'credits' => 8000];

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
     * A hull that has been `depart`-rejected for every body (gearless moons /
     * Mars, low-TWR Venus) is a dead end: `hasDepartCapableShip()` goes false,
     * and an on-ground expansionist gears a fresh flyer instead of holding.
     *
     * @covers \NHA\Brain\Ladder::hasDepartCapableShip
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testAShipRejectedForEveryBodyIsADeadEndAndTriggersARebuild(): void
    {
        $ship = ['vehicles' => [['name' => 'skiff', 'flies' => true, 'orbital_engine' => true]]];
        $all = ['deimos', 'phobos', 'mars', 'venus'];

        $this->assertTrue(Ladder::hasDepartCapableShip($ship));
        $this->assertTrue(Ladder::hasDepartCapableShip($ship, ['deimos', 'phobos']), 'mars still open');
        $this->assertFalse(Ladder::hasDepartCapableShip($ship, $all), 'nowhere left to go');
        // The real stall: the three gear bodies are all rejected but venus was
        // never listed (departTarget skips it — no acid_skin), so the old
        // all-of-DEPART_ORDER test kept the dead hull looking "capable".
        $this->assertFalse(Ladder::hasDepartCapableShip($ship, ['deimos', 'phobos', 'mars']), 'venus does not keep a gearless hull alive');
        $this->assertFalse(Ladder::hasDepartCapableShip(['vehicles' => []], []), 'no ship at all');

        // On the ground with the dead-end ship + parts stock → GEAR UP a new
        // flyer (build / craft a part), not hold or ride.
        $grounded = [
            'tick' => 5, 'in_space' => false, 'altitude' => 0, 'position' => [10, 10],
            'inventory' => self::KIT + ['metal' => 120, 'credits' => 8000, 'composite' => 8, 'chip' => 2, 'bearing' => 3, 'wire' => 6, 'ion_thruster' => 1, 'cryo_fuel' => 95],
            'vehicles' => [['name' => 'deadend', 'flies' => true, 'orbital_engine' => true]],
            'loose_parts' => [], 'elevators' => [['x' => 10, 'y' => 10, 'height' => 500]], 'nearby_deposits' => [],
        ];
        $pick = Ladder::suggestion($grounded, [], [], false, 'expansionist', $all);
        $this->assertContains($pick['verb'], ['build', 'combine', 'buy'], 'rebuilding the flyer, not riding up');
    }

    /**
     * `flyerReady()` requires a `landing_gear` part now — a bundle without one
     * still `flies` but every moon / Mars `depart` would be rejected, so it is
     * not "ready" to finalize.
     *
     * @covers \NHA\Brain\Ladder::flyerReady
     */
    public function testFlyerReadyRequiresLandingGear(): void
    {
        $full = array_merge(
            ['frame', 'cockpit', 'jet', 'tail', 'fuel_tank', 'landing_gear'],
            array_fill(0, 3, 'engine'),
            array_fill(0, 2, 'propeller'),
            array_fill(0, 3, 'wing'),
        );
        $this->assertTrue(Ladder::flyerReady($full));

        $noGear = array_values(array_filter($full, static fn(string $p): bool => $p !== 'landing_gear'));
        $this->assertFalse(Ladder::flyerReady($noGear));
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

        // isHoldingForWindow: true here, false the moment a SERVICEABLE window opens.
        $held = $orbit(['credits' => 4000, 'cryo_fuel' => 2, 'heat_shield' => 1]);
        $this->assertTrue(Ladder::isHoldingForWindow($held, 'expansionist'));
        $open = $held;
        $open['expansion']['windows']['deimos'] = ['open' => true];
        $open['inventory']['cryo_fuel'] = 80;
        $this->assertFalse(Ladder::isHoldingForWindow($open, 'expansionist'));
        $this->assertFalse(Ladder::isHoldingForWindow($held, 'homestead'));

        // A window that is open but UNSERVICEABLE (Venus, no acid_skin) still holds.
        $venusOnly = $held;
        $venusOnly['expansion']['windows'] = ['venus' => ['open' => true]];
        $venusOnly['inventory']['cryo_fuel'] = 120;
        $this->assertTrue(Ladder::isHoldingForWindow($venusOnly, 'expansionist'));

        // Still holding while decaying through the space tier (alt 100–299) —
        // the ~2/tick sink to the ground must not fall through to churn.
        $sinking = $held;
        $sinking['altitude'] = 180;
        $this->assertTrue(Ladder::isHoldingForWindow($sinking, 'expansionist'));
        $this->assertContains(Ladder::suggestion($sinking, [], [], false, 'expansionist')['verb'], ['deposit', 'move', 'buy']);
        // Below the space tier → on its way to the ground, no longer "holding".
        $sinking['altitude'] = 60;
        $this->assertFalse(Ladder::isHoldingForWindow($sinking, 'expansionist'));

        // STATION-KEEP: dropped below the 300 depart floor with a tall elevator
        // on the cell → bounce it (ride down, the on-ground rung rides back up)
        // rather than idle straight past the band while a window is shut.
        $keep = $orbit(['credits' => 4000, 'cryo_fuel' => 120, 'heat_shield' => 1, 'acid_skin' => 1]);
        $keep['altitude'] = 250;
        $keep['position'] = [30, 110];
        $keep['elevators'] = [['id' => 1, 'x' => 30, 'y' => 110, 'height' => 680, 'dist' => 0]];
        $this->assertSame('ride', Ladder::suggestion($keep, [], [], false, 'expansionist')['verb']);
        // Comfortably in the band → no bounce, just idle.
        $keep['altitude'] = 480;
        $this->assertContains(Ladder::suggestion($keep, [], [], false, 'expansionist')['verb'], ['deposit', 'move']);

        // Under-fuelled + credits → stock cryo_fuel toward the transfer reserve.
        $buyFuel = Ladder::suggestion($held, [], [], false, 'expansionist');
        $this->assertSame('buy', $buyFuel['verb']);
        $this->assertSame('cryo_fuel', $buyFuel['args']['resource']);

        // Fuelled + shielded + no asteroid → a real idle (deposit), never land / mine.
        $idle = Ladder::suggestion($orbit(['credits' => 4000, 'cryo_fuel' => 120, 'heat_shield' => 1, 'acid_skin' => 1]), [], [], false, 'expansionist');
        $this->assertContains($idle['verb'], ['deposit', 'move']);

        // An asteroid in view but OUT of dock range → idle, not a `dock` that
        // would miss every turn (loop-break is suppressed here).
        $far = $orbit(['credits' => 4000, 'cryo_fuel' => 120, 'heat_shield' => 1, 'acid_skin' => 1]);
        $far['asteroids'] = [['id' => 1, 'x' => 40, 'y' => 40, 'dist' => 20]];
        $this->assertContains(Ladder::suggestion($far, [], [], false, 'expansionist')['verb'], ['deposit', 'move']);

        // …within range → dock it. The live engine accepted a dock at dist 6
        // (intent #3415083), so anything up to 8 counts as reachable; the old
        // `<= 2` guess meant this rung never fired in orbit at all.
        foreach ([2, 4, 6, 8] as $dist) {
            $near = $far;
            $near['asteroids'] = [['id' => 1, 'x' => 40, 'y' => 40, 'dist' => $dist]];
            $this->assertSame('dock', Ladder::suggestion($near, [], [], false, 'expansionist')['verb'], "dist {$dist} should be dockable");
        }
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

        // above the 300-600 depart band (moon altitude edge) → null.
        $high = $orbit(['deimos' => ['open' => true]], ['cryo_fuel' => 80]);
        $high['altitude'] = 601;
        $this->assertNull(Ladder::departTarget($high));

        // Associated with a body (surface / orbit / `location`) is NOT an
        // Earth-orbit depart context — even in the 300-600 band with an open
        // window it must not offer that (or any) body. Prevents `depart` to the
        // moon you are already at.
        $atMoon = $orbit(['deimos' => ['open' => true]], ['cryo_fuel' => 80]);
        $atMoon['expansion']['location'] = 'on_deimos';
        $this->assertNull(Ladder::departTarget($atMoon));
        $atMoonOrbit = $orbit(['deimos' => ['open' => true]], ['cryo_fuel' => 80]);
        $atMoonOrbit['expansion']['at_body_orbit'] = 'deimos';
        $this->assertNull(Ladder::departTarget($atMoonOrbit));

        // a destination in the unreachable list is skipped → next cheapest wins.
        $twoOpen = $orbit(['deimos' => ['open' => true], 'phobos' => ['open' => true]], ['cryo_fuel' => 80]);
        $this->assertSame('phobos', Ladder::departTarget($twoOpen, ['deimos']));
        $this->assertNull(Ladder::departTarget($twoOpen, ['deimos', 'phobos']));
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

    public function testAffordableBuyRefusesWhenOneUnitIsOutOfReach(): void
    {
        // metal is 10/unit: 9 credits cannot buy even one.
        self::assertNull(Ladder::affordableBuy('metal', 20, 9));
        $step = Ladder::affordableBuy('metal', 20, 110);
        self::assertNotNull($step);
        self::assertSame(11, $step['n']);
    }

    public function testRaiseCashStepSellsTheBiggestTradeableHoard(): void
    {
        $step = Ladder::raiseCashStep(['credits' => 4, 'brine' => 9000, 'iron' => 300, 'crystal' => 40]);
        self::assertNotNull($step);
        self::assertSame('sell', $step['verb']);
        self::assertSame('iron', $step['args']['resource']);   // brine is untradeable
        self::assertSame(20, $step['args']['n']);

        self::assertNull(Ladder::raiseCashStep(['credits' => 4, 'brine' => 9000]));
    }
}
