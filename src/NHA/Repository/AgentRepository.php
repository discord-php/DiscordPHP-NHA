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
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA agent and history related data.
 */
class AgentRepository extends AbstractRepository
{
    /**
     * Fetches public info about any agent by id.
     *
     * @param int $agent_id
     * @return PromiseInterface
     */
    public function getAgentInfo(int $agent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::AGENT)->bindAssoc(['agent_id' => $agent_id]);

        return $this->nha_http->get($endpoint);
    }

    /**
     * Fetches the agent list.
     *
     * @return PromiseInterface
     */
    public function getAgents(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::AGENTS_LIST);
    }

    /**
     * Fetches the roster.
     *
     * @return PromiseInterface
     */
    public function getRoster(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::ROSTER);
    }
}
