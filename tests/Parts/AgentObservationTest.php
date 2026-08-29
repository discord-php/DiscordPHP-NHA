<?php

declare(strict_types=1);

namespace Tests\NHA\Parts;

use DiscordTestCase;
use NHA\Parts\AgentObservation;

class AgentObservationTest extends DiscordTestCase
{
    public function testGetReadsNestedDotSeparatedPath(): void
    {
        $obs = new AgentObservation(1, ['nearby' => ['agents' => [1, 2, 3]]]);

        $this->assertSame([1, 2, 3], $obs->get('nearby.agents'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame('fallback', $obs->get('missing.path', 'fallback'));
        $this->assertNull($obs->get('missing.path'));
    }

    public function testGetHpFallsBackToHealthKey(): void
    {
        $obs = new AgentObservation(1, ['health' => 55]);

        $this->assertSame(55.0, $obs->getHp());
    }

    public function testGetMaxHpDefaultsTo100(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame(100.0, $obs->getMaxHp());
    }

    public function testGetPositionFallsBackToPosKey(): void
    {
        $obs = new AgentObservation(1, ['pos' => ['x' => 3, 'y' => 4]]);

        $this->assertSame(['x' => 3, 'y' => 4], $obs->getPosition());
    }

    public function testGetInventoryDefaultsToEmptyArray(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame([], $obs->getInventory());
    }

    public function testGetThreatsFallsBackToThreatAlertsKey(): void
    {
        $obs = new AgentObservation(1, ['threat_alerts' => ['a']]);

        $this->assertSame(['a'], $obs->getThreats());
    }

    public function testGetMessagesDefaultsToEmptyArray(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame([], $obs->getMessages());
    }

    public function testJsonSerializeReturnsRawPayload(): void
    {
        $obs = new AgentObservation(1, ['hp' => 10]);

        $this->assertSame(['hp' => 10], $obs->jsonSerialize());
    }
}
