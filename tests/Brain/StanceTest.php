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
     * @covers \NHA\Brain\Stance
     */
    public function testRanksCapitalistOnAFatPileWithNothingToBuild(): void
    {
        $raw = ['tick' => 100, 'inventory' => ['credits' => 5000]];

        $this->assertSame(Stance::Capitalist, Stance::pick($raw, 'homestead', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testHysteresisHoldsTheStanceUntilTheDwellTimerExpires(): void
    {
        $wantsCapitalist = ['tick' => 120, 'inventory' => ['credits' => 5000]];

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
