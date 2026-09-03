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

    public function testDefaultAgentIsNullWhenNoFileExists(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->getDefaultAgent());
    }

    public function testSetDefaultAgentPersistsToDisk(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42);

        $this->assertSame(42, $store->getDefaultAgent());
        $this->assertFileExists($this->path);
        $this->assertSame(['default_agent' => 42], json_decode(file_get_contents($this->path), true));
    }

    public function testStateIsReloadedFromExistingFile(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(7);

        $reloaded = new StateStore($this->path);

        $this->assertSame(7, $reloaded->getDefaultAgent());
    }

    public function testSetDefaultAgentOverwritesPreviousValue(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(1);
        $store->setDefaultAgent(2);

        $this->assertSame(2, $store->getDefaultAgent());
    }

    public function testDefaultAgentTokenIsNullWhenNotSet(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42);

        $this->assertNull($store->getDefaultAgentToken());
    }

    public function testSetDefaultAgentPersistsToken(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(42, 'secret-token');

        $this->assertSame('secret-token', $store->getDefaultAgentToken());

        $reloaded = new StateStore($this->path);
        $this->assertSame(42, $reloaded->getDefaultAgent());
        $this->assertSame('secret-token', $reloaded->getDefaultAgentToken());
    }

    public function testSetDefaultAgentWithoutTokenKeepsPreviousToken(): void
    {
        $store = new StateStore($this->path);
        $store->setDefaultAgent(1, 'secret-token');
        $store->setDefaultAgent(2);

        $this->assertSame(2, $store->getDefaultAgent());
        $this->assertSame('secret-token', $store->getDefaultAgentToken());
    }

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

    public function testAgentPositionIsNullWhenNeverRecorded(): void
    {
        $store = new StateStore($this->path);

        $this->assertNull($store->getAgentPosition(142287));
    }

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

    public function testAgentPositionUpdateOverwritesAndDoesNotTouchIdentity(): void
    {
        $store = new StateStore($this->path);
        $store->setDiscordUserAgent('116927250145869826', 142287, 'user-116927250145869826', 'tok');
        $store->setAgentPosition(142287, 30, 118, 1);
        $store->setAgentPosition(142287, 31, 119, 2);

        $this->assertSame(['x' => 31, 'y' => 119, 'updated_at' => $store->getAgentPosition(142287)['updated_at'], 'tick' => 2], $store->getAgentPosition(142287));
        $this->assertSame(142287, $store->getDiscordUserAgent('116927250145869826')['agent_id']);
    }

    public function testAgentPositionTickIsOptional(): void
    {
        $store = new StateStore($this->path);
        $store->setAgentPosition(9, 1, 2);

        $this->assertArrayNotHasKey('tick', $store->getAgentPosition(9));
    }

    public function testRecordObservationSnapshotsPositionAndTick(): void
    {
        $store = new StateStore($this->path);
        $store->recordObservation(142287, new \NHA\Parts\AgentObservation(142287, ['position' => [30, 118], 'tick' => 1099632]));

        $position = $store->getAgentPosition(142287);
        $this->assertSame(30, $position['x']);
        $this->assertSame(118, $position['y']);
        $this->assertSame(1099632, $position['tick']);
    }

    public function testRecordObservationIsNoOpWithoutPosition(): void
    {
        $store = new StateStore($this->path);
        $store->recordObservation(1, new \NHA\Parts\AgentObservation(1, ['tick' => 5]));

        $this->assertNull($store->getAgentPosition(1));
    }
}
