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
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $dir = dirname($this->path);
        if (is_dir($dir)) {
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

        $this->assertTrue($store->acquireAutoplayLease('bot.php:1', 45), 'a free lease is granted');
        $this->assertSame('bot.php:1', $store->autoplayLeaseHolder());

        $this->assertFalse($store->acquireAutoplayLease('autoplay.php:2', 45), 'a live lease blocks another holder');
        $this->assertTrue($store->acquireAutoplayLease('bot.php:1', 45), 'the holder can always renew');

        $store->releaseAutoplayLease('bot.php:1');
        $this->assertNull($store->autoplayLeaseHolder());
        $this->assertTrue($store->acquireAutoplayLease('autoplay.php:2', 45), 'the lease is free again after release');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testAutoplayLeaseExpires(): void
    {
        $store = new StateStore($this->path);
        $store->acquireAutoplayLease('bot.php:1', 45);

        // Rewrite the on-disk lease so it is already stale, then reload.
        $data = json_decode(file_get_contents($this->path), true);
        $data['autoplay_lease']['expires'] = time() - 1;
        file_put_contents($this->path, json_encode($data));

        $reloaded = new StateStore($this->path);
        $this->assertNull($reloaded->autoplayLeaseHolder(), 'an expired lease is nobody\'s');
        $this->assertTrue($reloaded->acquireAutoplayLease('autoplay.php:2', 45), 'and can be taken over');
    }

    /**
     * @covers \NHA\StateStore
     */
    public function testReleaseOnlyAffectsYourOwnLease(): void
    {
        $store = new StateStore($this->path);
        $store->acquireAutoplayLease('bot.php:1', 45);

        $store->releaseAutoplayLease('someone-else');

        $this->assertSame('bot.php:1', $store->autoplayLeaseHolder(), 'a foreign release is ignored');
    }
}
