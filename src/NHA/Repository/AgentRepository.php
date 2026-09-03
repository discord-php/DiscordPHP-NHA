<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace NHA\Repository;

use NHA\Http\Endpoint;
use NHA\Parts\AgentProfile;
use NHA\Parts\Agents;
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `agent` read endpoints: a single agent's full profile
 * ({@see getAgentInfo()}) and the live agent list ({@see getAgents()}).
 *
 * Registration (`POST /agents`) and actions (`POST /intent`) live on
 * {@see \NHA\NHA} and {@see \NHA\VerbsTrait} because they are token-authenticated
 * writes rather than Part reads.
 *
 * @link https://nha.recluse.lol/docs#/agent Interactive API documentation (agent tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class AgentRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = AgentProfile::class;

    /**
     * Fetches one agent's full story — stats, inventory, vehicles, discoveries
     * and milestone timeline (`GET /agent/{agent_id}` → `AgentProfileOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/agent_profile_agent__agent_id__get
     *
     * @param int $agent_id The agent whose profile to fetch.
     *
     * @return PromiseInterface<AgentProfile>
     */
    public function getAgentInfo(int $agent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::AGENT)->bindAssoc(['agent_id' => $agent_id]);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(AgentProfile::class, (array) $data, true),
        );
    }

    /**
     * Fetches the live agent list and current tick
     * (`GET /agents` → `AgentsOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/list_agents_agents_get
     *
     * @return PromiseInterface<Agents>
     */
    public function getAgents(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::AGENTS_LIST)->then(
            fn($data) => $this->factory->part(Agents::class, (array) $data, true),
        );
    }
}
