<?php

declare(strict_types=1);

use NHA\StateStore;

class StateStoreTest extends NHAUnitTestCase
{
    protected string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-state-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            // Sweep everything the store may have dropped here: the state file,
            // an orphaned `.tmp`, and the `.lease.lock` file the lease CAS uses.
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testDefaultAgentIsNullWhenNoFileExists(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->getDefaultAgent());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testSetDefaultAgentPersistsToDisk(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42);

        $this->assertSame(42, $store->getDefaultAgent());
        $this->assertFileExists($this->path);
        $this->assertSame(['default_agent' => 42], json_decode(file_get_contents($this->path), true));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testStateIsReloadedFromExistingFile(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(7);

        $reloaded = new StateStore($this->path);

        $this->assertSame(7, $reloaded->getDefaultAgent());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testSetDefaultAgentOverwritesPreviousValue(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(1);
        $store->setDefaultAgent(2);

        $this->assertSame(2, $store->getDefaultAgent());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testDefaultAgentTokenIsNullWhenNotSet(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42);

        $this->assertNull($store->getDefaultAgentToken());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testSetDefaultAgentPersistsToken(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42, 'secret-token');

        $this->assertSame('secret-token', $store->getDefaultAgentToken());

        $reloaded = new StateStore($this->path);
        $this->assertSame(42, $reloaded->getDefaultAgent());
        $this->assertSame('secret-token', $reloaded->getDefaultAgentToken());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testSetDefaultAgentWithoutTokenKeepsPreviousToken(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(1, 'secret-token');
        $store->setDefaultAgent(2);

        $this->assertSame(2, $store->getDefaultAgent());
        $this->assertSame('secret-token', $store->getDefaultAgentToken());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testDiscordUserAgentPersistsToDisk(): void
    {
        $store = new StateStore($this->path);
        $store->setDiscordUserAgent('123', 42, 'discord-123', 'secret-token');

        $reloaded = new StateStore($this->path);

        $this->assertSame([
            'agent_id' => 42,
            'name' => 'discord-123',
            'token' => 'secret-token',
        ], $reloaded->getDiscordUserAgent('123'));
        $this->assertNull($reloaded->getDiscordUserAgent('456'));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAgentPositionIsNullWhenNeverRecorded(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->getAgentPosition(142287));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAgentPositionPersistsAndReloads(): void
    {
        $store = new StateStore($this->path);
        $store->setAgentPosition(142287, 30, 118, 1099632);

        $position = $store->getAgentPosition(142287);
        $this->assertSame(30, $position['x']);
        $this->assertSame(118, $position['y']);
        $this->assertSame(1099632, $position['tick']);
        $this->assertGreaterThan(0, $position['updated_at']);

        $reloaded = new StateStore($this->path);
        $this->assertSame(30, $reloaded->getAgentPosition(142287)['x']);
        $this->assertSame(118, $reloaded->getAgentPosition(142287)['y']);
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAgentPositionUpdateOverwritesAndDoesNotTouchIdentity(): void
    {
        $store = new StateStore($this->path);
        $store->setDiscordUserAgent('116927250145869826', 142287, 'user-116927250145869826', 'tok');
        $store->setAgentPosition(142287, 30, 118, 1);
        $store->setAgentPosition(142287, 31, 119, 2);

        $this->assertSame(['x' => 31, 'y' => 119, 'updated_at' => $store->getAgentPosition(142287)['updated_at'], 'tick' => 2], $store->getAgentPosition(142287));
        $this->assertSame(142287, $store->getDiscordUserAgent('116927250145869826')['agent_id']);
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAgentPositionTickIsOptional(): void
    {
        $store = new StateStore($this->path);
        $store->setAgentPosition(9, 1, 2);

        $this->assertArrayNotHasKey('tick', $store->getAgentPosition(9));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testRecordObservationSnapshotsPositionAndTick(): void
    {
        $store = new StateStore($this->path);
        $store->recordObservation(142287, new \NHA\Parts\AgentObservation(142287, ['position' => [30, 118], 'tick' => 1099632]));

        $position = $store->getAgentPosition(142287);
        $this->assertSame(30, $position['x']);
        $this->assertSame(118, $position['y']);
        $this->assertSame(1099632, $position['tick']);
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testRecordObservationIsNoOpWithoutPosition(): void
    {
        $store = new StateStore($this->path);
        $store->recordObservation(1, new \NHA\Parts\AgentObservation(1, ['tick' => 5]));

        $this->assertNull($store->getAgentPosition(1));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testCommandSignaturesDefaultToEmptyAndPersist(): void
    {
        $store = new StateStore($this->path);
        $this->assertSame([], $store->getCommandSignatures());

        $store->setCommandSignature('mine', 'abc123');
        $store->setCommandSignature('chop', 'def456');

        $reloaded = new StateStore($this->path);
        $this->assertSame(['mine' => 'abc123', 'chop' => 'def456'], $reloaded->getCommandSignatures());
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testSaveIsAtomicAndLeavesNoTempFile(): void
    {
        $store = new StateStore($this->path);
        $store->setCommandSignature('nha', 'sig');

        $this->assertFileExists($this->path);
        $this->assertJson(file_get_contents($this->path));
        $this->assertSame([], glob(dirname($this->path) . '/*.tmp'), 'the temp file is renamed away, not left behind');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAutoplayLeaseIsHeldByOneDriverAtATime(): void
    {
        $store = new StateStore($this->path);

        $this->assertTrue($store->acquireAutoplayLease('bot.php:1', 15), 'a free lease is granted');
        $this->assertSame('bot.php:1', $store->autoplayLeaseHolder());

        $this->assertFalse($store->acquireAutoplayLease('autoplay.php:2', 15), 'a live lease blocks another holder');
        $this->assertTrue($store->acquireAutoplayLease('bot.php:1', 15), 'the holder can always renew');

        $store->releaseAutoplayLease('bot.php:1');
        $this->assertNull($store->autoplayLeaseHolder());
        $this->assertTrue($store->acquireAutoplayLease('autoplay.php:2', 15), 'the lease is free again after release');
    }

    /**
     * @covers \NHA\StateStore::leaseTtlForInterval
     */
    public function testAutoplayLeaseTtlIsThreeIntervalsFlooredAt45s(): void
    {
        $this->assertSame(45, StateStore::leaseTtlForInterval(null), 'null → the floor');
        $this->assertSame(45, StateStore::leaseTtlForInterval(10), '3×10 is below the floor');
        $this->assertSame(45, StateStore::leaseTtlForInterval(15), '3×15 sits exactly on the floor');
        $this->assertSame(180, StateStore::leaseTtlForInterval(60), 'a 60s interval outlives the gap between turns');
        $this->assertSame(45, StateStore::leaseTtlForInterval(-5), 'a nonsense interval still yields the floor');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAutoplayLeaseExpires(): void
    {
        $store = new StateStore($this->path);
        $store->acquireAutoplayLease('bot.php:1', 15);

        // Rewrite the on-disk lease so it is already stale, then reload.
        $data = json_decode(file_get_contents($this->path), true);
        $data['autoplay_lease']['expires'] = time() - 1;
        file_put_contents($this->path, json_encode($data));

        $reloaded = new StateStore($this->path);
        $this->assertNull($reloaded->autoplayLeaseHolder(), 'an expired lease is nobody\'s');
        $this->assertTrue($reloaded->acquireAutoplayLease('autoplay.php:2', 15), 'and can be taken over');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testReleaseOnlyAffectsYourOwnLease(): void
    {
        $store = new StateStore($this->path);
        $store->acquireAutoplayLease('bot.php:1', 15);

        $store->releaseAutoplayLease('someone-else');

        $this->assertSame('bot.php:1', $store->autoplayLeaseHolder(), 'a foreign release is ignored');
    }

    /**
     * A corrupt/empty state file must not let the lease re-read wipe the store.
     * The NHA server issues an agent token once, so losing it is permanent.
     *
     * @covers \NHA\StateStore
     *
     * @dataProvider corruptStateFiles
     */
    public function testLeaseReReadDoesNotAdoptAnEmptyOrInvalidStateFile(string $corrupt): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(142285, 'once-only-token');
        $store->acquireAutoplayLease('bot.php:1', 15);

        // The file goes bad between turns; the next acquire re-reads it under lock.
        file_put_contents($this->path, $corrupt);
        $store->acquireAutoplayLease('bot.php:1', 15);

        $reloaded = new StateStore($this->path);
        $this->assertSame(142285, $reloaded->getDefaultAgent(), 'the agent id survived');
        $this->assertSame('once-only-token', $reloaded->getDefaultAgentToken(), 'the once-only token survived');
        $this->assertSame('bot.php:1', $reloaded->autoplayLeaseHolder(), 'and the lease is still held');
    }

    /** @return array<string, array{string}> */
    public static function corruptStateFiles(): array
    {
        return [
            'empty file' => [''],
            'whitespace only' => ["  \n"],
            'truncated json' => ['{"default_agent": 142285, "default_agent_'],
            'json null' => ['null'],
            'json array' => ['[]'],
        ];
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testClearQueuedIntentForgetsTheIdButKeepsTheRestOfTheDecision(): void
    {
        $store = new StateStore($this->path);
        $store->recordDecision(7, ['verb' => 'combine', 'args' => ['a' => 'herb'], 'reason' => 'try it', 'queued_intent' => 3168877, 'tick' => 10]);

        $store->clearQueuedIntent(7);

        $last = (new StateStore($this->path))->getLastDecision(7);
        $this->assertNull($last['queued_intent'], 'the settled/aged-out id is forgotten');
        $this->assertSame('combine', $last['verb'], 'the rest of the decision is untouched');
        $this->assertSame(10, $last['tick']);
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testClearQueuedIntentWithAMismatchedIdIsANoOp(): void
    {
        $store = new StateStore($this->path);
        $store->recordDecision(7, ['verb' => 'mine', 'args' => [], 'reason' => '', 'queued_intent' => 999, 'tick' => 1]);

        // A newer decision already replaced the id we were polling — do not wipe it.
        $store->clearQueuedIntent(7, 555);

        $this->assertSame(999, (new StateStore($this->path))->getLastDecision(7)['queued_intent']);
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testCombineSignaturesAreRecordedDeduplicatedAndPersisted(): void
    {
        $store = new StateStore($this->path);
        $store->recordCombineSignature(7, 'glass+wood');
        $store->recordCombineSignature(7, 'iron+wood');
        $store->recordCombineSignature(7, 'glass+wood'); // dupe
        $store->recordCombineSignature(7, '   ');        // blank ignored

        $this->assertSame(['glass+wood', 'iron+wood'], (new StateStore($this->path))->getTriedCombineSignatures(7));
        $this->assertSame([], $store->getTriedCombineSignatures(999), 'other agents are unaffected');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testNoteInventorPointsTracksTheRecentTrendNotTheAbsolute(): void
    {
        $store = new StateStore($this->path);

        // First sighting only sets a baseline — no trend yet, even at 72 points.
        $this->assertFalse($store->noteInventorPoints(7, 72));

        // Score stalls: still 72 next turn → research is not paying.
        $this->assertFalse($store->noteInventorPoints(7, 72));

        // A discovery lands (72 → 84) → paying now, and it stays "paying" while
        // the gain is still recent.
        $this->assertTrue($store->noteInventorPoints(7, 84));
        $this->assertTrue($store->noteInventorPoints(7, 84));

        // A fresh reader sees the same recent-gain window.
        $this->assertTrue((new StateStore($this->path))->noteInventorPoints(7, 84));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testCombineSignatureListIsCappedAt2000(): void
    {
        $store = new StateStore($this->path);
        for ($i = 0; $i < 2050; $i++) {
            $store->recordCombineSignature(7, "sig{$i}");
        }

        $kept = $store->getTriedCombineSignatures(7);
        $this->assertCount(2000, $kept);
        $this->assertSame('sig2049', end($kept), 'newest is kept');
        $this->assertSame('sig50', $kept[0], 'oldest 50 were dropped');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testDeadCombinesAreRecordedDeduplicatedPersistedAndPerAgent(): void
    {
        $store = new StateStore($this->path);
        $store->recordDeadCombine(7, 'glass+wood');
        $store->recordDeadCombine(7, 'lens+salt');
        $store->recordDeadCombine(7, 'glass+wood'); // dupe
        $store->recordDeadCombine(7, '  ');         // blank ignored

        $this->assertSame(['glass+wood', 'lens+salt'], (new StateStore($this->path))->getDeadCombines(7));
        $this->assertSame([], $store->getDeadCombines(999), 'scoped per agent');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testForcedObjectiveRotatesPersistsAndExpires(): void
    {
        $store = new StateStore($this->path);

        $this->assertSame('expand', $store->bumpForcedObjective(7, 100)); // 'expand' now leads the rotation
        $this->assertSame('wealth', $store->bumpForcedObjective(7, 105));
        $this->assertSame('build', $store->bumpForcedObjective(7, 110));

        // Fresh reader, still inside the TTL.
        $this->assertSame('build', (new StateStore($this->path))->getForcedObjective(7, 120));
        // Aged out after 45 ticks.
        $this->assertNull($store->getForcedObjective(7, 200));
        // Wraps back round.
        $this->assertSame('research', $store->bumpForcedObjective(7, 210));
        $this->assertSame('expand', $store->bumpForcedObjective(7, 215));
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testLoopBreakCooldown(): void
    {
        $store = new StateStore($this->path);

        $this->assertFalse($store->loopBreakCooldownActive(7, 100), 'no break yet');
        $store->bumpForcedObjective(7, 100);
        $this->assertTrue($store->loopBreakCooldownActive(7, 110), 'within 24 ticks of the break');
        $this->assertFalse($store->loopBreakCooldownActive(7, 130), 'past the cooldown');
    }

    /**
     * @covers \NHA\State\LoopStrategyStateTrait
     */
    public function testGoingHomeLatchPersistsAcrossAReload(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->goingHome(7), 'not heading home yet');
        $store->setGoingHome(7, 'deimos');
        $this->assertSame('deimos', $store->goingHome(7));

        // Survives a reload — the glitch-proofing point of the latch.
        $reloaded = new StateStore($this->path);
        $this->assertSame('deimos', $reloaded->goingHome(7));

        $reloaded->clearGoingHome(7);
        $this->assertNull($reloaded->goingHome(7));

        // Per-agent.
        $this->assertNull($reloaded->goingHome(8));
    }

    /**
     * @covers \NHA\State\LoopStrategyStateTrait
     */
    public function testColonyDoneBodiesAreRecordedDeduplicatedAndPerAgent(): void
    {
        $store = new StateStore($this->path);

        $this->assertSame([], $store->colonyDoneBodies(7));
        $store->recordColonyDone(7, 'deimos');
        $store->recordColonyDone(7, 'deimos');
        $store->recordColonyDone(7, 'phobos');
        $this->assertSame(['deimos', 'phobos'], $store->colonyDoneBodies(7));
        $this->assertSame([], $store->colonyDoneBodies(8), 'per-agent');

        $store->clearColonyDone(7, 'deimos');
        $this->assertSame(['phobos'], $store->colonyDoneBodies(7));

        $reloaded = new StateStore($this->path);
        $this->assertSame(['phobos'], $reloaded->colonyDoneBodies(7));
    }

    /**
     * @covers \NHA\State\LoopStrategyStateTrait
     */
    public function testRideCooldownActiveAfterARecordedRideAndExpiresAfterTheWindow(): void
    {
        $store = new StateStore($this->path);

        $this->assertFalse($store->rideCooldownActive(7, 100), 'nothing recorded yet');
        $store->recordRide(7, 100);
        $this->assertTrue($store->rideCooldownActive(7, 101), 'just bounced — still cooling down');
        $this->assertTrue($store->rideCooldownActive(7, 111), 'one tick short of the window');
        $this->assertFalse($store->rideCooldownActive(7, 112), 'cooldown window elapsed');
        $this->assertFalse($store->rideCooldownActive(8, 101), 'per-agent');

        // Survives a reload.
        $reloaded = new StateStore($this->path);
        $this->assertTrue($reloaded->rideCooldownActive(7, 101));
    }
}
