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

use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
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
 * boards go through {@see board()}.
 *
 * @since 0.1.0
 */
class Commands
{
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

    protected static function textContainer(string $text): Container
    {
        return Container::new()->addComponents([TextDisplay::new($text)]);
    }

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

    public function observe(?int $agent_id): PromiseInterface
    {
        // NHA::observe() records position/tick into the StateStore (wired in the
        // constructor) for every caller, so no persistence is needed here.
        return $this->nha->observe($this->resolveAgentId($agent_id))->then(
            fn(AgentObservation $obs) => NHA::createBuilder()->addComponent($obs->toContainer($this->nha)),
        );
    }

    // --- Actions --------------------------------------------------------------

    /**
     * Queues one intent and confirms it, surfacing the `queued_intent` id so the
     * caller can follow up with {@see intentStatus()}.
     *
     * @param array<string, mixed> $args
     */
    public function queueVerb(?int $agent_id, string $verb, array $args = []): PromiseInterface
    {
        $agent_id = $this->resolveAgentId($agent_id);

        return $this->nha->intent($agent_id, $verb, $args)->then(function ($queued) use ($verb, $agent_id) {
            $queued = (array) $queued;
            $id = $queued['queued_intent'] ?? null;

            $ref = $id !== null ? " · check the outcome with `intent {$id}`" : '';
            $note = isset($queued['note']) && $queued['note'] ? "\n> {$queued['note']}" : '';

            return self::message("✅ Queued **{$verb}** for agent #{$agent_id}{$ref}{$note}");
        });
    }

    /** Raw verb + JSON-string args (the `/nha act` escape hatch for any verb). */
    public function act(?int $agent_id, string $verb, ?string $argsJson): PromiseInterface
    {
        $args = [];
        if ($argsJson !== null && trim($argsJson) !== '') {
            $args = json_decode($argsJson, true);
            if (! is_array($args)) {
                return reject(new \InvalidArgumentException('`args` must be a valid JSON object, e.g. `{"dx":1,"dy":0}`.'));
            }
        }

        return $this->queueVerb($agent_id, $verb, $args);
    }

    public function move(?int $agent_id, int $dx, int $dy): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'move', ['dx' => $dx, 'dy' => $dy]);
    }

    public function moveTo(?int $agent_id, int $x, int $y): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'move', ['x' => $x, 'y' => $y]);
    }

    public function mine(?int $agent_id, ?int $n = null, ?string $resource = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'mine', array_filter(
            ['n' => $n ?? 1, 'resource' => $resource],
            fn($v) => null !== $v,
        ));
    }

    public function chop(?int $agent_id, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'chop', ['n' => $n ?? 1]);
    }

    public function gather(?int $agent_id, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'gather', ['n' => $n ?? 1]);
    }

    public function say(?int $agent_id, string $text): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'say', ['text' => $text]);
    }

    public function tell(?int $agent_id, int $to, string $text): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'tell', ['to' => $to, 'text' => $text]);
    }

    // No-arg verbs: `verb => label` — every one just queues that verb.
    public function plant(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'plant');
    }

    public function ride(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'ride');
    }

    public function launch(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'launch');
    }

    public function land(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'land');
    }

    public function landMoon(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'land_moon');
    }

    public function dock(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'dock');
    }

    public function attune(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'attune');
    }

    public function deploy(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'deploy');
    }

    public function arm(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'arm');
    }

    public function landBody(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'land_body');
    }

    public function distress(?int $agent_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'distress');
    }

    // Craft & build.
    public function combine(?int $agent_id, array $ingredients, ?string $name = null, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'combine', array_filter(
            ['ingredients' => $ingredients, 'name' => $name, 'n' => $n],
            fn($v) => null !== $v,
        ));
    }

    public function build(?int $agent_id, string $part, array $with = []): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'build', array_filter(
            ['part' => $part, 'with' => $with ?: null],
            fn($v) => null !== $v,
        ));
    }

    public function finalize(?int $agent_id, ?string $name = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'finalize', null === $name ? [] : ['name' => $name]);
    }

    /** @param array<string, mixed> $args Shape-specific keys (size/height/color, module, kind, body, stage, w, h). */
    public function construct(?int $agent_id, string $shape, array $args = []): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'construct', ['shape' => $shape] + $args);
    }

    public function invest(?int $agent_id, string $module, int $credits, ?string $resource = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'invest', array_filter(
            ['module' => $module, 'credits' => $credits, 'resource' => $resource],
            fn($v) => null !== $v,
        ));
    }

    // Economy.
    public function sell(?int $agent_id, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'sell', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    public function buy(?int $agent_id, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'buy', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    public function order(?int $agent_id, string $side, string $resource, int $qty, float $price): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'order', ['side' => $side, 'resource' => $resource, 'qty' => $qty, 'price' => $price]);
    }

    public function cancelOrder(?int $agent_id, int|string $order_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'cancel', ['order_id' => $order_id]);
    }

    public function deposit(?int $agent_id, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'deposit', array_filter(['resource' => $resource, 'n' => $n], fn($v) => null !== $v));
    }

    /**
     * @param array<string, int> $give
     * @param array<string, int> $want
     */
    public function trade(?int $agent_id, int $to, array $give, array $want): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'trade', ['to' => $to, 'give' => $give, 'want' => $want]);
    }

    public function acceptTrade(?int $agent_id, int|string $trade_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'accept', ['trade_id' => $trade_id]);
    }

    // Contracts & bounties.
    /** @param array<string, int> $want */
    public function contract(?int $agent_id, mixed $reward, array $want, ?int $to = null, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'contract', array_filter(
            ['reward' => $reward, 'want' => $want, 'to' => $to, 'deadline_ticks' => $deadline_ticks],
            fn($v) => null !== $v,
        ));
    }

    public function fulfill(?int $agent_id, int|string $contract_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'fulfill', ['contract_id' => $contract_id]);
    }

    public function revoke(?int $agent_id, int|string $contract_id): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'revoke', ['contract_id' => $contract_id]);
    }

    public function bounty(?int $agent_id, int $target, mixed $reward, ?int $deadline_ticks = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'bounty', array_filter(
            ['target' => $target, 'reward' => $reward, 'deadline_ticks' => $deadline_ticks],
            fn($v) => null !== $v,
        ));
    }

    // Medicine & combat.
    public function heal(?int $agent_id, ?int $target = null, ?string $item = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'heal', array_filter(['target' => $target, 'item' => $item], fn($v) => null !== $v));
    }

    public function attack(?int $agent_id, int $target, ?string $weapon = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'attack', array_filter(['target' => $target, 'weapon' => $weapon], fn($v) => null !== $v));
    }

    public function detonate(?int $agent_id, int|string $bomb): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'detonate', ['bomb' => $bomb]);
    }

    public function steal(?int $agent_id, int $from, string $resource, ?int $n = null): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'steal', array_filter(
            ['from' => $from, 'resource' => $resource, 'n' => $n],
            fn($v) => null !== $v,
        ));
    }

    public function collect(?int $agent_id, int|string $loot): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'collect', ['loot' => $loot]);
    }

    // Diplomacy.
    public function ally(?int $agent_id, int $to): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'ally', ['to' => $to]);
    }

    public function acceptAlly(?int $agent_id, int $to): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'accept_ally', ['to' => $to]);
    }

    public function unally(?int $agent_id, int $to): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'unally', ['to' => $to]);
    }

    public function declareWar(?int $agent_id, int $to): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'declare_war', ['to' => $to]);
    }

    public function makePeace(?int $agent_id, int $to): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'make_peace', ['to' => $to]);
    }

    /** @param array<string, int> $give */
    public function assist(?int $agent_id, int $to, array $give): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'assist', ['to' => $to, 'give' => $give]);
    }

    // Expansion era.
    public function depart(?int $agent_id, string $dest): PromiseInterface
    {
        return $this->queueVerb($agent_id, 'depart', ['dest' => $dest]);
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

    // Thin aliases kept for the common boards / backward compatibility.
    public function world(): PromiseInterface
    {
        return $this->board('world');
    }

    public function map(): PromiseInterface
    {
        return $this->board('map');
    }

    public function market(?int $limit = null, ?string $resource = null): PromiseInterface
    {
        return $this->board('market', array_filter(['limit' => $limit, 'resource' => $resource], fn($v) => null !== $v));
    }

    public function roster(): PromiseInterface
    {
        return $this->board('roster');
    }

    public function rules(): PromiseInterface
    {
        return $this->board('rules');
    }

    public function contracts(): PromiseInterface
    {
        return $this->board('contracts');
    }

    public function depot(): PromiseInterface
    {
        return $this->board('depot');
    }

    public function agentInfo(int $agent_id): PromiseInterface
    {
        return $this->board('agent', ['id' => $agent_id]);
    }

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
}
