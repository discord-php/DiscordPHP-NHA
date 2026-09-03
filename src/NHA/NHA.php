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

use Discord\Http\Drivers\Guzzle;
use Discord\MessageCommandClient;
use Discord\Parts\User\Client as DiscordClient;
use Discord\Repository\EmojiRepository;
use Discord\Repository\GuildRepository;
use Discord\Repository\LobbyRepository;
use Discord\Repository\PrivateChannelRepository;
use Discord\Repository\SoundRepository;
use Discord\Repository\StickerPackRepository;
use Discord\Repository\UserRepository;
use NHA\Http\Endpoint;
use NHA\Http\Http;
use NHA\Parts\AgentObservation;
use React\Promise\PromiseInterface;
use NHA\Repository\AgentRepository;
use NHA\Repository\DepositsRepository;
use NHA\Repository\EconomyRepository;
use NHA\Repository\HistoryRepository;
use NHA\Repository\IntentRepository;
use NHA\Repository\MetaRepository;
use NHA\Repository\SocialRepository;
use NHA\Repository\WorldRepository;

/**
 * The NHA client class — a DiscordPHP {@see MessageCommandClient} extended with
 * an async HTTP client for the No-Human-Allowed MMO world API and typed
 * wrappers for its registration, observation and intent endpoints. Read-only
 * boards are exposed through the repositories on {@see Client}.
 *
 * @link https://nha.recluse.lol Live world
 * @link https://nha.recluse.lol/docs Interactive API documentation
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 * @link https://nha.recluse.lol/AGENTS.md Agent API reference
 *
 * @property Client $client
 * @property Http   $nha_http
 *
 * @property EmojiRepository          $emojis
 * @property GuildRepository          $guilds
 * @property LobbyRepository          $lobbies
 * @property PrivateChannelRepository $private_channels
 * @property SoundRepository          $sounds
 * @property StickerPackRepository    $sticker_packs
 * @property UserRepository           $users
 * @property AgentRepository          $agents
 * @property DepositsRepository       $deposits
 * @property EconomyRepository        $economy
 * @property HistoryRepository        $history
 * @property IntentRepository         $intents
 * @property MetaRepository           $meta
 * @property SocialRepository         $social
 * @property WorldRepository          $world
 *
 * @version 0.1.0
 */
class NHA extends MessageCommandClient
{
    use HelperTrait;
    use VerbsTrait;

    /**
     * The extended Client class.
     *
     * @var Client
     */
    protected $client;

    /**
     * Whether `$client` has already been upgraded to `NHA\Client`.
     *
     * @var bool
     */
    protected bool $clientUpgraded = false;

    /**
     * In-memory cache of the last observation received per agent id. This is a
     * last-known-state convenience only (read via {@see getCachedObservation()});
     * it is not durable and is not a repository. It holds one entry per distinct
     * agent id ever observed — bounded in practice by how many agents a single
     * bot process drives.
     *
     * @var array<int, AgentObservation>
     */
    protected array $observations = [];

    /**
     * Optional durable store. When set (see {@see setStateStore()}), every
     * {@see observe()} snapshots the agent's world position + tick into it, so
     * position survives restarts and is refreshed no matter which caller
     * triggered the observe (command, refresh button, or the relay poll loop).
     *
     * @var StateStore|null
     */
    protected ?StateStore $stateStore = null;

    /**
     * NHA action token for the current agent, if configured.
     *
     * This is distinct from the Discord bot token.
     *
     * @var string
     */
    protected string $agentToken = '';

    public function __construct(array $options = [])
    {
        $this->agentToken = (string) ($options['nha_token'] ?? '');
        unset($options['nha_token']);

        parent::__construct($options);

        // The react/socket driver hangs indefinitely against the live NHA host (TLS renegotiation is never completed).
        $this->nha_http = new Http(
            '',
            $this->loop,
            $this->options['logger'] ?? null,
            new Guzzle($this->loop, $options['socket_options'] ?? []),
        );

        $this->ensureClient();
    }

    /**
     * Ensures the internal NHA client registry exists even before Discord bootstraps it.
     */
    protected function ensureClient(): void
    {
        if ($this->clientUpgraded) {
            return;
        }
        $this->clientUpgraded = true;

        // Discord's own constructor already created a base Client and kicked off its
        // `applications/@me` fetch; capture it so its resolved Application can be
        // mirrored below instead of NHA\Client issuing a second, racy fetch.
        $bootstrapClient = $this->client;

        $this->client = $this->factory->part(Client::class, []);

        $this->once('application-init', function () use ($bootstrapClient): void {
            if ($bootstrapClient instanceof DiscordClient && ($application = $bootstrapClient->application ?? null)) {
                $this->client->application = $application;
            }
        });
    }

    /**
     * Keeps `$client` pinned to the upgraded `NHA\Client` once set, ignoring Discord's
     * own bootstrap Client resolving afterwards and trying to reclaim the pointer
     * (which would silently drop the NHA repositories and re-trigger `ensureClient()`
     * on the next magic property access).
     */
    public function setClient(DiscordClient $client): void
    {
        if ($this->clientUpgraded && ! $client instanceof Client) {
            return;
        }

        parent::setClient($client);
    }

    /**
     * The default starting materials for a new agent.
     */
    public const DEFAULT_MATERIALS = ['metal' => 40, 'credits' => 150];

    /**
     * Registers a new agent (`POST /agents`, body `AgentIn`).
     *
     * @link https://nha.recluse.lol/docs#/agent/register_agent_agents_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/AgentIn
     *
     * @param string $name
     * @param array  $materials e.g. ['metal' => 40, 'credits' => 150]
     *
     * @return PromiseInterface<int> Resolves with the new agent id.
     */
    public function registerAgent(string $name, array $materials = self::DEFAULT_MATERIALS): PromiseInterface
    {
        return $this->registerAgentIdentity($name, $materials)->then(
            fn(array $identity) => $identity['agent_id'],
        );
    }

    /**
     * Reclaims an existing agent after a restart, using its saved name + token
     * (`POST /agents` with `reuse: true`). Never creates a new agent.
     *
     * @link https://nha.recluse.lol/docs#/agent/register_agent_agents_post
     *
     * @param string $name  The agent's original name.
     * @param string $token The persistent token returned by the first registration.
     *
     * @return PromiseInterface<array{agent_id: int, name: string, token: string, reused: bool, spawn: ?array}>
     */
    public function reclaimAgentIdentity(string $name, string $token): PromiseInterface
    {
        return $this->registerAgentIdentity($name, [], true, $token);
    }

    /**
     * Registers (or, with `$reuse`, reclaims) an agent and returns its durable
     * identity data (`POST /agents` → `AgentRegisteredOut`).
     *
     * `reused` is the discriminator: true means an existing agent was returned
     * and nothing was created. On the reuse path `token` is only echoed back to
     * a caller that already proved it, so it may be empty — keep your saved copy.
     *
     * @link https://nha.recluse.lol/docs#/agent/register_agent_agents_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/AgentRegisteredOut
     *
     * @param string $name      Agent display name (public).
     * @param array  $materials Starting material buffers; empty uses the server default.
     * @param bool   $reuse     Reclaim an existing agent instead of spawning one.
     * @param string $token     Persistent token, required when `$reuse` is true.
     *
     * @return PromiseInterface<array{agent_id: int, name: string, token: string, reused: bool, spawn: ?array}>
     */
    public function registerAgentIdentity(string $name, array $materials = [], bool $reuse = false, string $token = ''): PromiseInterface
    {
        $body = [
            'name' => $name,
            'materials' => $materials === [] ? new \stdClass() : $materials,
        ];

        if ($reuse) {
            $body['reuse'] = true;
        }
        if ($token !== '') {
            $body['token'] = $token;
        }

        return $this->nha_http->post(Endpoint::AGENTS, $body)->then(function ($response) use ($name, $token): array {
            $response = (array) $response;

            if (! isset($response['agent_id'])) {
                throw new \UnexpectedValueException('NHA registration response did not include an agent_id.');
            }

            return [
                'agent_id' => (int) $response['agent_id'],
                'name' => (string) ($response['name'] ?? $name),
                'token' => (string) ($response['token'] ?? $token),
                'reused' => (bool) ($response['reused'] ?? false),
                'spawn' => isset($response['spawn']) ? (array) $response['spawn'] : null,
            ];
        });
    }

    /**
     * Gets the configured NHA action token.
     */
    public function getAgentToken(): string
    {
        return $this->agentToken;
    }

    /**
     * Sets the NHA action token used for intents that don't supply their own.
     */
    public function setAgentToken(string $token): void
    {
        $this->agentToken = $token;
    }

    /**
     * Queues an intent using the supplied agent-specific NHA token
     * (`POST /intent`, body `IntentIn`). Resolves with the `IntentQueuedOut`
     * body (`queued_intent`, `tick`, `note`) — the intent is queued, not
     * applied; poll {@see \NHA\Repository\IntentRepository::getIntentStatus()}.
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/IntentIn
     *
     * @return PromiseInterface
     */
    public function intentWithToken(int $agent_id, string $token, string $verb, array $args = []): PromiseInterface
    {
        return $this->nha_http->post(Endpoint::INTENT, [
            'agent' => $agent_id,
            'verb' => $verb,
            'args' => $args,
            'token' => $token,
        ]);
    }

    /**
     * Observes the world from an agent's perspective
     * (`GET /observe/{agent_id}` → `ObserveOut`).
     *
     * Every call refreshes the in-memory {@see getCachedObservation()} snapshot
     * and, when a {@see StateStore} has been attached via {@see setStateStore()},
     * durably records the agent's position + tick. Routing that write through
     * this one method keeps it consistent across every caller — slash/prefix
     * commands, the in-message refresh buttons and the relay poll loop.
     *
     * @link https://nha.recluse.lol/docs#/agent/observe_ep_observe__agent_id__get
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/ObserveOut
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
            $this->stateStore?->recordObservation($agent_id, $observation);

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
     * Attaches the durable {@see StateStore} that {@see observe()} snapshots
     * agent position into. Idempotent; pass `null` to detach.
     */
    public function setStateStore(?StateStore $store): void
    {
        $this->stateStore = $store;
    }

    /**
     * Gets the attached durable state store, if any.
     */
    public function getStateStore(): ?StateStore
    {
        return $this->stateStore;
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

        $this->ensureClient();

        return $this->client->{$name} ?? null;
    }

    /**
     * Determines if a property is set.
     *
     * @param string $name Variable name.
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        static $allowed = ['loop', 'options', 'logger', 'http', 'nha_http', 'application_commands'];

        if (in_array($name, $allowed)) {
            return isset($this->{$name});
        }

        if (null === $this->client) {
            $this->ensureClient();
        }

        if (null === $this->client) {
            return false;
        }

        return isset($this->client->{$name});
    }

}
