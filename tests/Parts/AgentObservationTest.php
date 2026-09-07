<?php

declare(strict_types=1);

use NHA\Parts\AgentObservation;

class AgentObservationTest extends NHATestCase
{
    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetReadsNestedDotSeparatedPath(): void
    {
        $obs = new AgentObservation(1, ['nearby' => ['agents' => [1, 2, 3]]]);

        $this->assertSame([1, 2, 3], $obs->get('nearby.agents'));
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetReturnsDefaultWhenMissing(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame('fallback', $obs->get('missing.path', 'fallback'));
        $this->assertNull($obs->get('missing.path'));
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetHpFallsBackToHealthKey(): void
    {
        $obs = new AgentObservation(1, ['health' => 55]);

        $this->assertSame(55.0, $obs->getHp());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetMaxHpDefaultsTo100(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame(100.0, $obs->getMaxHp());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetPositionFallsBackToPosKey(): void
    {
        $obs = new AgentObservation(1, ['pos' => ['x' => 3, 'y' => 4]]);

        $this->assertSame(['x' => 3, 'y' => 4], $obs->getPosition());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetPositionNormalisesPositionalPair(): void
    {
        // GET /observe/:id returns `position` as a `[x, y]` pair.
        $obs = new AgentObservation(142287, ['position' => [30, 118]]);

        $this->assertSame(['x' => 30, 'y' => 118], $obs->getPosition());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetPositionAcceptsObjectShape(): void
    {
        $obs = new AgentObservation(1, ['position' => ['x' => 5, 'y' => 6]]);

        $this->assertSame(['x' => 5, 'y' => 6], $obs->getPosition());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetPositionFallsBackToFlatScalars(): void
    {
        $obs = new AgentObservation(1, ['x' => 7, 'y' => 8]);

        $this->assertSame(['x' => 7, 'y' => 8], $obs->getPosition());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetPositionIsNullWhenAbsent(): void
    {
        $this->assertNull((new AgentObservation(1, []))->getPosition());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetNearbyAgentsReadsObservePayloadKey(): void
    {
        $obs = new AgentObservation(1, ['nearby_agents' => [['id' => 2, 'x' => 3, 'y' => 4]]]);

        $this->assertSame([['id' => 2, 'x' => 3, 'y' => 4]], $obs->getNearbyAgents());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetThreatsReadsAlertsKey(): void
    {
        $obs = new AgentObservation(1, ['alerts' => ['incoming']]);

        $this->assertSame(['incoming'], $obs->getThreats());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetInventoryDefaultsToEmptyArray(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame([], $obs->getInventory());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetThreatsFallsBackToThreatAlertsKey(): void
    {
        $obs = new AgentObservation(1, ['threat_alerts' => ['a']]);

        $this->assertSame(['a'], $obs->getThreats());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testGetMessagesDefaultsToEmptyArray(): void
    {
        $obs = new AgentObservation(1, []);

        $this->assertSame([], $obs->getMessages());
    }

    /**
     * @covers \NHA\Parts\AgentObservation
     */
    public function testJsonSerializeReturnsRawPayload(): void
    {
        $obs = new AgentObservation(1, ['hp' => 10]);

        $this->assertSame(['hp' => 10], $obs->jsonSerialize());
    }

    /**
     * Guards that every response Part's `$fillable` list stays in lock-step with
     * its `*Out` schema in openapi.json. `Deposits` is excluded because it models
     * a single row of `DepositsOut.deposits`, not the envelope.
     *
     * @link https://nha.recluse.lol/openapi.json
     *
     * @coversNothing schema-drift guard, not a unit under test
     */
    public function testPartsMatchOpenApiSchemaKeys(): void
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../openapi.json'), true);
        $schemas = $schema['components']['schemas'];

        $map = [
            'AgentProfile' => 'AgentProfileOut',
            'Agents' => 'AgentsOut',
            'Chat' => 'ChatOut',
            'Contracts' => 'ContractsOut',
            'Depot' => 'DepotOut',
            'Feed' => 'FeedOut',
            'GuildPending' => 'GuildPendingOut',
            'Health' => 'HealthOut',
            'IntentStatus' => 'IntentStatusOut',
            'Inventors' => 'InventorsOut',
            'Log' => 'LogOut',
            'Map' => 'MapOut',
            'Market' => 'MarketOut',
            'Milestones' => 'MilestonesOut',
            'Records' => 'RecordsOut',
            'Relations' => 'RelationsOut',
            'Roster' => 'RosterOut',
            'Rules' => 'RulesOut',
            'Scene' => 'SceneOut',
            'Station' => 'StationOut',
            'Structures' => 'StructuresOut',
            'Timeline' => 'TimelineOut',
            'Updates' => 'UpdatesOut',
            'World' => 'WorldOut',
        ];

        foreach ($map as $class => $schemaName) {
            $this->assertArrayHasKey($schemaName, $schemas, "openapi.json is missing {$schemaName}");

            $expected = array_keys($schemas[$schemaName]['properties'] ?? []);
            sort($expected);

            $reflection = new ReflectionClass('NHA\\Parts\\' . $class);
            $actual = $reflection->getProperty('fillable')->getValue($reflection->newInstanceWithoutConstructor());
            sort($actual);

            $this->assertSame($expected, $actual, "{$class}::\$fillable is out of sync with {$schemaName}");
        }
    }

    /**
     * @covers \NHA\Parts\Deposits
     */
    public function testDepositsPartModelsOneRow(): void
    {
        $reflection = new ReflectionClass(\NHA\Parts\Deposits::class);
        $actual = $reflection->getProperty('fillable')->getValue($reflection->newInstanceWithoutConstructor());
        sort($actual);

        $this->assertSame(['amount', 'dist', 'id', 'resource', 'x', 'y'], $actual);
    }

    /**
     * @covers \NHA\Parts\Out
     */
    public function testOutBaseKeepsUndeclaredKeysAndSerialisesLosslessly(): void
    {
        $part = (new ReflectionClass(\NHA\Parts\World::class))->newInstanceWithoutConstructor();
        $part->fill(['tick' => 3, 'undocumented' => 'kept']);

        $this->assertSame(3, $part->tick);
        $this->assertSame('kept', $part->undocumented);
        $this->assertSame(['tick' => 3, 'undocumented' => 'kept'], $part->jsonSerialize());
    }
}
