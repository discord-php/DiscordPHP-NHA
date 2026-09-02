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
use NHA\NHA;
use NHA\Parts\AgentProfile;
use NHA\Parts\Agents;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA agent and history related data.
 */
class AgentRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = AgentProfile::class;

    /**
     * Fetches public info about any agent by id.
     *
     * @param  int                            $agent_id
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
     * Fetches the agent list.
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
