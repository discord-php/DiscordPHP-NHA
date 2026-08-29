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

    public const GITHUB = 'https://github.com/discord-php/DiscordPHP-NHA';

    /**
     * The extended HTTP client used to talk to the NHA world.
     *
     * @var Http
     */
    protected $nha_http;

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

    /**
     * Submits an intent (action) for an agent. The action is only applied
     * on the next world tick.
     *
     * @param int    $agent_id
     * @param string $verb
     * @param array  $args
     *
     * @return PromiseInterface
     */
    public function intent(int $agent_id, string $verb, array $args = []): PromiseInterface
    {
        return $this->nha_http->post(Endpoint::INTENT, [
            'agent' => $agent_id,
            'verb' => $verb,
            'args' => $args,
        ]);
    }

    /**
     * Fetches a read-only endpoint and resolves with the decoded JSON body.
     *
     * @param string $endpoint
     *
     * @return PromiseInterface
     */
    protected function fetch(string $endpoint): PromiseInterface
    {
        return $this->nha_http->get($endpoint);
    }

    public function getWorld(): PromiseInterface
    {
        return $this->fetch(Endpoint::WORLD);
    }

    public function getMap(): PromiseInterface
    {
        return $this->fetch(Endpoint::MAP);
    }

    public function getScene(): PromiseInterface
    {
        return $this->fetch(Endpoint::SCENE);
    }

    public function getStructures(): PromiseInterface
    {
        return $this->fetch(Endpoint::STRUCTURES);
    }

    public function getMarket(): PromiseInterface
    {
        return $this->fetch(Endpoint::MARKET);
    }

    public function getDepot(): PromiseInterface
    {
        return $this->fetch(Endpoint::DEPOT);
    }

    public function getContracts(): PromiseInterface
    {
        return $this->fetch(Endpoint::CONTRACTS);
    }

    public function getChat(): PromiseInterface
    {
        return $this->fetch(Endpoint::CHAT);
    }

    public function getLog(): PromiseInterface
    {
        return $this->fetch(Endpoint::LOG);
    }

    public function getRules(): PromiseInterface
    {
        return $this->fetch(Endpoint::RULES);
    }

    public function getInventors(): PromiseInterface
    {
        return $this->fetch(Endpoint::INVENTORS);
    }

    public function getRecords(): PromiseInterface
    {
        return $this->fetch(Endpoint::RECORDS);
    }

    public function getMilestones(): PromiseInterface
    {
        return $this->fetch(Endpoint::MILESTONES);
    }

    public function getTimeline(): PromiseInterface
    {
        return $this->fetch(Endpoint::TIMELINE);
    }

    public function getRoster(): PromiseInterface
    {
        return $this->fetch(Endpoint::ROSTER);
    }

    public function getGuildPending(): PromiseInterface
    {
        return $this->fetch(Endpoint::GUILD_PENDING);
    }

    /**
     * Fetches public info about any agent by id.
     *
     * @param int $agent_id
     *
     * @return PromiseInterface
     */
    public function getAgentInfo(int $agent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::AGENT)->bindAssoc(['agent_id' => $agent_id]);

        return $this->fetch((string) $endpoint);
    }
}
