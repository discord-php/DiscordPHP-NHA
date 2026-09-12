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
use NHA\Http\Http;
use React\Promise\PromiseInterface;

/**
 * Typed convenience wrappers over {@see NHA::intent()} for the documented agent
 * verbs. Each method forwards to `intent()` unchanged and exists so callers
 * (and IDEs/LLMs) get discoverable, self-documenting signatures.
 *
 * All verbs are submitted through `POST /intent` (schema `IntentIn`); the
 * response is an `IntentQueuedOut` body — the intent is only QUEUED, never
 * applied inline. Poll {@see \NHA\Repository\IntentRepository::getIntentStatus()}
 * with its `queued_intent` id once the world tick has advanced.
 *
 * `IntentIn` does not enumerate per-verb argument schemas; the authoritative
 * source for verb names, argument keys and gates is the live game — verify
 * against `/openapi.json`, `/docs` and `/rules` before changing a wrapper.
 *
 * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post Submit-intent endpoint
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/IntentIn
 * @link https://nha.recluse.lol/AGENTS.md Agent API reference (verb catalogue)
 * @link https://nha.recluse.lol/rules Crafting/economy rules codex
 *
 * @since 3.0.0
 */
trait VerbsTrait
{
    /**
     * The extended HTTP client used to talk to the NHA world.
     *
     * @var Http
     */
    protected $nha_http;

    /**
     * Submits an intent (action) for an agent. The action is only applied on a
     * later world tick — a resolved promise means "queued", not "succeeded".
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     *
     * @param int    $agent_id The acting agent.
     * @param string $verb     Server-side verb name (used verbatim).
     * @param array  $args     Verb argument object.
     *
     * @return PromiseInterface Resolves with the `IntentQueuedOut` body (`queued_intent`, `tick`, `note`).
     */
    public function intent(int $agent_id, string $verb, array $args = []): PromiseInterface
    {
        $payload = [
            'agent' => $agent_id,
            'verb' => $verb,
            // Cast so an empty arg set serialises as `{}` (a JSON object) rather
            // than `[]`; the NHA API rejects a list here with a 422 dict_type.
            'args' => (object) $args,
        ];

        if (method_exists($this, 'getAgentToken') && $token = $this->getAgentToken()) {
            $payload['token'] = $token;
        }

        return $this->nha_http->post(Endpoint::INTENT, $payload);
    }

    // --- Move & gather -------------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: move, mine, chop, gather, plant

    /** Steps the agent by `(dx, dy)` grid cells. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function move(int $agent_id, int $dx, int $dy): PromiseInterface
    {
        return $this->intent($agent_id, 'move', ['dx' => $dx, 'dy' => $dy]);
    }

    /** Walks toward absolute cell `(x, y)` (the `move{x,y}` form). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function moveTo(int $agent_id, int $x, int $y): PromiseInterface
    {
        return $this->intent($agent_id, 'move', ['x' => $x, 'y' => $y]);
    }

    /**
     * Mines the nearest mineral within 8 cells, up to `n` units, optionally
     * restricted to one `resource`.
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     */
    public function mine(int $agent_id, int $n = 1, ?string $resource = null): PromiseInterface
    {
        return $this->intent($agent_id, 'mine', array_filter([
            'n' => $n,
            'resource' => $resource,
        ], fn($v) => null !== $v));
    }

    /** Chops the tree/plant under the agent, up to `n` units. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function chop(int $agent_id, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'chop', ['n' => $n]);
    }

    /** Gathers loose resources at the agent's tile, up to `n` units. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function gather(int $agent_id, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'gather', ['n' => $n]);
    }

    /** Tops up the most-drained tree on the agent's cell (cap 22), 1 wood; rejected if all full (verb `plant`). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function plantTree(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'plant', []);
    }

    // --- Craft & build ----------------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: combine, build, finalize, deploy, construct

    /**
     * Combines `ingredients` (a `{resource: qty}` map) by their physics tags:
     * a known recipe crafts up to `n` copies, an unknown mixture is escrowed to
     * the Inventors' Guild. `name` is an optional label for a novel item.
     *
     * @link https://nha.recluse.lol/rules
     */
    public function combine(int $agent_id, array $ingredients, ?string $name = null, ?int $n = null): PromiseInterface
    {
        return $this->intent($agent_id, 'combine', array_filter([
            'ingredients' => $ingredients,
            'name' => $name,
            'n' => $n,
        ], fn($v) => null !== $v));
    }

    /** Builds one vehicle `part`, optionally with up to 3 `with` upgrade items. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function build(int $agent_id, string $part, array $with = []): PromiseInterface
    {
        return $this->intent($agent_id, 'build', array_filter([
            'part' => $part,
            'with' => $with ?: null,
        ], fn($v) => null !== $v));
    }

    /** Assembles all loose parts into one finished vehicle (optionally named `name`). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function finalize(int $agent_id, ?string $name = null): PromiseInterface
    {
        return $this->intent($agent_id, 'finalize', null === $name ? [] : ['name' => $name]);
    }

    /**
     * Places a structure. `$shape` is one of the `construct` shape allowlist
     * (`box`/`cylinder`/`sphere`/`cone`/`pyramid`/`elevator`/`station`/
     * `ziggurat`/`monument`/`road`/`city`/`colony`/`terraform`/`extractor`);
     * `$args` carries the shape-specific keys (`size`/`height`/`color`,
     * `module`, `kind`, `body`, `stage`, `w`/`h`, …). Gates live in the rules.
     *
     * @link https://nha.recluse.lol/rules
     *
     * @param array<string, mixed> $args
     */
    public function construct(int $agent_id, string $shape, array $args = []): PromiseInterface
    {
        return $this->intent($agent_id, 'construct', ['shape' => $shape] + $args);
    }

    /**
     * Bankrolls a `module` with `credits`. Without `$body` that is a Station
     * module (space era); WITH `$body` it funds that body's COLONY module from
     * anywhere — no need to be standing on the body. Only the fixed-price
     * industrial lines can be bought this way; every exotic line
     * (`c_regolith`, `cloud_acid`, …) still has to be mined on the surface.
     *
     * @link https://nha.recluse.lol/docs#/world/station_ep_station_get
     *
     * @since 3.5.0 the `$body` (colony) form
     */
    public function invest(int $agent_id, string $module, int $credits, ?string $resource = null, ?string $body = null): PromiseInterface
    {
        return $this->intent($agent_id, 'invest', array_filter([
            'body' => $body,
            'module' => $module,
            'credits' => $credits,
            'resource' => $resource,
        ], fn($v) => null !== $v));
    }

    /** Mounts the vehicle at the agent's tile. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function ride(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'ride', []);
    }

    /** Deploys a carried deployable (e.g. extractor). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function deploy(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'deploy', []);
    }

    // --- Fly & space ----------------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: launch, land, land_moon, ride, dock

    /** Launches the agent's aircraft/rocket upward. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function launch(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'launch', []);
    }

    /** Lands the agent's aircraft at the current position. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function land(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'land', []);
    }

    /** Lands on the Moon surface (verb `land_moon`). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function landMoon(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'land_moon', []);
    }

    /** Docks with an asteroid / the orbital station. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function dock(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'dock', []);
    }

    // --- Expansion era (Season 5) -------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: depart, land_body, distress

    /**
     * Commits a ship to an interplanetary transfer (verb `depart`, arg `dest`).
     * Needs a flying ship with an `ion_thruster` + fuel, launched from Earth
     * orbit while the destination's window is open; Mars/Venus also need a
     * `heat_shield` in hold (+`acid_skin` for Venus). Pass `'earth'` to return.
     *
     * @link https://nha.recluse.lol/rules
     *
     * @param string $dest One of `deimos`/`phobos`/`mars`/`venus`, or `earth` to come home.
     */
    public function depart(int $agent_id, string $dest): PromiseInterface
    {
        return $this->intent($agent_id, 'depart', ['dest' => $dest]);
    }

    /** Lands on the body the agent has travelled to (verb `land_body`). @link https://nha.recluse.lol/rules */
    public function landBody(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'land_body', []);
    }

    /**
     * Emergency recovery for a stranded off-world agent (verb `distress`) —
     * not a substitute for planned return delta-v.
     *
     * @link https://nha.recluse.lol/rules
     */
    public function distress(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'distress', []);
    }

    // --- Trade -----------------------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: sell, buy, order, cancel, trade, accept, deposit

    /** Sells `n` of `resource` to the depot at the fixed price. @link https://nha.recluse.lol/docs#/economy/depot_depot_get */
    public function sell(int $agent_id, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'sell', ['resource' => $resource, 'n' => $n]);
    }

    /** Buys `n` of `resource` from the depot at the fixed price. @link https://nha.recluse.lol/docs#/economy/depot_depot_get */
    public function buy(int $agent_id, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'buy', ['resource' => $resource, 'n' => $n]);
    }

    /** Posts a market order (`side` = buy|sell). @link https://nha.recluse.lol/docs#/economy/market_market_get */
    public function order(int $agent_id, string $side, string $resource, int $qty, float $price): PromiseInterface
    {
        return $this->intent($agent_id, 'order', ['side' => $side, 'resource' => $resource, 'qty' => $qty, 'price' => $price]);
    }

    /** Cancels one of the agent's open market orders (verb `cancel`). @link https://nha.recluse.lol/docs#/economy/market_market_get */
    public function cancelOrder(int $agent_id, mixed $order_id): PromiseInterface
    {
        return $this->intent($agent_id, 'cancel', ['order_id' => $order_id]);
    }

    /** Offers a peer-to-peer trade: `give` these, `want` those. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function trade(int $agent_id, int $to, array $give, array $want): PromiseInterface
    {
        return $this->intent($agent_id, 'trade', ['to' => $to, 'give' => $give, 'want' => $want]);
    }

    /** Accepts an incoming peer-to-peer trade (verb `accept`). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function acceptTrade(int $agent_id, mixed $trade_id): PromiseInterface
    {
        return $this->intent($agent_id, 'accept', ['trade_id' => $trade_id]);
    }

    /**
     * Deposits `n` of `resource` into shared/escrow storage (verb `deposit`).
     * Argument shape follows the live rules — verify before relying on it.
     *
     * @link https://nha.recluse.lol/rules
     */
    public function deposit(int $agent_id, string $resource, int $n): PromiseInterface
    {
        return $this->intent($agent_id, 'deposit', ['resource' => $resource, 'n' => $n]);
    }

    // --- Contracts & bounties -----------------------------------------------------
    // @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get

    /** Posts a supply contract offering `reward` for `want`. @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get */
    public function contract(int $agent_id, mixed $reward, array $want, ?int $to = null, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->intent($agent_id, 'contract', array_filter([
            'reward' => $reward,
            'want' => $want,
            'to' => $to,
            'deadline_ticks' => $deadline_ticks,
        ], fn($v) => null !== $v));
    }

    /** Fulfils an open supply contract. @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get */
    public function fulfill(int $agent_id, mixed $contract_id): PromiseInterface
    {
        return $this->intent($agent_id, 'fulfill', ['contract_id' => $contract_id]);
    }

    /** Revokes one of the agent's own open contracts. @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get */
    public function revoke(int $agent_id, mixed $contract_id): PromiseInterface
    {
        return $this->intent($agent_id, 'revoke', ['contract_id' => $contract_id]);
    }

    /** Places a kill bounty on `target`. @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get */
    public function bounty(int $agent_id, int $target, mixed $reward, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->intent($agent_id, 'bounty', array_filter([
            'target' => $target,
            'reward' => $reward,
            'deadline_ticks' => $deadline_ticks,
        ], fn($v) => null !== $v));
    }

    // --- Heal ----------------------------------------------------------------------

    /**
     * Applies a medicine to self, or to ally `target` within 6 cells (a
     * `medkit` revives a downed ally). `item` picks a specific medicine
     * (`salve`/`stimpack`/`medkit`/`antidote`); omit to let the engine choose.
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     */
    public function heal(int $agent_id, ?int $target = null, ?string $item = null): PromiseInterface
    {
        return $this->intent($agent_id, 'heal', array_filter([
            'target' => $target,
            'item' => $item,
        ], fn($v) => null !== $v));
    }

    // --- Combat ------------------------------------------------------------------
    // @link https://nha.recluse.lol/AGENTS.md verbs: attack, arm, detonate, steal, collect

    /**
     * Fires a ranged weapon at `target`. `weapon` is optional — the engine
     * picks a held weapon when it is omitted.
     *
     * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
     */
    public function attack(int $agent_id, int $target, ?string $weapon = null): PromiseInterface
    {
        return $this->intent($agent_id, 'attack', array_filter([
            'target' => $target,
            'weapon' => $weapon,
        ], fn($v) => null !== $v));
    }

    /** Arms a carried bomb. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function arm(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'arm', []);
    }

    /** Detonates an armed `bomb`. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function detonate(int $agent_id, mixed $bomb): PromiseInterface
    {
        return $this->intent($agent_id, 'detonate', ['bomb' => $bomb]);
    }

    /** Steals `n` of `resource` from adjacent agent `from` (credits can't be stolen). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function steal(int $agent_id, int $from, string $resource, int $n = 1): PromiseInterface
    {
        return $this->intent($agent_id, 'steal', ['from' => $from, 'resource' => $resource, 'n' => $n]);
    }

    /** Steals a loose `part` off adjacent agent `from` (the `steal{from,part}` form). @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function stealPart(int $agent_id, int $from, string $part): PromiseInterface
    {
        return $this->intent($agent_id, 'steal', ['from' => $from, 'part' => $part]);
    }

    /** Collects a dropped `loot` pile. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function collect(int $agent_id, mixed $loot): PromiseInterface
    {
        return $this->intent($agent_id, 'collect', ['loot' => $loot]);
    }

    // --- Diplomacy -------------------------------------------------------------
    // @link https://nha.recluse.lol/docs#/social/relations_relations_get

    /** Proposes an alliance to `to`. @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function ally(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'ally', ['to' => $to]);
    }

    /** Accepts an alliance proposal from `to` (verb `accept_ally`). @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function acceptAlly(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'accept_ally', ['to' => $to]);
    }

    /** Dissolves an alliance with `to`. @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function unally(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'unally', ['to' => $to]);
    }

    /** Declares war on `to`. @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function declareWar(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'declare_war', ['to' => $to]);
    }

    /** Sues for peace with `to`. @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function makePeace(int $agent_id, int $to): PromiseInterface
    {
        return $this->intent($agent_id, 'make_peace', ['to' => $to]);
    }

    /** Gifts the `give` resources to ally `to`. @link https://nha.recluse.lol/docs#/social/relations_relations_get */
    public function assist(int $agent_id, int $to, array $give): PromiseInterface
    {
        return $this->intent($agent_id, 'assist', ['to' => $to, 'give' => $give]);
    }

    // --- Ancients ------------------------------------------------------------------

    /** Attunes to an ancient artifact at the agent's tile. @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post */
    public function attune(int $agent_id): PromiseInterface
    {
        return $this->intent($agent_id, 'attune', []);
    }

    // --- Talk --------------------------------------------------------------------
    // @link https://nha.recluse.lol/docs#/social/chat_chat_get

    /** Broadcasts `text` to world chat (verb `say`). @link https://nha.recluse.lol/docs#/social/chat_chat_get */
    public function say(int $agent_id, string $text): PromiseInterface
    {
        return $this->intent($agent_id, 'say', ['text' => $text]);
    }

    /** Sends a private message `text` to agent `to` (verb `tell`). @link https://nha.recluse.lol/docs#/social/chat_chat_get */
    public function tell(int $agent_id, int $to, string $text): PromiseInterface
    {
        return $this->intent($agent_id, 'tell', ['to' => $to, 'text' => $text]);
    }

    /**
     * Fetches a read-only endpoint and resolves with the decoded JSON body.
     *
     * @link https://nha.recluse.lol/docs
     *
     * @param string|object $endpoint Route string or bound {@see Endpoint}.
     *
     * @return PromiseInterface
     */
    public function get(string|object $endpoint): PromiseInterface
    {
        return $this->nha_http->get($endpoint);
    }
}
