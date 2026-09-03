<?php

declare(strict_types=1);

use NHA\Parts\AgentObservation;

class AgentObservationTest extends NHATestCase
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

    public function testGetPositionNormalisesPositionalPair(): void
    {
        // GET /observe/:id returns `position` as a `[x, y]` pair.
        $obs = new AgentObservation(142287, ['position' => [30, 118]]);

        $this->assertSame(['x' => 30, 'y' => 118], $obs->getPosition());
    }

    public function testGetPositionAcceptsObjectShape(): void
    {
        $obs = new AgentObservation(1, ['position' => ['x' => 5, 'y' => 6]]);

        $this->assertSame(['x' => 5, 'y' => 6], $obs->getPosition());
    }

    public function testGetPositionFallsBackToFlatScalars(): void
    {
        $obs = new AgentObservation(1, ['x' => 7, 'y' => 8]);

        $this->assertSame(['x' => 7, 'y' => 8], $obs->getPosition());
    }

    public function testGetPositionIsNullWhenAbsent(): void
    {
        $this->assertNull((new AgentObservation(1, []))->getPosition());
    }

    public function testGetNearbyAgentsReadsObservePayloadKey(): void
    {
        $obs = new AgentObservation(1, ['nearby_agents' => [['id' => 2, 'x' => 3, 'y' => 4]]]);

        $this->assertSame([['id' => 2, 'x' => 3, 'y' => 4]], $obs->getNearbyAgents());
    }

    public function testGetThreatsReadsAlertsKey(): void
    {
        $obs = new AgentObservation(1, ['alerts' => ['incoming']]);

        $this->assertSame(['incoming'], $obs->getThreats());
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

    /**
     * Guards that every response Part's `$fillable` list stays in lock-step with
     * its `*Out` schema in openapi.json. `Deposits` is excluded because it models
     * a single row of `DepositsOut.deposits`, not the envelope.
     *
     * @link https://nha.recluse.lol/openapi.json
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

    public function testDepositsPartModelsOneRow(): void
    {
        $reflection = new ReflectionClass(\NHA\Parts\Deposits::class);
        $actual = $reflection->getProperty('fillable')->getValue($reflection->newInstanceWithoutConstructor());
        sort($actual);

        $this->assertSame(['amount', 'dist', 'id', 'resource', 'x', 'y'], $actual);
    }

    public function testOutBaseKeepsUndeclaredKeysAndSerialisesLosslessly(): void
    {
        $part = (new ReflectionClass(\NHA\Parts\World::class))->newInstanceWithoutConstructor();
        $part->fill(['tick' => 3, 'undocumented' => 'kept']);

        $this->assertSame(3, $part->tick);
        $this->assertSame('kept', $part->undocumented);
        $this->assertSame(['tick' => 3, 'undocumented' => 'kept'], $part->jsonSerialize());
    }
}
