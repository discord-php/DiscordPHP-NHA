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

namespace NHA;

use NHA\Http\Endpoint;
use NHA\Repository\AbstractRepository;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA world state.
 */
class NHARepository extends AbstractRepository
{
    /**
     * @var NHA
     */
    protected NHA $client;

    /**
     * @param NHA $client
     */
    public function __construct(NHA $client)
    {
        $this->client = $client;
    }

    /**
     * Fetches the current world state.
     *
     * @return PromiseInterface
     */
    public function getWorld(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::WORLD);
    }

    /**
     * Fetches the map.
     *
     * @return PromiseInterface
     */
    public function getMap(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MAP);
    }

    /**
     * Fetches the current scene.
     *
     * @return PromiseInterface
     */
    public function getScene(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::SCENE);
    }

    /**
     * Fetches the structures in the scene.
     *
     * @return PromiseInterface
     */
    public function getStructures(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::STRUCTURES);
    }

    /**
     * Fetches the market.
     *
     * @return PromiseInterface
     */
    public function getMarket(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MARKET);
    }

    /**
     * Fetches the depot.
     *
     * @return PromiseInterface
     */
    public function getDepot(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::DEPOT);
    }

    /**
     * Fetches the contracts.
     *
     * @return PromiseInterface
     */
    public function getContracts(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::CONTRACTS);
    }

    /**
     * Fetches the chat.
     *
     * @return PromiseInterface
     */
    public function getChat(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::CHAT);
    }

    /**
     * Fetches the log.
     *
     * @return PromiseInterface
     */
    public function getLog(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::LOG);
    }

    /**
     * Fetches the rules.
     *
     * @return PromiseInterface
     */
    public function getRules(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::RULES);
    }

    /**
     * Fetches the inventors.
     *
     * @return PromiseInterface
     */
    public function getInventors(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::INVENTORS);
    }

    /**
     * Fetches the records.
     *
     * @return PromiseInterface
     */
    public function getRecords(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::RECORDS);
    }

    /**
     * Fetches the milestones.
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

    /**
     * Fetches the roster.
     *
     * @return PromiseInterface
     */
    public function getRoster(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::ROSTER);
    }

    /**
     * Fetches the guild pending information.
     *
     * @return PromiseInterface
     */
    public function getGuildPending(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::GUILD_PENDING);
    }

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
     * Checks the status/outcome of a previously submitted intent.
     *
     * @param int $intent_id The ID of the intent to check.
     * @return PromiseInterface
     */
    public function getIntentStatus(int $intent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::INTENT_STATUS)->bindAssoc(['intent_id' => $intent_id]);

        return $this->client->fetch((string) $endpoint);
    }

    /**
     * Fetches nearby deposits.
     *
     * @param float $x
     * @param float $y
     * @param int $radius
     * @param string|null $resource
     * @param int $limit
     * @return PromiseInterface
     */
    public function getDeposits(float $x, float $y, int $radius = 100, ?string $resource = null, int $limit = 10): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::DEPOSITS);

        return $this->client->getNhaHttpClient()->get((string) $endpoint, [
            'x' => (string) $x,
            'y' => (string) $y,
            'radius' => (string) $radius,
            'resource' => $resource,
            'limit' => (string) $limit,
        ]);
    }





}
