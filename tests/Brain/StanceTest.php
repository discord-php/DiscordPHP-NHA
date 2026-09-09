<?php

declare(strict_types=1);

use NHA\Brain\Stance;

/**
 * One goal — the Solar Accord — so {@see Stance::rank()} has only two answers:
 * defend a live fight (`aggressive`), or drive the mission (`expansionist`).
 * `homestead` / `capitalist` are never ranked.
 */
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

        $this->assertSame(Stance::Aggressive, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * Being attacked while unarmed is not `aggressive` (nothing to fight back
     * with) — the mission stance still stands; the defend/flee rungs handle it.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAnAttackWhileUnarmedDoesNotForceAggressive(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 40, 'inventory' => [],
            'alerts' => [['tick' => 95, 'kind' => 'attacked', 'by' => 7]],
        ];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * A weak passer-by is NOT a reason to switch to aggressive — hunting does
     * not further the Accord.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testASoftTargetNearbyDoesNotRankAggressive(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 100,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6],
            'nearby_agents' => [['id' => 9, 'dist' => 8, 'hp' => 20]],
        ];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testRanksExpansionistInSpace(): void
    {
        $this->assertSame(
            Stance::Expansionist,
            Stance::pick(['tick' => 100, 'in_space' => true, 'inventory' => []], 'expansionist', 0),
        );
    }

    /**
     * Every non-combat state is the mission — geared or bare, rich or broke,
     * a fat credit pile or a covered contract. The expansionist ladder arms,
     * stockpiles and banks a glut as tactics; none of that is a separate stance.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testEveryNonCombatStateRanksExpansionist(): void
    {
        $states = [
            'geared on Earth' => ['tick' => 100, 'inventory' => ['kinetic_gun' => 1, 'slug' => 6, 'stimpack' => 1]],
            'bare on Earth' => ['tick' => 100, 'inventory' => ['wood' => 20]],
            'fat pile + glut' => ['tick' => 100, 'inventory' => ['credits' => 5000, 'brine' => 140]],
            'fat pile + covered contract' => [
                'tick' => 100,
                'inventory' => ['credits' => 5000, 'iron' => 20],
                'contracts' => [['id' => 1, 'want' => ['iron' => 10]]],
            ],
            'fat pile, nothing to trade' => ['tick' => 100, 'inventory' => ['credits' => 5000, 'iron' => 20]],
        ];

        foreach ($states as $label => $raw) {
            $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0), $label);
        }
    }

    /**
     * A stored `homestead` / `capitalist` (from before this change, or written
     * mid-dwell) is corrected to the mission stance on the next pick — the
     * mission pre-empts the dwell timer, it does not wait 40 ticks.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAStaleNonMissionStanceIsCorrectedImmediately(): void
    {
        $raw = ['tick' => 105, 'inventory' => ['wood' => 20]];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'homestead', 100));
        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'capitalist', 100));
    }

    /**
     * Aggressive pre-empts the dwell timer (a fight will not wait), and the
     * moment the threat is stale the mission stance resumes.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAggressivePreemptsAndThenHandsBackToTheMission(): void
    {
        $armed = ['kinetic_gun' => 1, 'slug' => 6];

        $underFire = ['tick' => 105, 'hp' => 90, 'inventory' => $armed, 'alerts' => [['tick' => 104, 'kind' => 'attacked', 'by' => 7]]];
        $this->assertSame(Stance::Aggressive, Stance::pick($underFire, 'expansionist', 100));

        // 40+ ticks on with no fresh hit → back to the mission.
        $clear = ['tick' => 200, 'hp' => 90, 'inventory' => $armed, 'alerts' => [['tick' => 104, 'kind' => 'attacked', 'by' => 7]]];
        $this->assertSame(Stance::Expansionist, Stance::pick($clear, 'aggressive', 105));
    }
}
