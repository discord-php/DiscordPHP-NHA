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

use Discord\Http\Drivers\React;
use Discord\MessageCommandClient;
use NHA\Http\Endpoint;
use NHA\Http\Http;
use NHA\Parts\AgentObservation;
use NHA\Repository\AgentRepository;
use NHA\Repository\DiscoveryRepository;
use NHA\Repository\EconomyRepository;
use NHA\Repository\IntentRepository;
use NHA\Repository\SocialRepository;
use NHA\Repository\WorldRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Client for the NHA (https://nha.recluse.lol) agent sandbox, layered on
 * top of a DiscordPHP `MessageCommandClient` so the bot can relay world
 * data into Discord via chat commands, slash commands and components.
 *
 * @version 0.1.0
 */
class NHA extends MessageCommandClient
{
    use HelperTrait;
    use VerbsTrait;

    /**
     * The repository for querying NHA world state.
     *
     * @var WorldRepository
     */
    protected WorldRepository $world_repo;

    /**
     * The repository for querying NHA economy related data.
     *
     * @var EconomyRepository
     */
    protected EconomyRepository $economy_repo;

    /**
     * The repository for querying NHA social and communication data.
     *
     * @var SocialRepository
     */
    protected SocialRepository $social_repo;

    /**
     * The repository for querying NHA agent and history related data.
     *
     * @var AgentRepository
     */
    protected AgentRepository $agent_repo;

    /**
     * The repository for querying NHA discovery and intent related data.
     *
     * @var DiscoveryRepository
     */
    protected DiscoveryRepository $discovery_repo;

    /**
     * The repository for querying NHA intent related data.
     *
     * @var IntentRepository
     */
    protected IntentRepository $intent_repo;

    /**
     * Local cache of the last observation received per agent id.
     *
     * @var AgentObservation[]
     */
    protected array $agents = [];

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->nha_http = new Http(
            '',
            $this->loop,
            $this->options['logger'],
            new React($this->loop, $options['socket_options'] ?? [])
        );

        $this->world_repo = new WorldRepository($this);
        $this->economy_repo = new EconomyRepository($this);
        $this->social_repo = new SocialRepository($this);
        $this->agent_repo = new AgentRepository($this);
        $this->discovery_repo = new DiscoveryRepository($this);
        $this->intent_repo = new IntentRepository($this);
    }

    /**
     * Gets the NHA HTTP client.
     *
     * @return Http
     */
    public function getNhaHttpClient(): Http
    {
        return $this->nha_http;
    }

    /**
     * Gets the world repository.
     *
     * @return WorldRepository
     */
    public function getWorldRepo(): WorldRepository
    {
        return $this->world_repo;
    }

    /**
     * Gets the economy repository.
     *
     * @return EconomyRepository
     */
    public function getEconomyRepo(): EconomyRepository
    {
        return $this->economy_repo;
    }

    /**
     * Gets the social repository.
     *
     * @return SocialRepository
     */
    public function getSocialRepo(): SocialRepository
    {
        return $this->social_repo;
    }

    /**
     * Gets the agent repository.
     *
     * @return AgentRepository
     */
    public function getAgentRepo(): AgentRepository
    {
        return $this->agent_repo;
    }

    /**
     * Gets the discovery repository.
     *
     * @return DiscoveryRepository
     */
    public function getDiscoveryRepo(): DiscoveryRepository
    {
        return $this->discovery_repo;
    }

    /**
     * Gets the intent repository.
     *
     * @return IntentRepository
     */
    public function getIntentRepo(): IntentRepository
    {
        return $this->intent_repo;
    }

    /**
     * Gets the last cached observation for an agent, if any.
     *
     * @param int $agent_id
     *
     * @return AgentObservation|null
     */
    public function getCachedObservation(int $agent_id): ?AgentObservation
    {
        return $this->agents[$agent_id] ?? null;
    }

    /**
     * Registers a new agent.
     *
     * @param string $name
     * @param array  $materials e.g. ['metal' => 40, 'credits' => 150]
     *
     * @return PromiseInterface<int> Resolves with the new agent id.
     */
    public function registerAgent(string $name, array $materials = []): PromiseInterface
    {
        return $this->nha_http->post(Endpoint::AGENTS, [
            'name' => $name,
            'materials' => $materials,
        ])->then(fn($response) => $response->agent_id);
    }

    /**
     * Observes the world from an agent's perspective.
     *
     * @param int $agent_id
     *
     * @return PromiseInterface<AgentObservation>
     */
    public function observe(int $agent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::OBSERVE)->bindAssoc(['agent_id' => $agent_id]);

        return $this->nha_http->get($endpoint)->then(function ($response) use ($agent_id) {
            $observation = new AgentObservation($agent_id, (array) $response);
            $this->agents[$agent_id] = $observation;

            return $observation;
        });
    }
}
