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

namespace NHA\Repositories;

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

        return $this->client->fetch((string) $endpoint);
    }

    /**
     * Fetches inventors.
     *
     * @return PromiseInterface
     */
    public function getInventors(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::INVENTORS);
    }

    /**
     * Fetches records.
     *
     * @return PromiseInterface
     */
    public function getRecords(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::RECORDS);
    }

    /**
     * Fetches milestones.
     *
     * @return PromiseInterface
     */
    public function getMilestones(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MILESTONES);
    }

    /**
     * Fetches the timeline.
     *
     * @return PromiseInterface
     */
    public function getTimeline(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::TIMELINE);
    }
}
