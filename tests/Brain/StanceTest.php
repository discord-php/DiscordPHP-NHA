<?php

declare(strict_types=1);

use NHA\Brain\Stance;

class StanceTest extends NHAUnitTestCase
{
    /**
     * @covers \NHA\Brain\Stance
     */
    public function testRanksAggressiveOnARecentAttackWhenArmed(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 90,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6],
            'alerts' => [['tick' => 90, 'kind' => 'attacked', 'by' => 7]],
        ];

        $this->assertSame(Stance::Aggressive, Stance::pick($raw, 'homestead', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testAnAttackWhileUnarmedDoesNotForceAggressive(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 40, 'inventory' => [],
            'alerts' => [['tick' => 95, 'kind' => 'attacked', 'by' => 7]],
        ];

        $this->assertSame(Stance::Homestead, Stance::pick($raw, 'homestead', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testRanksExpansionistInSpace(): void
    {
        $this->assertSame(
            Stance::Expansionist,
            Stance::pick(['tick' => 100, 'in_space' => true, 'inventory' => []], 'homestead', 0),
        );
    }

    /**
     * On Earth, once minimally geared (weapon + ammo + a medicine), the drive
     * is the Solar Accord mission — expansionist, not homestead.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testRanksExpansionistOnceGearedOnEarth(): void
    {
        $geared = ['tick' => 100, 'inventory' => ['kinetic_gun' => 1, 'slug' => 6, 'stimpack' => 1]];
        $this->assertSame(Stance::Expansionist, Stance::pick($geared, 'homestead', 0));

        // Still unarmed → homestead (gear up first).
        $bare = ['tick' => 100, 'inventory' => ['wood' => 20]];
        $this->assertSame(Stance::Homestead, Stance::pick($bare, 'homestead', 0));
    }

    /**
     * A fat pile plus real market work to do (here: a raw stockpiled past the
     * hoard cap) ranks Capitalist.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testRanksCapitalistOnAFatPileWithMarketWork(): void
    {
        $raw = ['tick' => 100, 'inventory' => ['credits' => 5000, 'brine' => 140]];

        $this->assertSame(Stance::Capitalist, Stance::pick($raw, 'homestead', 0));
    }

    /**
     * A covered contract also counts as market work.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testACoveredContractRanksCapitalist(): void
    {
        $raw = [
            'tick' => 100,
            'inventory' => ['credits' => 5000, 'iron' => 20],
            'contracts' => [['id' => 1, 'want' => ['iron' => 10]]],
        ];

        $this->assertSame(Stance::Capitalist, Stance::pick($raw, 'homestead', 0));
    }

    /**
     * The deadlock guard: a fat pile with nothing to build AND nothing to trade
     * must NOT latch Capitalist — the agent belongs in Homestead spending the
     * credits on a tower. (Capitalist's own steer never lets it assemble the
     * build materials, so it would be stuck forever.)
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAFatPileWithNoMarketWorkFallsToHomestead(): void
    {
        $raw = ['tick' => 100, 'inventory' => ['credits' => 5000, 'iron' => 20]];

        $this->assertSame(Stance::Homestead, Stance::pick($raw, 'capitalist', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testHysteresisHoldsTheStanceUntilTheDwellTimerExpires(): void
    {
        $wantsCapitalist = ['tick' => 120, 'inventory' => ['credits' => 5000, 'brine' => 140]];

        // Switched to homestead at tick 100; only 20 ticks ago → hold it.
        $this->assertSame(Stance::Homestead, Stance::pick($wantsCapitalist, 'homestead', 100));

        // 41 ticks later the dwell timer has expired → the switch goes through.
        $wantsCapitalist['tick'] = 141;
        $this->assertSame(Stance::Capitalist, Stance::pick($wantsCapitalist, 'homestead', 100));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testAggressiveIgnoresTheDwellTimer(): void
    {
        $raw = [
            'tick' => 105, 'hp' => 90,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6],
            'alerts' => [['tick' => 104, 'kind' => 'attacked', 'by' => 7]],
        ];

        // Stance changed only 5 ticks ago, but a fight does not wait.
        $this->assertSame(Stance::Aggressive, Stance::pick($raw, 'homestead', 100));
    }
}
