<?php

declare(strict_types=1);

use NHA\Brain\GameData;

/**
 * Locks the transcribed NHA physics ({@see \NHA\Brain\GameData}) against the
 * game's public source (`github.com/Recluse/nha-mmo`, `engine/vehicles.py`).
 *
 * The brain spent weeks steering the agent with GUESSED ship mechanics; the
 * real rules are ~40 lines of closed-form integer arithmetic. These tests pin
 * that port so any future edit to a PART constant, an upgrade delta, or the
 * flyer recipe that would stop the ship flying fails loudly here instead of
 * on the live server.
 */
class GameDataTest extends NHAUnitTestCase
{
    /** `math.isqrt` semantics: floor of the real root, exact on perfect squares and the value just below. */
    public function testIsqrtMatchesPythonMathIsqrt(): void
    {
        $this->assertSame(0, GameData::isqrt(0));
        $this->assertSame(1, GameData::isqrt(1));
        $this->assertSame(1, GameData::isqrt(3));
        $this->assertSame(2, GameData::isqrt(4));
        $this->assertSame(9, GameData::isqrt(99));
        $this->assertSame(10, GameData::isqrt(100));
        $this->assertSame(100, GameData::isqrt(10022));
    }

    /**
     * The reference flyer ({@see GameData::FLYER}) — the whole point of the
     * 3.2.27 rewrite — must finalize as a launch- and depart-capable ship.
     */
    public function testReferenceFlyerFliesAndCanDepart(): void
    {
        $v = GameData::assess(GameData::FLYER);

        $this->assertTrue($v['controllable'], 'the chip cockpit must give control');
        $this->assertTrue($v['flies'], 'wing_area * v_air^2 must clear 10 * mass');
        $this->assertTrue($v['orbital_engine'], 'the ion_thruster jet is the orbital drive');
        $this->assertTrue($v['can_launch'], 'thrust must clear GRAVITY * mass');
        $this->assertTrue($v['depart']['mars'], 'thrust must clear the Mars terminal-TWR gate');
        $this->assertTrue($v['depart']['venus'], 'thrust must clear the Venus terminal-TWR gate');

        // Sanity on the transcribed numbers (mass ~880, thrust ~4900, v_air ~100).
        $this->assertGreaterThan(4000, $v['thrust']);
        $this->assertLessThan(1000, $v['mass']);
    }

    /**
     * The failure that bricked every early bundle: no `cockpit` → `control` 0 →
     * the engine reports neither `drives` nor `flies`, whatever else is bolted on.
     */
    public function testNoCockpitMeansNoControlSoNothingWorks(): void
    {
        $recipe = GameData::FLYER;
        unset($recipe['cockpit']);

        $v = GameData::assess($recipe);

        $this->assertFalse($v['controllable']);
        $this->assertFalse($v['flies']);
        $this->assertFalse($v['drives']);
        $this->assertFalse($v['depart']['mars']);
    }

    /** A bare jet (no `ion_thruster`) is not an orbital engine, so `depart` stays closed even if it flies. */
    public function testOrbitalEngineNeedsTheIonThrusterUpgrade(): void
    {
        $recipe = GameData::FLYER;
        $recipe['jet'] = [1, null];

        $v = GameData::assess($recipe);

        $this->assertFalse($v['orbital_engine']);
        $this->assertFalse($v['depart']['deimos']);
    }

    /**
     * Flight is power-to-mass, not part count: piling on bare engines adds mass
     * (raising drag, cutting `v_air`) without the propellers that turn power
     * into thrust — the exact mistake the 82-engine probe bundle made.
     */
    public function testAnEngineStackWithoutPropellersDoesNotOutflyTheLightFlyer(): void
    {
        $stack = [
            'frame' => [1, null],
            'cockpit' => [1, null],
            'engine' => [40, null],
            'wing' => [2, null],
        ];

        $v = GameData::assess($stack);
        $light = GameData::assess(GameData::FLYER);

        $this->assertGreaterThan($v['thrust'], $light['thrust'], 'the light flyer makes more thrust than 40 bare engines');
        $this->assertGreaterThan($v['v_air'], $light['v_air'], 'and a higher air speed, on a fraction of the mass');
    }

    /** `partStats` applies the `with:` deltas on top of the base row (composite frame: +strength, −40 mass). */
    public function testPartStatsAppliesUpgradeDeltas(): void
    {
        $bare = GameData::partStats('frame');
        $comp = GameData::partStats('frame', ['composite']);

        $this->assertSame(80, $bare['mass']);
        $this->assertSame(40, $comp['mass']);
        $this->assertSame(320, $comp['strength']);
    }

    /** A `wheel` + engine + cockpit with drive is a ground vehicle; drop the wheel and it no longer drives. */
    public function testDrivesNeedsControlAWheelAndDrive(): void
    {
        $rover = ['cockpit' => [1, null], 'engine' => [1, null], 'wheel' => [4, null], 'frame' => [1, null]];
        $this->assertTrue(GameData::assess($rover)['drives']);

        $noWheels = $rover;
        unset($noWheels['wheel']);
        $this->assertFalse(GameData::assess($noWheels)['drives']);
    }

    private const BUNDLE = [
        'frame' => 1, 'cockpit' => 1, 'jet' => 1, 'engine' => 3, 'propeller' => 2,
        'wing' => 3, 'tail' => 1, 'fuel_tank' => 2, 'landing_gear' => 1,
    ];

    private const UPGRADE = [
        'frame' => 'composite', 'cockpit' => 'chip', 'jet' => 'ion_thruster',
        'engine' => 'engine', 'propeller' => 'bearing', 'wing' => 'composite',
        'fuel_tank' => 'casing', 'wheel' => 'alloy', 'tail' => 'alloy',
    ];

    /** With nothing built and nothing on hand, the bill is the whole bundle expanded to raws. */
    public function testRemainingShipBillForAnUnstartedFlyer(): void
    {
        $bill = GameData::remainingShipBill(self::BUNDLE, self::UPGRADE, [], []);

        // BUILD_COST metal: 5+4+10+24+8+12+2+6+3 = 74, plus 2 bearings (metal+oil) + 1 alloy tail (metal 2).
        $this->assertSame(78, $bill['metal']);
        $this->assertSame(6, $bill['crystal']);
        // composite for frame + 3 wings → 4 aluminium + 4 carbon; chip → 1 silicon + 1 copper.
        $this->assertSame(4, $bill['aluminum']);
        $this->assertSame(4, $bill['carbon']);
        $this->assertSame(1, $bill['silicon']);
        $this->assertSame(1, $bill['copper']);
        $this->assertSame(2, $bill['oil']);
    }

    /**
     * The bill SHRINKS as parts get built and stock comes in — the "decrease
     * after having built it" half of the feature.
     */
    public function testRemainingShipBillShrinksAsPartsAreBuilt(): void
    {
        $full = GameData::remainingShipBill(self::BUNDLE, self::UPGRADE, [], []);
        $part = GameData::remainingShipBill(
            self::BUNDLE,
            self::UPGRADE,
            ['frame' => 1, 'cockpit' => 1, 'jet' => 1, 'engine' => 3],
            ['metal' => 20, 'composite' => 2],
        );

        $this->assertLessThan($full['metal'], $part['metal']);
        $this->assertLessThan($full['aluminum'], $part['aluminum']);
        $this->assertArrayNotHasKey('crystal', $part, 'the crystal parts (jet + 3 engines) are built — no crystal left to buy');
        $this->assertArrayNotHasKey('silicon', $part, 'the chip cockpit is built');
    }

    /** A fully built bundle (everything in loose_parts) needs nothing more. */
    public function testRemainingShipBillIsEmptyForACompleteBundle(): void
    {
        $this->assertSame([], GameData::remainingShipBill(self::BUNDLE, self::UPGRADE, self::BUNDLE, []));
    }
}
