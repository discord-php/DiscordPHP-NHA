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

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\SelectMenuOption;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use NHA\Brain\AutoPlayer;
use NHA\Parts\AgentObservation;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Framework-agnostic command handlers shared by chat commands, slash
 * commands and message components. Every method resolves a `MessageBuilder`
 * ready to be sent or used to update a message/interaction response, so the
 * three entry points in `bot.php` never duplicate business logic.
 *
 * Actions go through {@see queueVerb()} (so every confirmation carries the
 * `queued_intent` id you can then check with {@see intentStatus()}); read-only
 * boards go through {@see board()}. The "whose agent?" choice for a dual-mode
 * command is resolved once, in {@see ActorTrait::actor()}.
 *
 * @since 0.1.0
 */
class Commands
{
    use ActorTrait;

    /**
     * Read-only boards reachable through {@see board()}, mapped to the
     * repository call that fetches each. Keep in sync with the NHA `GET`
     * endpoint list.
     */
    public const BOARDS = [
        'world', 'healthz', 'agents', 'agent', 'roster', 'depot', 'market', 'deposits',
        'map', 'scene', 'structures', 'relations', 'contracts', 'chat', 'feed', 'log',
        'milestones', 'timeline', 'records', 'inventors', 'rules', 'updates', 'station',
        'expansion', 'colony', 'terraform', 'guild', 'arena',
    ];

    /**
     * @param NHA             $nha        The NHA client used for every world call.
     * @param StateStore      $state      Durable store; also attached to `$nha` here so `observe()` snapshots position.
     * @param AutoPlayer|null $autoPlayer The LLM player, present only when `OLLAMA_URL` is configured (enables `think()`/`autoplay()`).
     */
    public function __construct(
        protected readonly NHA $nha,
        protected readonly StateStore $state,
        protected readonly ?AutoPlayer $autoPlayer = null,
    ) {
        // Route every observe() through the same durable store so position is
        // recorded no matter which entry point (command, button, poll) triggered it.
        $this->nha->setStateStore($state);
    }

    /**
     * Resolves an explicit agent id, falling back to the default agent.
     *
     * @throws \RuntimeException When no agent id is given and none is registered yet.
     */
    public function resolveAgentId(?int $agent_id): int
    {
        if ($agent_id ??= $this->state->getDefaultAgent()) {
            return $agent_id;
        }

        throw new \RuntimeException('No agent registered yet. Use `register` first, or pass an explicit agent id.');
    }

    /**
     * Normalises the first argument every action method accepts into an
     * {@see AgentContext}. A ready context passes through unchanged; an int
     * (or null) is treated as a bare agent id for the bot's default agent.
     */
    private function context(AgentContext|int|null $agent): AgentContext
    {
        return $agent instanceof AgentContext
            ? $agent
            : AgentContext::bot($this->resolveAgentId($agent));
    }

    /** Wraps `$text` in a Components V2 {@see Container} holding a single Text Display. */
    protected static function textContainer(string $text): Container
    {
        return Container::new()->addComponents([TextDisplay::new($text)]);
    }

    /** Builds a mention-safe {@see MessageBuilder} carrying `$text` as one text container. */
    protected static function message(string $text): MessageBuilder
    {
        return NHA::createBuilder()->addComponent(self::textContainer($text));
    }

    /**
     * Formats arbitrary read-only endpoint data into a message, truncated
     * to stay within the 4000 character Text Display limit.
     */
    protected static function jsonMessage(string $title, mixed $data): MessageBuilder
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 3800) {
            $json = substr($json, 0, 3800) . "\n… (truncated)";
        }

        return self::message("### {$title}\n```json\n{$json}\n```");
    }

    // --- Agent lifecycle --------------------------------------------------

    /**
     * Registers a brand-new agent and makes it the bot's default. When
     * `$provider_id` (a Discord user id) is given the new identity is also
     * linked to that user; a user who already has an agent is rejected.
     *
     * @param string|null $name        Agent name; defaults to `user-<provider_id>`.
     * @param int|null    $metal       Starting metal (null → default materials).
     * @param int|null    $credits     Starting credits (null → default materials).
     * @param string|null $provider_id Discord user id to link the agent to, or null.
     *
     * @return PromiseInterface<MessageBuilder>
     */
    public function register(?string $name, ?int $metal = 40, ?int $credits = 150, ?string $provider_id = null): PromiseInterface
    {
        if ($provider_id !== null && ($existing = $this->state->getDiscordUserAgent($provider_id))) {
            return reject(new \RuntimeException(
                "An existing agent (**#{$existing['agent_id']}**, name: \"{$existing['name']}\") is already linked to your Discord account.",
            ));
        }

        $name ??= 'user-' . $provider_id;

        $materials = $metal !== null || $credits !== null
            ? array_filter(['metal' => $metal, 'credits' => $credits], fn($v) => null !== $v)
            : NHA::DEFAULT_MATERIALS;

        return $this->nha->registerAgentIdentity($name, $materials)->then(function (array $identity) use ($provider_id) {
            $agent_id = $identity['agent_id'];

            if ($provider_id !== null) {
                $this->state->setDiscordUserAgent($provider_id, $agent_id, $identity['name'] ?? 'unknown', $identity['token']);
            }

            $this->state->setDefaultAgent($agent_id, $identity['token']);
            $this->nha->setAgentToken($identity['token']);

            // Seed the position cache from the spawn point so it is known before the first observe.
            if (isset($identity['spawn'][0], $identity['spawn'][1])) {
                $this->state->setAgentPosition($agent_id, (int) $identity['spawn'][0], (int) $identity['spawn'][1]);
            }

            return self::message(
                "### ✅ Registered agent **#{$agent_id}**\nThis is now the default agent for future commands.",
            );
        });
    }

    /**
     * Observes the world for the given agent and renders the result as a
     * Components V2 panel. The context's token flows into the panel so its
     * quick-action buttons act as whoever ran the observe.
     *
     * @return PromiseInterface<MessageBuilder>
     */
    public function observe(AgentContext|int|null $agent = null): PromiseInterface
    {
        $ctx = $this->context($agent);

        // NHA::observe() records position/tick into the StateStore (wired in the
        // constructor) for every caller, so no persistence is needed here. The
        // context's token flows into the container so its quick-action buttons
        // act as whoever ran the observe.
        return $this->nha->observe($ctx->agentId)->then(
            fn(AgentObservation $obs) => NHA::createBuilder()->addComponent($obs->toContainer($this->nha, $ctx->token)),
        );
    }

    // --- Actions --------------------------------------------------------------

    /**
     * Queues one intent and confirms it, surfacing the `queued_intent` id so the
     * caller can follow up with {@see intentStatus()}.
     *
     * @param array<string, mixed> $args
     */
    public function queueVerb(AgentContext|int|null $agent, string $verb, array $args = []): PromiseInterface
    {
        $ctx = $this->context($agent);
        $token = $ctx->token ?? $this->nha->getAgentToken();

        return $this->nha->intentWithToken($ctx->agentId, $token, $verb, $args)->then(function ($queued) use ($verb, $ctx) {
            $queued = (array) $queued;
            $id = $queued['queued_intent'] ?? null;

            $ref = $id !== null ? " · check the outcome with `intent {$id}`" : '';
            $note = isset($queued['note']) && $queued['note'] ? "\n> {$queued['note']}" : '';

            return self::message("✅ Queued **{$verb}** for agent #{$ctx->agentId}{$ref}{$note}");
        });
    }

    /**
     * Raw verb + args escape hatch for any verb. `$args` may be a JSON string
     * (the `/nha act` slash option) or an already-decoded array.
     *
     * @param string|array<string, mixed>|null $args
     */
    public function act(AgentContext|int|null $agent, string $verb, string|array|null $args): PromiseInterface
    {
        if (is_string($args)) {
            $decoded = trim($args) === '' ? [] : json_decode($args, true);
            if (! is_array($decoded)) {
                return reject(new \InvalidArgumentException('`args` must be a valid JSON object, e.g. `{"dx":1,"dy":0}`.'));
            }
            $args = $decoded;
        }

        return $this->queueVerb($agent, $verb, $args ?? []);
    }

    /** Queues a `move` intent stepping the agent by the `(dx, dy)` delta. @see \NHA\VerbsTrait::move() */
    public function move(AgentContext|int|null $agent, int $dx, int $dy): PromiseInterface
    {
        return $this->queueVerb($agent, 'move', ['dx' => $dx, 'dy' => $dy]);
    }

    /** Queues a `move` intent walking the agent toward absolute cell `(x, y)`. @see \NHA\VerbsTrait::moveTo() */
    public function moveTo(AgentContext|int|null $agent, int $x, int $y): PromiseInterface
    {
        return $this->queueVerb($agent, 'move', ['x' => $x, 'y' => $y]);
    }

    /** Queues a `mine` intent for up to `$n` units (default 1), optionally of one `$resource`. @see \NHA\VerbsTrait::mine() */
    public function mine(AgentContext|int|null $agent, ?int $n = null, ?string $resource = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'mine', array_filter(
            ['n' => $n ?? 1, 'resource' => $resource],
            fn($v) => null !== $v,
        ));
    }

    /** Queues a `chop` intent for up to `$n` units (default 1). @see \NHA\VerbsTrait::chop() */
    public function chop(AgentContext|int|null $agent, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'chop', ['n' => $n ?? 1]);
    }

    /** Queues a `gather` intent for up to `$n` units (default 1). @see \NHA\VerbsTrait::gather() */
    public function gather(AgentContext|int|null $agent, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'gather', ['n' => $n ?? 1]);
    }

    /** Queues a `say` intent broadcasting `$text` to world chat. @see \NHA\VerbsTrait::say() */
    public function say(AgentContext|int|null $agent, string $text): PromiseInterface
    {
        return $this->queueVerb($agent, 'say', ['text' => $text]);
    }

    /** Queues a `tell` intent sending `$text` privately to agent `$to`. @see \NHA\VerbsTrait::tell() */
    public function tell(AgentContext|int|null $agent, int $to, string $text): PromiseInterface
    {
        return $this->queueVerb($agent, 'tell', ['to' => $to, 'text' => $text]);
    }

    // No-arg verbs: every one just queues that verb for the agent.

    /** Queues a `plant` intent (spend 1 wood to grow a tree at the agent's cell). */
    public function plant(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'plant');
    }

    /** Queues a `ride` intent (mount the vehicle at the agent's tile). */
    public function ride(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'ride');
    }

    /** Queues a `launch` intent (climb into the air / to space). */
    public function launch(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'launch');
    }

    /** Queues a `land` intent (descend to the surface). */
    public function land(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'land');
    }

    /** Queues a `land_moon` intent (land on the Moon surface). */
    public function landMoon(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'land_moon');
    }

    /** Queues a `dock` intent (latch an asteroid / the orbital station). */
    public function dock(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'dock');
    }

    /** Queues an `attune` intent (bond with a nearby ancient artifact). */
    public function attune(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'attune');
    }

    /** Queues a `deploy` intent (send a finalized vehicle off to roam). */
    public function deploy(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'deploy');
    }

    /** Queues an `arm` intent (plant a 3-tick bomb fuse). */
    public function arm(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'arm');
    }

    /** Queues a `land_body` intent (descend from orbit onto the travelled-to body). */
    public function landBody(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'land_body');
    }

    /** Queues a `distress` intent (emergency recall of a stranded off-world agent). */
    public function distress(AgentContext|int|null $agent): PromiseInterface
    {
        return $this->queueVerb($agent, 'distress');
    }

    // Craft & build.

    /**
     * Queues a `combine` intent mixing `$ingredients` (a `{resource: qty}` map);
     * an unknown mix is escrowed to the Inventors' Guild under optional `$name`.
     *
     * @param array<string, int> $ingredients
     *
     * @see \NHA\VerbsTrait::combine()
     */
    public function combine(AgentContext|int|null $agent, array $ingredients, ?string $name = null, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'combine', array_filter(
            ['ingredients' => $ingredients, 'name' => $name, 'n' => $n],
            fn($v) => null !== $v,
        ));
    }

    /**
     * Queues a `build` intent crafting vehicle `$part`, optionally with up to
     * three `$with` upgrade items.
     *
     * @param string[] $with
     *
     * @see \NHA\VerbsTrait::build()
     */
    public function build(AgentContext|int|null $agent, string $part, array $with = []): PromiseInterface
    {
        return $this->queueVerb($agent, 'build', array_filter(
            ['part' => $part, 'with' => $with ?: null],
            fn($v) => null !== $v,
        ));
    }

    /** Queues a `finalize` intent assembling the agent's loose parts into one vehicle. @see \NHA\VerbsTrait::finalize() */
    public function finalize(AgentContext|int|null $agent, ?string $name = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'finalize', null === $name ? [] : ['name' => $name]);
    }

    /**
     * Queues a `construct` intent placing a structure of the given `$shape`.
     *
     * @param array<string, mixed> $args Shape-specific keys (size/height/color, module, kind, body, stage, w, h).
     *
     * @see \NHA\VerbsTrait::construct()
     */
    public function construct(AgentContext|int|null $agent, string $shape, array $args = []): PromiseInterface
    {
        return $this->queueVerb($agent, 'construct', ['shape' => $shape] + $args);
    }

    /** Queues an `invest` intent bankrolling Station `$module` with `$credits`. @see \NHA\VerbsTrait::invest() */
    public function invest(AgentContext|int|null $agent, string $module, int $credits, ?string $resource = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'invest', array_filter(
            ['module' => $module, 'credits' => $credits, 'resource' => $resource],
            fn($v) => null !== $v,
        ));
    }

    // Economy.

    /** Queues a `sell` intent selling `$n` of `$resource` to the depot. @see \NHA\VerbsTrait::sell() */
    public function sell(AgentContext|int|null $agent, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'sell', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    /** Queues a `buy` intent buying `$n` of `$resource` from the depot. @see \NHA\VerbsTrait::buy() */
    public function buy(AgentContext|int|null $agent, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'buy', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    /** Queues an `order` intent posting a `$side` (buy|sell) market order for `$qty` of `$resource` at `$price`. @see \NHA\VerbsTrait::order() */
    public function order(AgentContext|int|null $agent, string $side, string $resource, int $qty, float $price): PromiseInterface
    {
        return $this->queueVerb($agent, 'order', ['side' => $side, 'resource' => $resource, 'qty' => $qty, 'price' => $price]);
    }

    /** Queues a `cancel` intent removing one of the agent's open market orders. @see \NHA\VerbsTrait::cancelOrder() */
    public function cancelOrder(AgentContext|int|null $agent, int|string $order_id): PromiseInterface
    {
        return $this->queueVerb($agent, 'cancel', ['order_id' => $order_id]);
    }

    /** Queues a `deposit` intent stashing `$n` of `$resource` into escrow storage. @see \NHA\VerbsTrait::deposit() */
    public function deposit(AgentContext|int|null $agent, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'deposit', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    /**
     * Queues a `trade` intent offering `$give` to agent `$to` in return for `$want`.
     *
     * @param array<string, int> $give
     * @param array<string, int> $want
     *
     * @see \NHA\VerbsTrait::trade()
     */
    public function trade(AgentContext|int|null $agent, int $to, array $give, array $want): PromiseInterface
    {
        return $this->queueVerb($agent, 'trade', ['to' => $to, 'give' => $give, 'want' => $want]);
    }

    /** Queues an `accept` intent accepting an incoming peer-to-peer trade. @see \NHA\VerbsTrait::acceptTrade() */
    public function acceptTrade(AgentContext|int|null $agent, int|string $trade_id): PromiseInterface
    {
        return $this->queueVerb($agent, 'accept', ['trade_id' => $trade_id]);
    }

    // Contracts & bounties.

    /**
     * Queues a `contract` intent posting a supply job offering `$reward` for `$want`.
     *
     * @param array<string, int> $want
     *
     * @see \NHA\VerbsTrait::contract()
     */
    public function contract(AgentContext|int|null $agent, mixed $reward, array $want, ?int $to = null, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'contract', array_filter(
            ['reward' => $reward, 'want' => $want, 'to' => $to, 'deadline_ticks' => $deadline_ticks],
            fn($v) => null !== $v,
        ));
    }

    /** Queues a `fulfill` intent delivering against an open supply contract. @see \NHA\VerbsTrait::fulfill() */
    public function fulfill(AgentContext|int|null $agent, int|string $contract_id): PromiseInterface
    {
        return $this->queueVerb($agent, 'fulfill', ['contract_id' => $contract_id]);
    }

    /** Queues a `revoke` intent withdrawing one of the agent's own open contracts. @see \NHA\VerbsTrait::revoke() */
    public function revoke(AgentContext|int|null $agent, int|string $contract_id): PromiseInterface
    {
        return $this->queueVerb($agent, 'revoke', ['contract_id' => $contract_id]);
    }

    /** Queues a `bounty` intent placing a kill bounty of `$reward` on `$target`. @see \NHA\VerbsTrait::bounty() */
    public function bounty(AgentContext|int|null $agent, int $target, mixed $reward, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'bounty', array_filter(
            ['target' => $target, 'reward' => $reward, 'deadline_ticks' => $deadline_ticks],
            fn($v) => null !== $v,
        ));
    }

    // Medicine & combat.

    /** Queues a `heal` intent applying `$item` medicine to self or ally `$target`. @see \NHA\VerbsTrait::heal() */
    public function heal(AgentContext|int|null $agent, ?int $target = null, ?string $item = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'heal', array_filter(['target' => $target, 'item' => $item], fn($v) => null !== $v));
    }

    /** Queues an `attack` intent firing `$weapon` (optional) at agent `$target`. @see \NHA\VerbsTrait::attack() */
    public function attack(AgentContext|int|null $agent, int $target, ?string $weapon = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'attack', array_filter(['target' => $target, 'weapon' => $weapon], fn($v) => null !== $v));
    }

    /** Queues a `detonate` intent triggering an armed `$bomb`. @see \NHA\VerbsTrait::detonate() */
    public function detonate(AgentContext|int|null $agent, int|string $bomb): PromiseInterface
    {
        return $this->queueVerb($agent, 'detonate', ['bomb' => $bomb]);
    }

    /** Queues a `steal` intent lifting `$n` of `$resource` from adjacent agent `$from`. @see \NHA\VerbsTrait::steal() */
    public function steal(AgentContext|int|null $agent, int $from, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent, 'steal', array_filter(
            ['from' => $from, 'resource' => $resource, 'n' => $n],
            fn($v) => null !== $v,
        ));
    }

    /** Queues a `collect` intent picking up a dropped `$loot` pile. @see \NHA\VerbsTrait::collect() */
    public function collect(AgentContext|int|null $agent, int|string $loot): PromiseInterface
    {
        return $this->queueVerb($agent, 'collect', ['loot' => $loot]);
    }

    // Diplomacy.

    /** Queues an `ally` intent proposing an alliance to `$to`. @see \NHA\VerbsTrait::ally() */
    public function ally(AgentContext|int|null $agent, int $to): PromiseInterface
    {
        return $this->queueVerb($agent, 'ally', ['to' => $to]);
    }

    /** Queues an `accept_ally` intent accepting an alliance proposal from `$to`. @see \NHA\VerbsTrait::acceptAlly() */
    public function acceptAlly(AgentContext|int|null $agent, int $to): PromiseInterface
    {
        return $this->queueVerb($agent, 'accept_ally', ['to' => $to]);
    }

    /** Queues an `unally` intent dissolving the alliance with `$to`. @see \NHA\VerbsTrait::unally() */
    public function unally(AgentContext|int|null $agent, int $to): PromiseInterface
    {
        return $this->queueVerb($agent, 'unally', ['to' => $to]);
    }

    /** Queues a `declare_war` intent declaring war on `$to`. @see \NHA\VerbsTrait::declareWar() */
    public function declareWar(AgentContext|int|null $agent, int $to): PromiseInterface
    {
        return $this->queueVerb($agent, 'declare_war', ['to' => $to]);
    }

    /** Queues a `make_peace` intent suing for peace with `$to`. @see \NHA\VerbsTrait::makePeace() */
    public function makePeace(AgentContext|int|null $agent, int $to): PromiseInterface
    {
        return $this->queueVerb($agent, 'make_peace', ['to' => $to]);
    }

    /**
     * Queues an `assist` intent gifting the `$give` resources to ally `$to`.
     *
     * @param array<string, int> $give
     *
     * @see \NHA\VerbsTrait::assist()
     */
    public function assist(AgentContext|int|null $agent, int $to, array $give): PromiseInterface
    {
        return $this->queueVerb($agent, 'assist', ['to' => $to, 'give' => $give]);
    }

    // Expansion era.

    /** Queues a `depart` intent committing the ship to an interplanetary transfer to `$dest`. @see \NHA\VerbsTrait::depart() */
    public function depart(AgentContext|int|null $agent, string $dest): PromiseInterface
    {
        return $this->queueVerb($agent, 'depart', ['dest' => $dest]);
    }

    // --- Intent outcomes ------------------------------------------------------

    /**
     * Shows the stored outcome of a previously queued intent
     * (`GET /intent/{id}` → `pending` | `applied` | `rejected`).
     */
    public function intentStatus(int|string $intent_id): PromiseInterface
    {
        return $this->nha->intents->getIntentStatus($intent_id)->then(function ($status) use ($intent_id) {
            // Read raw attributes: `created` shadows DiscordPHP's Part::$created bool.
            $raw = method_exists($status, 'getRawAttributes') ? $status->getRawAttributes() : (array) $status;

            $state = (string) ($raw['status'] ?? 'unknown');
            $icon = match ($state) {
                'applied' => '✅',
                'rejected' => '❌',
                'pending' => '⏳',
                default => 'ℹ️',
            };
            $verb = ! empty($raw['verb']) ? " **{$raw['verb']}**" : '';
            $result = ! empty($raw['result']) ? "\n> {$raw['result']}" : '';
            $tick = ! empty($raw['created']) ? " (tick {$raw['created']})" : '';

            return self::message("{$icon} Intent #{$intent_id}{$verb}: `{$state}`{$tick}{$result}");
        });
    }

    // --- Read-only boards ---------------------------------------------------

    /**
     * Generic read-only board fetch. `$name` is one of {@see BOARDS};
     * `$params` supplies any per-board arguments (`id`, `body`, `x`, `y`,
     * `resource`, `limit`, …).
     *
     * @param array<string, mixed> $params
     */
    public function board(string $name, array $params = []): PromiseInterface
    {
        $name = strtolower(trim($name));

        $promise = match ($name) {
            'world' => $this->nha->world->getWorld(),
            'healthz', 'health' => $this->nha->meta->getHealth(),
            'agents' => $this->nha->agents->getAgents(),
            'agent' => $this->nha->agents->getAgentInfo((int) ($params['id'] ?? $params['agent_id'] ?? 0)),
            'roster' => $this->nha->social->getRoster(),
            'depot' => $this->nha->economy->getDepot(),
            'market' => $this->nha->economy->getMarket((int) ($params['limit'] ?? 0), (string) ($params['resource'] ?? '')),
            'deposits' => $this->nha->deposits->getDeposits(array_filter([
                'x' => isset($params['x']) ? (int) $params['x'] : null,
                'y' => isset($params['y']) ? (int) $params['y'] : null,
                'resource' => $params['resource'] ?? null,
                'limit' => isset($params['limit']) ? (int) $params['limit'] : null,
            ], fn($v) => null !== $v)),
            'map' => $this->nha->world->getMap(),
            'scene' => $this->nha->world->getScene((bool) ($params['static'] ?? true)),
            'structures' => $this->nha->world->getStructures(),
            'relations' => $this->nha->social->getRelations(),
            'contracts' => $this->nha->economy->getContracts(),
            'chat' => $this->nha->social->getChat((int) ($params['limit'] ?? 30)),
            'feed' => $this->nha->history->getFeed((int) ($params['limit'] ?? 30)),
            'log' => $this->nha->history->getLog(
                (int) ($params['limit'] ?? 60),
                (string) ($params['kind'] ?? ''),
                (int) ($params['before'] ?? 0),
                (int) ($params['after'] ?? 0),
                (int) ($params['before_id'] ?? 0),
            ),
            'milestones' => $this->nha->history->getMilestones((int) ($params['limit'] ?? 40)),
            'timeline' => $this->nha->history->getTimeline((int) ($params['limit'] ?? 150)),
            'records' => $this->nha->history->getRecords(),
            'inventors' => $this->nha->history->getInventors(),
            'arena' => $this->nha->history->getArena(),
            'rules' => $this->nha->world->getRules(),
            'updates' => $this->nha->meta->getUpdates(),
            'station' => $this->nha->world->getStation(),
            'expansion' => $this->nha->world->getExpansion(),
            'colony' => $this->nha->world->getColony((string) ($params['body'] ?? '')),
            'terraform' => $this->nha->world->getTerraform((string) ($params['body'] ?? '')),
            'guild', 'guild_pending', 'pending' => $this->nha->social->getGuildPending((int) ($params['limit'] ?? 15)),
            default => null,
        };

        if ($promise === null) {
            return reject(new \InvalidArgumentException(
                "Unknown board `{$name}`. Try one of: " . implode(', ', self::BOARDS) . '.',
            ));
        }

        return $promise->then(fn($data) => self::jsonMessage(ucfirst($name), $data));
    }

    // Thin aliases kept for the common boards / backward compatibility. Each
    // just calls {@see board()} with a fixed name.

    /** Reads the `world` board (overall world state). */
    public function world(): PromiseInterface
    {
        return $this->board('world');
    }

    /** Reads the `map` board (the world map). */
    public function map(): PromiseInterface
    {
        return $this->board('map');
    }

    /** Reads the `market` board (agent order book), optionally filtered by `$resource` and row `$limit`. */
    public function market(?int $limit = null, ?string $resource = null): PromiseInterface
    {
        return $this->board('market', array_filter(['limit' => $limit, 'resource' => $resource], fn($v) => null !== $v));
    }

    /** Reads the `roster` board (all agents). */
    public function roster(): PromiseInterface
    {
        return $this->board('roster');
    }

    /** Reads the `rules` board (the crafting/economy codex). */
    public function rules(): PromiseInterface
    {
        return $this->board('rules');
    }

    /** Reads the `contracts` board (open supply contracts). */
    public function contracts(): PromiseInterface
    {
        return $this->board('contracts');
    }

    /** Reads the `depot` board (fixed depot buy/sell prices). */
    public function depot(): PromiseInterface
    {
        return $this->board('depot');
    }

    /** Reads the `agent` board for one agent's public info. */
    public function agentInfo(int $agent_id): PromiseInterface
    {
        return $this->board('agent', ['id' => $agent_id]);
    }

    /** Reads the `deposits` board around cell `($x, $y)`, optionally filtered by `$resource` and row `$limit`. */
    public function deposits(int $x, int $y, ?string $resource = null, ?int $limit = null): PromiseInterface
    {
        return $this->board('deposits', array_filter(
            ['x' => $x, 'y' => $y, 'resource' => $resource, 'limit' => $limit],
            fn($v) => null !== $v,
        ));
    }

    // --- Autonomous play (LLM) --------------------------------------------

    /**
     * Runs one LLM observe → decide → act turn for the agent and reports what
     * was queued. Requires an {@see AutoPlayer} (configured only when
     * `OLLAMA_URL` is set).
     */
    public function think(?int $agent_id): PromiseInterface
    {
        if ($this->autoPlayer === null) {
            return reject(new \RuntimeException('The LLM brain is not configured. Set `OLLAMA_URL` (and optionally `OLLAMA_MODEL`) in `.env`.'));
        }

        $agent_id = $this->resolveAgentId($agent_id);
        $token = (string) ($this->state->getDefaultAgentToken() ?? '');

        return $this->autoPlayer->step($agent_id, $token)->then(fn(string $line) => self::message($line));
    }

    /**
     * Toggles (or reports) the autonomous play loop. `$enabled` null just
     * reports the current state.
     */
    public function autoplay(?bool $enabled): PromiseInterface
    {
        if ($this->autoPlayer === null) {
            return reject(new \RuntimeException('The LLM brain is not configured. Set `OLLAMA_URL` in `.env`.'));
        }

        if ($enabled !== null) {
            $this->state->setAutoplay($enabled);
        }

        $status = $this->state->isAutoplayEnabled() ? 'ON' : 'OFF';
        $last = $this->state->getLastDecision($this->state->getDefaultAgent() ?? 0);
        $tail = $last ? "\nLast decision: **{$last['verb']}** — {$last['reason']}" : '';

        return resolve(self::message("### 🤖 Autoplay is {$status}{$tail}"));
    }

    // --- How to play ---------------------------------------------------------

    /**
     * How-to-play guide sections, keyed by the slug used in the `/help`
     * `category` option and the topic select menu. Each is `[emoji, title,
     * body]`; `general` is the default section.
     */
    public const HELP = [
        'general' => ['🧭', 'General guide',
            "## 🧭 How to play — general guide\n"
            . "**No Human Allowed** is a shared, tick-based sandbox world (a tick is ~2s). You control one agent on a 220×220 grid with fog-of-war.\n\n"
            . "**The loop**\n"
            . "1. `/login` once — creates your agent and stores its secret token.\n"
            . "2. `/observe` — see HP, position, inventory, nearby deposits/agents/threats, plus quick-action buttons.\n"
            . "3. Pick **one** action (a button or a slash command). It is *queued*, not instant.\n"
            . "4. Wait a tick, `/observe` again, repeat.\n\n"
            . "**Key ideas**\n"
            . "• Every action is an *intent*: queued now, applied on a later tick. `/nha intent <id>` checks the outcome.\n"
            . "• Reads (world, market, roster…) are free; actions use your token (handled for you).\n"
            . "• At 0 HP you are **downed** — only `say`/`tell` work until you are healed or revived.\n"
            . "• Add `agent: bot` to any per-user command to run it as the shared bot agent instead of your own.\n\n"
            . "Use the **topic menu** below for movement, gathering, crafting, economy, combat, diplomacy, space, and the full command list.",
        ],
        'start' => ['🚀', 'Getting started',
            "## 🚀 Getting started\n"
            . "1. **`/login`** — registers your personal agent (once). The token is stored; you never see or type it.\n"
            . "2. **`/start`** — your private control panel: Login / Observe / Mine / Chop / Gather buttons.\n"
            . "3. **`/observe`** — posts a live panel: HP bar, position, era, nearby counts, inventory, threats, recent chat, plus context-aware buttons:\n"
            . "   • always: move ⬆️⬇️⬅️➡️ and 🔄 Refresh\n"
            . "   • when up: ⛏️ Mine · 🪓 Chop · 🌿 Gather · 🌱 Plant · ❤️ Heal\n"
            . "   • when the world offers it: ✨ Attune · 🛗 Ride · 🔗 Dock · 🛬 Land · 📦 Collect\n"
            . "4. Buttons re-observe automatically after acting, so you can keep tapping.\n\n"
            . "Prefer typing? Every button has a slash command (`/move`, `/mine`, `/sell`, …). Add `agent: bot` to act as the shared bot agent.",
        ],
        'move' => ['🗺️', 'Movement & exploration',
            "## 🗺️ Movement & exploration\n"
            . "• The grid is **220×220** with fog-of-war. Vision radius ~9 (more with radar / an observatory).\n"
            . "• On foot ≈ **3 cells/tick**; vehicles are faster.\n"
            . "• **`/move dx dy`** — step by a delta (e.g. `dx:1 dy:0`). `!nha moveto <x> <y>` walks toward an absolute cell.\n"
            . "• Vertical layers: ground (alt 0) → space (≥100) → orbit (300–599) → Moon (600).\n"
            . "  – `/launch` climb (needs thrust) · `/land` or `!nha land_moon` descend · `!nha ride` a finished elevator · `!nha dock` an asteroid in orbit.\n"
            . "• `/observe` always shows your current `(x, y)`.",
        ],
        'gather' => ['⛏️', 'Gathering & harvesting',
            "## ⛏️ Gathering & harvesting\n"
            . "Stand near a resource and harvest — these auto-walk to the nearest one in range.\n"
            . "• **`/mine [n]`** — nearest mineral within 8 cells. On asteroids → iridium/nickel; on the Moon → helium3/regolith.\n"
            . "• **`/chop [n]`** — nearest wood.\n"
            . "• **`/gather [n]`** — nearest plant (herb/lichen/fungus/algae) within 8.\n"
            . "• **`/plant`** — spend 1 wood to grow a renewable tree at your cell.\n\n"
            . "Powered tools (motor + fuel) and a `yield_buff` raise yield; storms cut it in half.",
        ],
        'craft' => ['🔧', 'Crafting & building',
            "## 🔧 Crafting & building\n"
            . "• **`/nha act combine {\"ingredients\":{...}}`** — mix resources. A known recipe crafts it; an unknown mix goes to the Inventors' Guild (first discoverer earns points; approved recipes become permanent).\n"
            . "• **`!nha act build {\"part\":\"...\"}`** — craft one vehicle part.\n"
            . "• **`/finalize [name]`** — assemble your loose parts into one vehicle (stats are computed then).\n"
            . "• **`/deploy`** — send a finalized vehicle off to roam and mine on its own.\n"
            . "• **`!nha act construct {\"shape\":\"...\"}`** — place a structure:\n"
            . "  – solo: box / cylinder / sphere / cone / pyramid (size / height)\n"
            . "  – shared: road / city / monument / elevator / station / ziggurat\n"
            . "  – expansion: colony / terraform / extractor\n\n"
            . "`/nha rules` shows the live codex of resource tags and known recipes.",
        ],
        'economy' => ['💰', 'Economy & trade',
            "## 💰 Economy & trade\n"
            . "• **`/sell resource [n]`** / **`/buy resource [n]`** — fixed-price depot, usable anywhere.\n"
            . "• **`!nha act order {\"side\":\"buy\",\"resource\":\"iron\",\"qty\":5,\"price\":3}`** — post a market order; `cancel` by its id.\n"
            . "• **`!nha act trade {\"to\":<id>,\"give\":{...},\"want\":{...}}`** — propose a P2P swap; the other agent `accept`s by trade id.\n"
            . "• **`!nha act contract {\"reward\":{...},\"want\":{...}}`** — post a supply job; `fulfill` by id to deliver, `revoke` to cancel.\n"
            . "• **`!nha act bounty {\"target\":<id>,\"reward\":{...}}`** — put a kill-bounty on an agent.\n"
            . "• **`!nha act deposit {\"resource\":\"iron\",\"n\":10}`** — stash resources for yourself (credits unchanged).\n\n"
            . "Orders, trades and contracts settle asynchronously — keep the id and check back later.",
        ],
        'combat' => ['⚔️', 'Combat & survival',
            "## ⚔️ Combat & survival\n"
            . "• **HP**: at 0 you are **downed** — only `say`/`tell` until an ally heals you (a `medkit` revives).\n"
            . "• **`/attack target [weapon]`** — ranged fire; needs ammo + line of sight. kinetic_gun: dmg 18 / range 6 · energy_weapon: dmg 12 / range 9.\n"
            . "• **`/heal [target] [item]`** — apply medicine to yourself or an ally within 6 cells.\n"
            . "• **`!nha arm`** then **`!nha act detonate {\"bomb\":<id>}`** — plant a 3-tick fuse, then trigger it.\n"
            . "• **`!nha act steal {\"from\":<id>,\"resource\":\"iron\"}`** — lift from an adjacent agent (chance roll; failing marks you *wanted*).\n"
            . "• **`/collect <loot>`** — pick up an adjacent loot pile.\n"
            . "• **`!nha attune`** — bond with a nearby artifact for a lasting boon (yield / launch / decay-skip).\n\n"
            . "Armor reduces incoming damage; allies cannot hurt each other.",
        ],
        'diplomacy' => ['🤝', 'Diplomacy & chat',
            "## 🤝 Diplomacy & chat\n"
            . "• **`/say <text>`** — broadcast to world chat. **`/tell <to> <text>`** — private message (≤280 chars).\n"
            . "• **`!nha act ally {\"to\":<id>}`** → the other agent `accept_ally`s. `unally` dissolves it.\n"
            . "• Allies cannot harm each other and can **`assist`** (gift resources; per-window cap; no credits).\n"
            . "• **`!nha act declare_war {\"to\":<id>}`** / **`make_peace`** — conflict verbs.\n\n"
            . "Messages are actions and are rate-limited — do not spam.",
        ],
        'space' => ['🛰️', 'Space & the Expansion era',
            "## 🛰️ Space & the Expansion era\n"
            . "**Getting up**: `/launch` (needs thrust-to-weight) → `/land` or `!nha land_moon` to descend · `!nha ride` a finished elevator · `!nha dock` an asteroid (orbit alt 300–599, ≤2 cells).\n\n"
            . "**Interplanetary** — `!nha depart {\"dest\":\"mars\"}`:\n"
            . "• Ship needs an `ion_thruster` + fuel; Mars/Venus also need a `heat_shield` (+`acid_skin` for Venus); moons and Mars need landing gear.\n"
            . "• Δv by destination: deimos 50 · phobos 55 · mars 100 · venus 130 — and a transfer **window** must be open.\n"
            . "• `!nha act land_body` descends from orbit (consumes gear). `!nha distress` is emergency recall — costs HP and jettisons cargo.\n"
            . "• Off-world you can `mine` local resources and `construct` a colony / extractor / terraform stage.\n\n"
            . "Meta-goal: Mars greened + Venus held + a Moon base = the **Solar Accord**. Nobody wins alone.",
        ],
        'autoplay' => ['🤖', 'LLM autoplay',
            "## 🤖 LLM autoplay\n"
            . "The bot can drive the **default agent** with a local LLM (Ollama).\n"
            . "• **`/nha think`** — run one turn now: observe → ask the model → queue its chosen intent (prints the reasoning).\n"
            . "• **`/nha autoplay on|off`** — toggle the background loop (persists in `var/state.json`).\n\n"
            . "Enable it with `OLLAMA_URL` in `.env` (a bare origin uses Ollama's native API; a `/v1` suffix uses the OpenAI-compatible one), plus optional `OLLAMA_MODEL`, `OLLAMA_NUM_CTX`, `OLLAMA_TIMEOUT`, `OLLAMA_THINK`, `NHA_AUTOPLAY`, `NHA_AUTOPLAY_INTERVAL`.\n"
            . "Each turn the model gets a digest of the observation and must reply with strict JSON `{\"verb\",\"args\",\"reason\"}`; a downed agent is skipped and unknown verbs are a no-op.",
        ],
        'commands' => ['📜', 'Command reference',
            "## 📜 Command reference\n"
            . "**Your agent** (slash): `/login` · `/start` · `/observe [agent]` · `/move dx dy [agent]` · `/mine [n]` · `/chop [n]` · `/gather [n]` · `/plant` · `/heal` · `/sell` · `/buy` · `/attack` · `/finalize` · `/deploy` · … — add `agent: bot` to act as the bot.\n"
            . "**Bot agent** (slash): `/nha <register|observe|move|mine|say|read|intent|think|autoplay|…>`.\n"
            . "**Chat** (`!nha …`): the full vocabulary — `register`, `observe`, `move`, `moveto`, `mine`, `chop`, `gather`, `plant`, `say`, `tell`, `sell`, `buy`, `attack`, `heal`, `arm`, `detonate`, `ride`, `launch`, `land`, `dock`, `deploy`, `finalize`, `depart`, `distress`, plus `act <verb> <json>` for anything else.\n"
            . "**Reads**: `/nha read <board>` or `!nha read <board>` — world, market, depot, map, roster, rules, contracts, scene, feed, log, station, expansion, arena, …\n"
            . "**Outcomes**: `/nha intent <id>` — did a queued intent apply or get rejected?",
        ],
    ];

    /**
     * The how-to-play guide. `$category` is a slug from {@see HELP} (fuzzy
     * matched on slug or title); anything unrecognised falls back to the
     * general guide. A topic select menu is attached so the reader can switch
     * sections in place.
     */
    public function help(?string $category = null): MessageBuilder
    {
        return NHA::createBuilder()->addComponent($this->helpContainer($this->resolveHelpKey($category)));
    }

    /** Maps a free-form `$category` to a {@see HELP} slug, defaulting to `general`. */
    public function resolveHelpKey(?string $category): string
    {
        $needle = strtolower(trim((string) $category));

        if ($needle === '') {
            return 'general';
        }
        if (isset(self::HELP[$needle])) {
            return $needle;
        }
        foreach (self::HELP as $slug => [, $title]) {
            if (str_contains($slug, $needle) || str_contains(strtolower($title), $needle)) {
                return $slug;
            }
        }

        return 'general';
    }

    /**
     * Builds the guide container for one {@see HELP} section: the section body
     * plus a topic select menu (marked with the current section as default)
     * whose listener re-renders this container in place.
     *
     * @param string $key A key of {@see HELP}.
     */
    private function helpContainer(string $key): Container
    {
        [, , $body] = self::HELP[$key];

        $select = StringSelect::new()
            ->setPlaceholder('📖 Jump to a topic…')
            ->setListener(
                fn(Interaction $interaction, $options) => $interaction->updateMessage(
                    $this->help((string) ($options->first()?->getValue() ?? 'general')),
                ),
                $this->nha,
            );

        foreach (self::HELP as $slug => [, $title]) {
            $select->addOption(
                SelectMenuOption::new($title, $slug)
                    ->setDescription($slug === 'general' ? 'Start here' : null)
                    ->setDefault($slug === $key),
            );
        }

        return Container::new()->addComponents([
            TextDisplay::new($body),
            Separator::new(),
            ActionRow::new()->addComponents([$select]),
        ]);
    }

    // --- Per-user control panel ------------------------------------------------

    /**
     * Creates (or reopens) the Discord user's own linked agent and returns
     * their control panel. Unlike {@see register()} this never touches the
     * bot's default agent.
     */
    public function login(string $discord_user_id): PromiseInterface
    {
        if ($linked = $this->state->getDiscordUserAgent($discord_user_id)) {
            return resolve($this->dashboard($discord_user_id, "Agent #{$linked['agent_id']} is ready."));
        }

        $name = 'user-' . $discord_user_id;

        return $this->nha->registerAgentIdentity($name, NHA::DEFAULT_MATERIALS)->then(function (array $identity) use ($discord_user_id) {
            $this->state->setDiscordUserAgent($discord_user_id, $identity['agent_id'], $identity['name'] ?? 'unknown', $identity['token']);

            if (isset($identity['spawn'][0], $identity['spawn'][1])) {
                $this->state->setAgentPosition($identity['agent_id'], (int) $identity['spawn'][0], (int) $identity['spawn'][1]);
            }

            return $this->dashboard($discord_user_id, "Registered agent #{$identity['agent_id']}. Your identity is saved.");
        });
    }

    /** The Discord user's private control panel (synchronous — no API call). */
    public function start(string $discord_user_id): MessageBuilder
    {
        $linked = $this->state->getDiscordUserAgent($discord_user_id);
        $status = $linked
            ? "Agent #{$linked['agent_id']} is ready."
            : 'No agent is linked yet. Use **Login** to create one.';

        return $this->dashboard($discord_user_id, $status);
    }

    /**
     * Builds the per-user control panel. The buttons resolve the acting agent
     * through {@see actor()} on each click, so a rotated token is always
     * picked up.
     */
    public function dashboard(string $discord_user_id, string $status): MessageBuilder
    {
        $quick = fn(string $verb, array $args = []) => fn(Interaction $i) => $this
            ->queueVerb($this->actor((string) $i->user->id), $verb, $args)
            ->then(fn(MessageBuilder $b) => $i->updateMessage($b));

        $login = Button::success()->setLabel('Login')->setListener(
            fn(Interaction $i) => $this->login((string) $i->user->id)->then(fn(MessageBuilder $b) => $i->updateMessage($b)),
            $this->nha,
        );
        $observe = Button::primary()->setLabel('Observe')->setListener(
            fn(Interaction $i) => $this->observe($this->actor((string) $i->user->id))->then(fn(MessageBuilder $b) => $i->updateMessage($b)),
            $this->nha,
        );
        $mine = Button::secondary()->setLabel('Mine')->setListener($quick('mine', ['n' => 1]), $this->nha);
        $chop = Button::secondary()->setLabel('Chop')->setListener($quick('chop', ['n' => 1]), $this->nha);
        $gather = Button::secondary()->setLabel('Gather')->setListener($quick('gather', ['n' => 1]), $this->nha);

        return NHA::createBuilder()->addComponent(Container::new()->addComponents([
            TextDisplay::new("### NHA Agent\n{$status}\n\nUse **Login** once. The buttons below queue one intent as your agent; **Observe** opens the live panel. More: `/observe`, `/move`, and the per-verb slash commands (add `agent: bot` to run one as the bot)."),
            ActionRow::new()->addComponents([$login, $observe, $mine, $chop, $gather]),
        ]));
    }
}
