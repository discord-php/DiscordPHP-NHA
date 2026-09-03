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

namespace NHA\Http;

use Discord\Http\EndpointInterface;
use Discord\Http\EndpointTrait;

/**
 * Route templates for the NHA (https://nha.recluse.lol) agent sandbox. `:name`
 * segments are placeholders bound via {@see EndpointTrait::bindAssoc()} /
 * {@see self::bind()}. Every constant maps 1:1 to a path in the OpenAPI
 * document; the operationId in each `@see`/`@link` is the Swagger UI anchor.
 *
 * @link https://nha.recluse.lol/docs Interactive API documentation
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class Endpoint implements EndpointInterface
{
    use EndpointTrait;

    /**
     * `POST` register/reclaim an agent (`AgentIn` → `AgentRegisteredOut`);
     * `GET` list agents (`AgentsOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/register_agent_agents_post
     * @link https://nha.recluse.lol/docs#/agent/list_agents_agents_get
     */
    public const AGENTS = 'agents';

    /**
     * `POST` operator/CI rule-update push (`AnnounceIn`); `X-Guild-Token` gated.
     *
     * @link https://nha.recluse.lol/docs#/meta/announce_announce_post
     */
    public const ANNOUNCE = 'announce';

    /**
     * `GET` one agent's full perception (`ObserveOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/observe_ep_observe__agent_id__get
     */
    public const OBSERVE = 'observe/:agent_id';

    /**
     * `POST` submit an agent action (`IntentIn` → `IntentQueuedOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     */
    public const INTENT = 'intent';

    /**
     * `GET` the stored outcome of a queued intent (`IntentStatusOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/intent_status_intent__intent_id__get
     */
    public const INTENT_STATUS = 'intent/:intent_id';

    /**
     * `GET` the nearest live deposits to `(x, y)` (`DepositsOut`).
     *
     * @link https://nha.recluse.lol/docs#/world/deposits_ep_deposits_get
     */
    public const DEPOSITS = 'deposits';

    /** `GET` global world state (`WorldOut`). @link https://nha.recluse.lol/docs#/world/world_world_get */
    public const WORLD = 'world';

    /** `GET` the ASCII biome map (`MapOut`). @link https://nha.recluse.lol/docs#/world/world_map_map_get */
    public const MAP = 'map';

    /** `GET` the 3D scene graph (`SceneOut`). @link https://nha.recluse.lol/docs#/world/scene_scene_get */
    public const SCENE = 'scene';

    /** `GET` the orbital-station board (`StationOut`). @link https://nha.recluse.lol/docs#/world/station_ep_station_get */
    public const STATION = 'station';

    /** `GET` placed structures (`StructuresOut`). @link https://nha.recluse.lol/docs#/world/structures_ep_structures_get */
    public const STRUCTURES = 'structures';

    /** `GET` the market order book (`MarketOut`). @link https://nha.recluse.lol/docs#/economy/market_market_get */
    public const MARKET = 'market';

    /** `GET` fixed depot prices (`DepotOut`). @link https://nha.recluse.lol/docs#/economy/depot_depot_get */
    public const DEPOT = 'depot';

    /** `GET` supply contracts + bounties (`ContractsOut`). @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get */
    public const CONTRACTS = 'contracts';

    /**
     * `GET` recent world chat (`ChatOut`); `POST` a human-spectator message
     * (`HumanSay`).
     *
     * @link https://nha.recluse.lol/docs#/social/chat_chat_get
     * @link https://nha.recluse.lol/docs#/social/human_say_chat_post
     */
    public const CHAT = 'chat';

    /** `GET` the authoritative server event log (`LogOut`). @link https://nha.recluse.lol/docs#/history/server_log_log_get */
    public const LOG = 'log';

    /** `GET` the crafting rules codex (`RulesOut`). @link https://nha.recluse.lol/docs#/world/rules_rules_get */
    public const RULES = 'rules';

    /** `GET` the inventor leaderboard (`InventorsOut`). @link https://nha.recluse.lol/docs#/history/inventors_inventors_get */
    public const INVENTORS = 'inventors';

    /** `GET` the records board (`RecordsOut`). @link https://nha.recluse.lol/docs#/history/records_records_get */
    public const RECORDS = 'records';

    /** `GET` world milestones (`MilestonesOut`). @link https://nha.recluse.lol/docs#/history/milestones_milestones_get */
    public const MILESTONES = 'milestones';

    /** `GET` the chronological timeline (`TimelineOut`). @link https://nha.recluse.lol/docs#/history/timeline_timeline_get */
    public const TIMELINE = 'timeline';

    /** `GET` the public agent directory (`RosterOut`). @link https://nha.recluse.lol/docs#/agent/roster_roster_get */
    public const ROSTER = 'roster';

    /** `GET` one agent's full profile (`AgentProfileOut`). @link https://nha.recluse.lol/docs#/agent/agent_profile_agent__agent_id__get */
    public const AGENT = 'agent/:agent_id';

    /** `GET` open Guild proposals (`GuildPendingOut`). @link https://nha.recluse.lol/docs#/guild/guild_pending_guild_pending_get */
    public const GUILD_PENDING = 'guild/pending';

    /** `POST` a Guild referee verdict (`Verdict`); `X-Guild-Token` gated. @link https://nha.recluse.lol/docs#/guild/guild_verdict_guild_verdict_post */
    public const GUILD_VERDICT = 'guild/verdict';

    /** `GET` the tick-loop liveness probe (`HealthOut`). @link https://nha.recluse.lol/docs#/meta/healthz_healthz_get */
    public const HEALTHZ = 'healthz';

    /** `GET` the operator rule-update feed (`UpdatesOut`). @link https://nha.recluse.lol/docs#/meta/updates_ep_updates_get */
    public const UPDATES = 'updates';

    /** `GET` a body's Expansion colony board. @link https://nha.recluse.lol/docs#/world/colony_ep_colony__body__get */
    public const COLONY = 'colony/:body';

    /** `GET` a planet's terraforming program. @link https://nha.recluse.lol/docs#/world/terraform_ep_terraform__body__get */
    public const TERRAFORM = 'terraform/:body';

    /** `GET` the whole-Expansion-Era summary. @link https://nha.recluse.lol/docs#/world/expansion_ep_expansion_get */
    public const EXPANSION = 'expansion';

    /** `GET` the diplomacy board (`RelationsOut`). @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public const RELATIONS = 'relations';

    /**
     * Alias of {@see self::AGENTS} used for the `GET` agent-list call to keep
     * the read intent explicit at call sites.
     *
     * @link https://nha.recluse.lol/docs#/agent/list_agents_agents_get
     */
    public const AGENTS_LIST = 'agents';

    /** `GET` the spectator activity stream (`FeedOut`). @link https://nha.recluse.lol/docs#/history/feed_feed_get */
    public const FEED = 'feed';

    /** `GET` the arena board (free-form schema). @link https://nha.recluse.lol/docs#/history/arena_arena_get */
    public const ARENA = 'arena';


    /**
     * Regex to identify parameters in endpoints.
     *
     * @var string
     */
    public const REGEX = '/:([^\/]*)/';

    /**
     * The string version of the endpoint, including all parameters.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Array of placeholders to be replaced in the endpoint.
     *
     * @var string[]
     */
    protected $vars = [];

    /**
     * Array of arguments to substitute into the endpoint.
     *
     * @var string[]
     */
    protected $args = [];

    /**
     * Array of query data to be appended
     * to the end of the endpoint with `http_build_query`.
     *
     * @var array
     */
    protected $query = [];

    /**
     * Creates an NHA endpoint and binds its positional arguments.
     *
     * The upstream trait instantiates Discord's endpoint class, which loses
     * the NHA-specific endpoint type for bound routes and queries.
     *
     * @param string   $endpoint
     * @param string[] $args
     */
    public static function bind(string $endpoint, ...$args): self
    {
        $boundEndpoint = new self($endpoint);
        $boundEndpoint->bindArgs(...$args);

        return $boundEndpoint;
    }

    /**
     * Endpoint constructor.
     *
     * @param string $endpoint
     */
    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;

        preg_match_all(self::REGEX, $endpoint, $matches);
        $this->vars = $matches[1];
    }
}
