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
use NHA\NHARepository;
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
     * @var NHARepository
     */
    protected $repo;

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

        $this->repo = new NHARepository($this);
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
     * Gets the repository for querying NHA world state.
     *
     * @return NHARepository
     */
    public function getRepo(): NHARepository
    {
        return $this->repo;
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
        ])->then(fn ($response) => $response->agent_id);
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
