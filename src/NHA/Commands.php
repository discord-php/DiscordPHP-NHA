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
use NHA\Parts\AgentObservation;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Framework-agnostic command handlers shared by chat commands, slash
 * commands and message components. Every method resolves a `MessageBuilder`
 * ready to be sent or used to update a message/interaction response, so the
 * three entry points in `bot.php` never duplicate business logic.
 *
 * @since 0.1.0
 */
class Commands
{
    public function __construct(protected readonly NHA $nha, protected readonly StateStore $state) {}

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

    /**
     * Formats arbitrary read-only endpoint data into a message, truncated
     * to stay within the 4000 character Text Display limit.
     */
    protected static function jsonMessage(string $title, mixed $data): MessageBuilder
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if (strlen($json) > 3800) {
            $json = substr($json, 0, 3800) . "\n… (truncated)";
        }

        return NHA::createBuilder()->addComponent(self::textContainer("### {$title}\n```json\n{$json}\n```"));
    }

    // --- Agent lifecycle ---------------------------------------------------

    public function register(?string $name, ?int $metal, ?int $credits): PromiseInterface
    {
        $name ??= 'my-bot';
        $materials = array_filter(['metal' => $metal, 'credits' => $credits], fn($v) => null !== $v);

        return $this->nha->registerAgentIdentity($name, $materials)->then(function (array $identity) {
            $agent_id = $identity['agent_id'];

            $this->state->setDefaultAgent($agent_id, $identity['token']);
            $this->nha->setAgentToken($identity['token']);

            return NHA::createBuilder()->addComponent(self::textContainer(
                "### ✅ Registered agent **#{$agent_id}**\nThis is now the default agent for future commands.",
            ));
        });
    }

    public function observe(?int $agent_id): PromiseInterface
    {
        return $this->nha->observe($this->resolveAgentId($agent_id))->then(
            fn(AgentObservation $obs) => NHA::createBuilder()->addComponent($obs->toContainer($this->nha)),
        );
    }

    // --- Generic + convenience actions --------------------------------------

    public function act(?int $agent_id, string $verb, ?string $argsJson): PromiseInterface
    {
        $args = [];
        if ($argsJson) {
            $args = json_decode($argsJson, true);
            if (! is_array($args)) {
                return reject(new \InvalidArgumentException('`args` must be a valid JSON object, e.g. `{"dx":1,"dy":0}`.'));
            }
        }

        $agent_id = $this->resolveAgentId($agent_id);

        return $this->nha->intent($agent_id, $verb, $args)->then(
            fn() => NHA::createBuilder()->addComponent(self::textContainer("✅ Queued **{$verb}** for agent #{$agent_id}.")),
        );
    }

    public function move(?int $agent_id, int $dx, int $dy): PromiseInterface
    {
        $agent_id = $this->resolveAgentId($agent_id);

        return $this->nha->move($agent_id, $dx, $dy)->then(
            fn() => NHA::createBuilder()->addComponent(self::textContainer("✅ Queued **move** ({$dx}, {$dy}) for agent #{$agent_id}.")),
        );
    }

    public function mine(?int $agent_id, ?int $n): PromiseInterface
    {
        return $this->gatherVerb('mine', $agent_id, $n);
    }

    public function chop(?int $agent_id, ?int $n): PromiseInterface
    {
        return $this->gatherVerb('chop', $agent_id, $n);
    }

    public function gather(?int $agent_id, ?int $n): PromiseInterface
    {
        return $this->gatherVerb('gather', $agent_id, $n);
    }

    protected function gatherVerb(string $verb, ?int $agent_id, ?int $n): PromiseInterface
    {
        $agent_id = $this->resolveAgentId($agent_id);
        $n ??= 1;

        return $this->nha->intent($agent_id, $verb, ['n' => $n])->then(
            fn() => NHA::createBuilder()->addComponent(self::textContainer("✅ Queued **{$verb}** x{$n} for agent #{$agent_id}.")),
        );
    }

    public function say(?int $agent_id, string $text): PromiseInterface
    {
        $agent_id = $this->resolveAgentId($agent_id);

        return $this->nha->say($agent_id, $text)->then(
            fn() => NHA::createBuilder()->addComponent(self::textContainer("💬 Agent #{$agent_id} said: {$text}")),
        );
    }

    public function tell(?int $agent_id, int $to, string $text): PromiseInterface
    {
        $agent_id = $this->resolveAgentId($agent_id);

        return $this->nha->tell($agent_id, $to, $text)->then(
            fn() => NHA::createBuilder()->addComponent(self::textContainer("💬 Agent #{$agent_id} told #{$to}: {$text}")),
        );
    }

    // --- Read-only world data -----------------------------------------------

    public function world(): PromiseInterface
    {
        return $this->nha->world->getWorld()->then(fn($data) => self::jsonMessage('World', $data));
    }

    public function map(): PromiseInterface
    {
        return $this->nha->world->getMap()->then(fn($data) => self::jsonMessage('Map', $data));
    }

    public function market(): PromiseInterface
    {
        return $this->nha->economy->getMarket()->then(fn($data) => self::jsonMessage('Market', $data));
    }

    public function roster(): PromiseInterface
    {
        return $this->nha->social->getRoster()->then(fn($data) => self::jsonMessage('Roster', $data));
    }

    public function rules(): PromiseInterface
    {
        return $this->nha->social->getRules()->then(fn($data) => self::jsonMessage('Rules', $data));
    }

    public function contracts(): PromiseInterface
    {
        return $this->nha->economy->getContracts()->then(fn($data) => self::jsonMessage('Contracts', $data));
    }

    public function agentInfo(int $agent_id): PromiseInterface
    {
        return $this->nha->agents->getAgentInfo($agent_id)->then(fn($data) => self::jsonMessage("Agent #{$agent_id}", $data));
    }
}
