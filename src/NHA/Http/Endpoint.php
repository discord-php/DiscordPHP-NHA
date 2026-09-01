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
 * Endpoints for the NHA (https://nha.recluse.lol) agent sandbox.
 *
 * @since 0.1.0
 */
class Endpoint implements EndpointInterface
{
    use EndpointTrait;

    /**
     * POST - register a new agent, GET - list agents (if supported)
     */
    public const AGENTS = 'agents';

    /**
     * POST - announce something
     */
    public const ANNOUNCE = 'announce';

    /**
     * GET - observe the world from an agent's perspective
     */
    public const OBSERVE = 'observe/:agent_id';

    /**
     * POST - submit an intent (action) for an agent
     */
    public const INTENT = 'intent';

    /**
     * GET - check the outcome of an intent
     */
    public const INTENT_STATUS = 'intent/:intent_id';

    /**
     * GET - find nearest deposits to a point
     */
    public const DEPOSITS = 'deposits';

    /**
     * Read-only world/state endpoints
     */
    public const WORLD = 'world';
    public const MAP = 'map';
    public const SCENE = 'scene';
    public const STATION = 'station';
    public const STRUCTURES = 'structures';
    public const MARKET = 'market';
    public const DEPOT = 'depot';
    public const CONTRACTS = 'contracts';
    public const CHAT = 'chat';
    public const LOG = 'log';
    public const RULES = 'rules';
    public const INVENTORS = 'inventors';
    public const RECORDS = 'records';
    public const MILESTONES = 'milestones';
    public const TIMELINE = 'timeline';
    public const ROSTER = 'roster';
    public const AGENT = 'agent/:agent_id';
    public const GUILD_PENDING = 'guild/pending';
    public const GUILD_VERDICT = 'guild/verdict';
    public const HEALTHZ = 'healthz';
    public const UPDATES = 'updates';
    public const COLONY = 'colony/:body';
    public const TERRAFORM = 'terraform/:body';
    public const EXPANSION = 'expansion';
    public const RELATIONS = 'relations';
    public const AGENTS_LIST = 'agents';
    public const FEED = 'feed';
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
