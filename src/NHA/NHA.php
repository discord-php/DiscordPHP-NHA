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
use NHA\Client\Client;
use NHA\Http\Endpoint;
use NHA\Http\Http;
use NHA\Parts\AgentObservation;
use React\Promise\PromiseInterface;

/**
 * The NHA client class.
 *
 * @property Client $client
 * @property Http $nha_http
 * @property AgentObservation|null $cached_observation
 *
 * @version 0.1.0
 */
class NHA extends MessageCommandClient
{
    use HelperTrait;
    use VerbsTrait;

    /**
     * The extended NHA HTTP client.
     *
     * @var Http
     */
    protected $nha_http;

    /**
     * The extended Client class.
     *
     * @var Client
     */
    protected $client;

    /**
     * Local cache of the last observation received per agent id.
     *
     * @var array<int, AgentObservation>
     */
    protected array $observations = [];

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->nha_http = new Http(
            '',
            $this->loop,
            $this->options['logger'] ?? null,
            new React($this->loop, $options['socket_options'] ?? [])
        );

        $this->client = $this->factory->part(Client::class, (array) $this->client);
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
            $this->observations[$agent_id] = $observation;

            return $observation;
        });
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
        return $this->observations[$agent_id] ?? null;
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
     * Gets the client.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Handles dynamic get calls to the client.
     *
     * @param string $name Variable name.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        static $allowed = ['loop', 'options', 'logger', 'http', 'nha_http', 'application_commands'];

        if (in_array($name, $allowed)) {
            return $this->{$name};
        }

        if (null === $this->client) {
            return;
        }

        return $this->client->{$name};
    }
}
