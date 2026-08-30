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

/**
 * @deprecated This class has been decomposed into specialized repositories in src/NHA/Repositories/
 * Use the specific repository getters on the NHA client instead.
 */
class NHARepository
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
     * @return \React\Promise\PromiseInterface
     */
    public function getWorld(): \React\Promise\PromiseInterface
    {
        return $this->client->getWorldRepo()->getWorld();
    }

    /**
     * Fetches the map.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getMap(): \React\Promise\PromiseInterface
    {
        return $this->client->getWorldRepo()->getMap();
    }

    /**
     * Fetches the current scene.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getScene(): \React\Promise\PromiseInterface
    {
        return $this->client->getWorldRepo()->getScene();
    }

    /**
     * Fetches the structures in the scene.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getStructures(): \React\Promise\PromiseInterface
    {
        return $this->client->getWorldRepo()->getStructures();
    }

    /**
     * Fetches the market.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getMarket(): \React\Promise\PromiseInterface
    {
        return $this->client->getEconomyRepo()->getMarket();
    }

    /**
     * Fetches the depot.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getDepot(): \React\Promise\PromiseInterface
    {
        return $this->client->getEconomyRepo()->getDepot();
    }

    /**
     * Fetches the contracts.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getContracts(): \React\Promise\PromiseInterface
    {
        return $this->client->getEconomyRepo()->getContracts();
    }

    /**
     * Fetches the chat.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getChat(): \React\Promise\PromiseInterface
    {
        return $this->client->getSocialRepo()->getChat();
    }

    /**
     * Fetches the log.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getLog(): \React\Promise\PromiseInterface
    {
        return $this->client->getSocialRepo()->getLog();
    }

    /**
     * Fetches the rules.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getRules(): \React\Promise\PromiseInterface
    {
        return $this->client->getSocialRepo()->getRules();
    }

    /**
     * Fetches the inventors.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getInventors(): \React\Promise\PromiseInterface
    {
        return $this->client->getAgentRepo()->getInventors();
    }

    /**
     * Fetches the records.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getRecords(): \React\Promise\PromiseInterface
    {
        return $this->client->getAgentRepo()->getRecords();
    }

    /**
     * Fetches the milestones.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getMilestones(): \React\Promise\PromiseInterface
    {
        return $this->client->getAgentRepo()->getMilestones();
    }

    /**
     * Fetches the timeline.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getTimeline(): \React\Promise\PromiseInterface
    {
        return $this->client->getAgentRepo()->getTimeline();
    }

    /**
     * Fetches the roster.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getRoster(): \React\Promise\PromiseInterface
    {
        return $this->client->getSocialRepo()->getRoster();
    }

    /**
     * Fetches the guild pending information.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function getGuildPending(): \React\Promise\PromiseInterface
    {
        return $this->client->getSocialRepo()->getGuildPending();
    }

    /**
     * Fetches public info about any agent by id.
     *
     * @param int $agent_id
     * @return \React\Promise\PromiseInterface
     */
    public function getAgentInfo(int $agent_id): \React\Promise\PromiseInterface
    {
        return $this->client->getAgentRepo()->getAgentInfo($agent_id);
    }

    /**
     * Checks the status/outcome of a previously submitted intent.
     *
     * @param int $intent_id The ID of the intent to check.
     * @return \React\Promise\PromiseInterface
     */
    public function getIntentStatus(int $intent_id): \React\Promise\PromiseInterface
    {
        return $this->client->getIntentRepo()->getIntentStatus($intent_id);
    }

    /**
     * Fetches nearby deposits.
     *
     * @param float $x
     * @param float $y
     * @param int $radius
     * @param string|null $resource
     * @param int $limit
     * @return \React\Promise\PromiseInterface
     */
    public function getDeposits(float $x, float $y, int $radius = 100, ?string $resource = null, int $limit = 10): \React\Promise\PromiseInterface
    {
        return $this->client->getDiscoveryRepo()->getDeposits($x, $y, $radius, $resource, $limit);
    }
}
