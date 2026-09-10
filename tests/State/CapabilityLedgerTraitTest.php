<?php

declare(strict_types=1);

use NHA\StateStore;

class CapabilityLedgerTraitTest extends NHAUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-cap-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testARecordedBlockIsReadableAndSurvivesAReload(): void
    {
        $store = new StateStore($this->path);
        $store->recordCapability(7, 'depart:deimos', 'needs_part', 'no landing gear', 1200);

        $this->assertTrue($store->capabilityBlocked(7, 'depart:deimos'));
        $this->assertSame('no landing gear', $store->capabilityReason(7, 'depart:deimos'));
        $this->assertFalse($store->capabilityBlocked(7, 'depart:mars'));
        $this->assertNull($store->capabilityReason(7, 'depart:mars'));

        $reloaded = new StateStore($this->path);
        $this->assertTrue($reloaded->capabilityBlocked(7, 'depart:deimos'));
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testCapabilityTargetsStripsThePrefixAndOnlyMatchesThatPrefix(): void
    {
        $store = new StateStore($this->path);
        $store->recordCapability(7, 'depart:deimos', 'needs_part', 'x', 1);
        $store->recordCapability(7, 'depart:phobos', 'needs_part', 'x', 1);
        $store->recordCapability(7, 'construct:module', 'needs_enum', 'x', 1);

        $targets = $store->capabilityTargets(7, 'depart');
        sort($targets);
        $this->assertSame(['deimos', 'phobos'], $targets);
        $this->assertSame(['module'], $store->capabilityTargets(7, 'construct'));
        $this->assertSame([], $store->capabilityTargets(7, 'build'));
        $this->assertSame([], $store->capabilityTargets(99, 'depart'));
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testClearingAClassDropsOnlyThatClass(): void
    {
        $store = new StateStore($this->path);
        $store->recordCapability(7, 'depart:deimos', 'needs_part', 'x', 1);
        $store->recordCapability(7, 'depart:venus', 'capability', 'x', 1);
        $store->recordCapability(7, 'construct:module', 'needs_enum', 'x', 1);

        $store->clearCapabilityClass(7, 'needs_part');

        $this->assertFalse($store->capabilityBlocked(7, 'depart:deimos'));
        $this->assertTrue($store->capabilityBlocked(7, 'depart:venus'));
        $this->assertTrue($store->capabilityBlocked(7, 'construct:module'));
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testANeedsItemBlockClearsOnceTheGatingItemIsHeld(): void
    {
        $store = new StateStore($this->path);
        $store->recordCapability(7, 'depart:venus', 'needs_item', 'no acid_skin', 1, 'acid_skin');
        $store->recordCapability(7, 'depart:mars', 'needs_item', 'no heat_shield', 1, 'heat_shield');

        // Empty / absent inventory leaves both in place.
        $store->clearCapabilitiesWithItem(7, ['metal' => 4]);
        $this->assertTrue($store->capabilityBlocked(7, 'depart:venus'));

        $store->clearCapabilitiesWithItem(7, ['acid_skin' => 1, 'heat_shield' => 0]);
        $this->assertFalse($store->capabilityBlocked(7, 'depart:venus'));
        $this->assertTrue($store->capabilityBlocked(7, 'depart:mars'), 'a zero count does not clear the block');
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testTheReviewHighWaterMarkOnlyEverAdvances(): void
    {
        $store = new StateStore($this->path);
        $this->assertSame(0, $store->capabilityReviewTick(7));

        $store->setCapabilityReviewTick(7, 1500);
        $this->assertSame(1500, $store->capabilityReviewTick(7));

        // A stale / lower value is ignored.
        $store->setCapabilityReviewTick(7, 1400);
        $this->assertSame(1500, $store->capabilityReviewTick(7));

        $store->setCapabilityReviewTick(7, 1600);
        $this->assertSame(1600, $store->capabilityReviewTick(7));
    }

    /**
     * @covers \NHA\State\CapabilityLedgerTrait
     */
    public function testRecordingTheSameKeyRefreshesRatherThanDuplicates(): void
    {
        $store = new StateStore($this->path);
        $store->recordCapability(7, 'depart:deimos', 'needs_part', 'first reason', 1000);
        $store->recordCapability(7, 'depart:deimos', 'needs_part', 'newer reason', 1200);

        $this->assertSame('newer reason', $store->capabilityReason(7, 'depart:deimos'));
        $this->assertCount(1, $store->capabilities(7));
    }
}
