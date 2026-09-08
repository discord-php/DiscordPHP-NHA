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

namespace NHA\Bot;

use Discord\Builders\CommandBuilder;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Repository\Interaction\GlobalCommandRepository;
use NHA\AgentContext;
use NHA\Commands;
use NHA\NHA;
use NHA\StateStore;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * The slash-command interface — a second, equivalent surface over the same
 * {@see \NHA\Commands} handlers as {@see ChatCommands}. Registered lazily once
 * the application and gateway are both ready.
 *
 * Global commands are created when missing and PATCHed only when their
 * definition (name + description + every option/choice/bound) has changed since
 * the last successful registration, tracked by a signature in `var/state.json`
 * so an unchanged boot makes zero rate-limited writes.
 *
 * @since 3.1.27
 */
final class SlashCommands
{
    /** Constrained value sets the server expects verbatim. */
    private const WEAPONS = ['kinetic_gun', 'energy_weapon'];
    private const MEDICINES = ['salve', 'stimpack', 'medkit', 'antidote'];
    private const DESTINATIONS = ['deimos', 'phobos', 'mars', 'venus', 'earth'];

    private GlobalCommandRepository $existing;

    /** @var array<string, string> command name => last-registered definition sha1 */
    private array $signatures = [];

    public function __construct(
        private readonly NHA $nha,
        private readonly Commands $commands,
        private readonly StateStore $state,
        private readonly Replies $replies,
    ) {}

    /** Freshen the current command set, then (re)register everything that changed. */
    public function register(): void
    {
        $this->nha->application->commands->freshen()->then(function (GlobalCommandRepository $existing): void {
            $this->existing = $existing;
            $this->signatures = $this->state->getCommandSignatures();

            $this->registerNhaGroup();
            $this->registerStandalone();
        });
    }

    // --- /nha <sub> ----------------------------------------------------------

    private function registerNhaGroup(): void
    {
        $agentId = fn(): Option => $this->option(Option::INTEGER, 'agent_id', 'Agent id (defaults to your registered agent).', false, ['min' => 1]);

        // `board` choices: readable boards minus the 3 with dedicated paths,
        // capped at Discord's 25-choice limit.
        $boards = array_slice(array_values(array_diff(Commands::BOARDS, ['healthz', 'agents', 'agent'])), 0, 25);

        $subCommands = [
            $this->sub('register', 'Register a new agent (becomes the default).', [
                $this->option(Option::STRING, 'name', 'Agent name (1-24 characters).', false, ['min' => 1, 'max' => 24]),
                $this->option(Option::INTEGER, 'metal', 'Starting metal.', false, ['min' => 0]),
                $this->option(Option::INTEGER, 'credits', 'Starting credits.', false, ['min' => 0]),
            ]),
            $this->sub('observe', 'Observe the world from your agent\'s perspective.', [$agentId()]),
            $this->sub('act', 'Send any raw verb + JSON args intent.', [
                $this->option(Option::STRING, 'verb', 'Verb to perform, e.g. attack, trade, contract.', true, ['min' => 1]),
                $this->option(Option::STRING, 'args', 'JSON object of args, e.g. {"dx":1,"dy":0}.'),
                $agentId(),
            ]),
            $this->sub('move', 'Move by (dx, dy).', [
                $this->option(Option::INTEGER, 'dx', 'Delta X.', true),
                $this->option(Option::INTEGER, 'dy', 'Delta Y.', true),
                $agentId(),
            ]),
            $this->sub('mine', 'Mine nearby minerals.', [$this->option(Option::INTEGER, 'n', 'Amount to mine (>=1).', false, ['min' => 1]), $agentId()]),
            $this->sub('chop', 'Chop nearby trees.', [$this->option(Option::INTEGER, 'n', 'Amount to chop (>=1).', false, ['min' => 1]), $agentId()]),
            $this->sub('gather', 'Forage the nearest plant.', [$this->option(Option::INTEGER, 'n', 'Amount to gather (>=1).', false, ['min' => 1]), $agentId()]),
            $this->sub('say', 'Say something in the world chat.', [$this->option(Option::STRING, 'text', 'Message text.', true, ['min' => 1, 'max' => 280]), $agentId()]),
            $this->sub('tell', 'Privately tell another agent something.', [
                $this->option(Option::INTEGER, 'to', 'Target agent id.', true, ['min' => 1]),
                $this->option(Option::STRING, 'text', 'Message text.', true, ['min' => 1, 'max' => 280]),
                $agentId(),
            ]),
            $this->sub('world', 'Show the current world state.'),
            $this->sub('market', 'Show the agent market order book.'),
            $this->sub('depot', 'Show the fixed depot buy/sell prices.'),
            $this->sub('rules', 'Show the crafting rules codex.'),
            $this->sub('agent', 'Look up any agent\'s public info.', [$this->option(Option::INTEGER, 'agent_id', 'Agent id to look up.', true, ['min' => 1])]),
            $this->sub('read', 'Read any world board.', [
                $this->option(Option::STRING, 'board', 'World board to read.', true, ['choices' => $boards]),
                $this->option(Option::STRING, 'arg', 'Board argument: agent id, body name, or resource.'),
                $this->option(Option::INTEGER, 'x', 'X (for the deposits board).', false, ['min' => 0]),
                $this->option(Option::INTEGER, 'y', 'Y (for the deposits board).', false, ['min' => 0]),
                $this->option(Option::INTEGER, 'limit', 'Row limit, where the board supports it.', false, ['min' => 1]),
            ]),
            $this->sub('intent', 'Check whether a queued intent applied or was rejected.', [
                $this->option(Option::INTEGER, 'id', 'The queued_intent id from an action confirmation.', true, ['min' => 1]),
            ]),
            $this->sub('sell', 'Sell a resource to the depot.', [
                $this->option(Option::STRING, 'resource', 'Resource to sell.', true, ['min' => 1]),
                $this->option(Option::INTEGER, 'n', 'Amount (default 1).', false, ['min' => 1]),
                $agentId(),
            ]),
            $this->sub('buy', 'Buy a resource from the depot.', [
                $this->option(Option::STRING, 'resource', 'Resource to buy.', true, ['min' => 1]),
                $this->option(Option::INTEGER, 'n', 'Amount (default 1).', false, ['min' => 1]),
                $agentId(),
            ]),
            $this->sub('heal', 'Apply a medicine to yourself or an ally.', [
                $this->option(Option::INTEGER, 'target', 'Ally to heal (omit to heal yourself).', false, ['min' => 1]),
                $this->option(Option::STRING, 'item', 'Which medicine (engine picks one if omitted).', false, ['choices' => self::MEDICINES]),
                $agentId(),
            ]),
            $this->sub('attack', 'Fire a ranged weapon at a target.', [
                $this->option(Option::INTEGER, 'target', 'Target agent id.', true, ['min' => 1]),
                $this->option(Option::STRING, 'weapon', 'Which weapon (engine picks one if omitted).', false, ['choices' => self::WEAPONS]),
                $agentId(),
            ]),
            $this->sub('deploy', 'Send a finalized vehicle off to roam and mine.', [$agentId()]),
            $this->sub('finalize', 'Assemble your loose parts into one vehicle.', [
                $this->option(Option::STRING, 'name', 'Name for the vehicle (optional).', false, ['min' => 1, 'max' => 24]),
                $agentId(),
            ]),
            $this->sub('think', 'Ask the LLM what to do next and queue it.', [$agentId()]),
            $this->sub('autoplay', 'Turn the autonomous LLM play loop on/off.', [$this->option(Option::STRING, 'state', 'on or off (omit to show status).', false, ['choices' => ['on', 'off']])]),
        ];

        $this->nha->listenCommand('nha', function (Interaction $interaction): PromiseInterface {
            $chosen = $interaction->data->options->first();
            $args = Replies::flattenOptions($chosen->options ?? []);

            return $this->replies->toInteraction($interaction, $this->dispatchNha($chosen->name, $args, (string) $interaction->user->id));
        });

        $this->createCommand('nha', 'Control your NHA (https://nha.recluse.lol) agent.', $subCommands);
    }

    /** Routes a `/nha <sub>` interaction to the matching {@see Commands} call. */
    private function dispatchNha(string $sub, array $a, string $userId): PromiseInterface
    {
        $c = $this->commands;

        return match ($sub) {
            // Pass the invoking user's id so the agent is linked to them and
            // named `user-<id>` — never a bare `user-` (see Commands::register).
            'register' => $c->register($a['name'] ?? null, $a['metal'] ?? 40, $a['credits'] ?? 150, $userId),
            'observe' => $c->observe($a['agent_id'] ?? null),
            'act' => $c->act($a['agent_id'] ?? null, $a['verb'], $a['args'] ?? null),
            'move' => $c->move($a['agent_id'] ?? null, (int) $a['dx'], (int) $a['dy']),
            'mine' => $c->mine($a['agent_id'] ?? null, $a['n'] ?? null),
            'chop' => $c->chop($a['agent_id'] ?? null, $a['n'] ?? null),
            'gather' => $c->gather($a['agent_id'] ?? null, $a['n'] ?? null),
            'say' => $c->say($a['agent_id'] ?? null, $a['text']),
            'tell' => $c->tell($a['agent_id'] ?? null, (int) $a['to'], $a['text']),
            'world' => $c->world(),
            'market' => $c->market(),
            'depot' => $c->depot(),
            'rules' => $c->rules(),
            'agent' => $c->agentInfo((int) $a['agent_id']),
            'read' => $c->board((string) ($a['board'] ?? ''), [
                'id' => $a['arg'] ?? null, 'body' => $a['arg'] ?? null, 'resource' => $a['arg'] ?? null,
                'x' => $a['x'] ?? null, 'y' => $a['y'] ?? null, 'limit' => $a['limit'] ?? null,
            ]),
            'intent' => $c->intentStatus((string) ($a['id'] ?? '')),
            'sell' => $c->sell($a['agent_id'] ?? null, (string) ($a['resource'] ?? ''), $a['n'] ?? null),
            'buy' => $c->buy($a['agent_id'] ?? null, (string) ($a['resource'] ?? ''), $a['n'] ?? null),
            'heal' => $c->heal($a['agent_id'] ?? null, isset($a['target']) ? (int) $a['target'] : null, $a['item'] ?? null),
            'attack' => $c->attack($a['agent_id'] ?? null, (int) ($a['target'] ?? 0), $a['weapon'] ?? null),
            'deploy' => $c->deploy($a['agent_id'] ?? null),
            'finalize' => $c->finalize($a['agent_id'] ?? null, $a['name'] ?? null),
            'think' => $c->think($a['agent_id'] ?? null),
            'autoplay' => $c->autoplay(match (strtolower($a['state'] ?? '')) {
                'on' => true,
                'off' => false,
                default => null,
            }),
            default => reject(new \InvalidArgumentException("Unknown sub-command `{$sub}`.")),
        };
    }

    // --- standalone per-user commands --------------------------------------

    private function registerStandalone(): void
    {
        $c = $this->commands;
        $r = $this->replies;
        $agentOpt = fn(): Option => $this->option(Option::STRING, 'agent', 'Whose agent: omit for yours, "bot", or an agent id.', false, ['min' => 1]);
        $actorFor = fn(Interaction $i, array $args): AgentContext => $c->actor((string) $i->user->id, $args['agent'] ?? null);

        $this->nha->listenCommand('start', fn(Interaction $i): PromiseInterface
            => $r->toInteraction($i, fn() => $c->start((string) $i->user->id), ephemeral: true));

        $this->nha->listenCommand('login', fn(Interaction $i): PromiseInterface
            => $r->toInteraction($i, fn() => $c->login((string) $i->user->id), ephemeral: true));

        $this->nha->listenCommand('help', fn(Interaction $i): PromiseInterface
            => $r->toInteraction($i, fn() => $c->help(Replies::flattenOptions($i->data->options ?? [])['category'] ?? null), ephemeral: true));

        $this->nha->listenCommand('observe', fn(Interaction $i): PromiseInterface
            => $r->toInteraction($i, fn() => $c->observe($actorFor($i, Replies::flattenOptions($i->data->options ?? []))), ephemeral: true));

        $this->nha->listenCommand('move', function (Interaction $i) use ($c, $r, $actorFor): PromiseInterface {
            $args = Replies::flattenOptions($i->data->options ?? []);

            return $r->toInteraction($i, fn() => $c->move($actorFor($i, $args), (int) $args['dx'], (int) $args['dy']), ephemeral: true);
        });

        $countOpt = fn(string $d): Option => $this->option(Option::INTEGER, 'n', $d, false, ['min' => 1]);
        $idOpt = fn(string $name, string $d, bool $req = true): Option => $this->option(Option::INTEGER, $name, $d, $req, ['min' => 1]);
        $msgOpt = fn(string $d): Option => $this->option(Option::STRING, 'text', $d, true, ['min' => 1, 'max' => 280]);
        $nameOpt = fn(): Option => $this->option(Option::STRING, 'name', 'Vehicle name (optional).', false, ['min' => 1, 'max' => 24]);
        $notNull = static fn($v): bool => null !== $v;

        $playerActionCommands = [
            'mine' => [[$countOpt('Amount to mine (>=1).')], fn(array $a) => ['mine', ['n' => (int) ($a['n'] ?? 1)]]],
            'chop' => [[$countOpt('Amount to chop (>=1).')], fn(array $a) => ['chop', ['n' => (int) ($a['n'] ?? 1)]]],
            'gather' => [[$countOpt('Amount to gather (>=1).')], fn(array $a) => ['gather', ['n' => (int) ($a['n'] ?? 1)]]],
            'plant' => [[], fn(array $a) => ['plant', []]],
            'ride' => [[], fn(array $a) => ['ride', []]],
            'launch' => [[], fn(array $a) => ['launch', []]],
            'land' => [[], fn(array $a) => ['land', []]],
            'dock' => [[], fn(array $a) => ['dock', []]],
            'attune' => [[], fn(array $a) => ['attune', []]],
            'say' => [[$msgOpt('World chat message.')], fn(array $a) => ['say', ['text' => $a['text']]]],
            'tell' => [[$idOpt('to', 'Target agent id.'), $msgOpt('Private message.')], fn(array $a) => ['tell', ['to' => (int) $a['to'], 'text' => $a['text']]]],

            // Space & flight.
            'deploy' => [[], fn(array $a) => ['deploy', []]],
            'finalize' => [[$nameOpt()], fn(array $a) => ['finalize', array_filter(['name' => $a['name'] ?? null], $notNull)]],
            'arm' => [[], fn(array $a) => ['arm', []]],
            'land_moon' => [[], fn(array $a) => ['land_moon', []]],
            'land_body' => [[], fn(array $a) => ['land_body', []]],
            'distress' => [[], fn(array $a) => ['distress', []]],
            'depart' => [[$this->option(Option::STRING, 'dest', 'Destination body.', true, ['choices' => self::DESTINATIONS])], fn(array $a) => ['depart', ['dest' => $a['dest']]]],

            // Economy.
            'sell' => [[$this->option(Option::STRING, 'resource', 'Resource to sell.', true, ['min' => 1]), $countOpt('Amount (default 1).')], fn(array $a) => ['sell', ['resource' => $a['resource'], 'n' => (int) ($a['n'] ?? 1)]]],
            'buy' => [[$this->option(Option::STRING, 'resource', 'Resource to buy.', true, ['min' => 1]), $countOpt('Amount (default 1).')], fn(array $a) => ['buy', ['resource' => $a['resource'], 'n' => (int) ($a['n'] ?? 1)]]],

            // Medicine & combat.
            'heal' => [[$idOpt('target', 'Ally to heal (omit for self).', false), $this->option(Option::STRING, 'item', 'Which medicine (engine picks one if omitted).', false, ['choices' => self::MEDICINES])], fn(array $a) => ['heal', array_filter(['target' => isset($a['target']) ? (int) $a['target'] : null, 'item' => $a['item'] ?? null], $notNull)]],
            'attack' => [[$idOpt('target', 'Target agent id.'), $this->option(Option::STRING, 'weapon', 'Which weapon (engine picks one if omitted).', false, ['choices' => self::WEAPONS])], fn(array $a) => ['attack', array_filter(['target' => (int) $a['target'], 'weapon' => $a['weapon'] ?? null], $notNull)]],
            'steal' => [[$idOpt('from', 'Adjacent agent id.'), $this->option(Option::STRING, 'resource', 'Resource to lift.', true, ['min' => 1]), $countOpt('Amount (default 1).')], fn(array $a) => ['steal', ['from' => (int) $a['from'], 'resource' => $a['resource'], 'n' => (int) ($a['n'] ?? 1)]]],
            'collect' => [[$this->option(Option::STRING, 'loot', 'Loot pile id.', true, ['min' => 1])], fn(array $a) => ['collect', ['loot' => $a['loot']]]],

            // Diplomacy.
            'ally' => [[$idOpt('to', 'Agent id.')], fn(array $a) => ['ally', ['to' => (int) $a['to']]]],
            'accept_ally' => [[$idOpt('to', 'Agent id.')], fn(array $a) => ['accept_ally', ['to' => (int) $a['to']]]],
            'unally' => [[$idOpt('to', 'Agent id.')], fn(array $a) => ['unally', ['to' => (int) $a['to']]]],
            'declare_war' => [[$idOpt('to', 'Agent id.')], fn(array $a) => ['declare_war', ['to' => (int) $a['to']]]],
            'make_peace' => [[$idOpt('to', 'Agent id.')], fn(array $a) => ['make_peace', ['to' => (int) $a['to']]]],
        ];

        foreach ($playerActionCommands as $name => [, $toIntent]) {
            $this->nha->listenCommand($name, function (Interaction $i) use ($c, $r, $actorFor, $toIntent): PromiseInterface {
                $args = Replies::flattenOptions($i->data->options ?? []);
                [$verb, $intentArgs] = $toIntent($args);

                return $r->toInteraction($i, fn() => $c->queueVerb($actorFor($i, $args), $verb, $intentArgs), ephemeral: true);
            });
        }

        $userSlashCommands = [
            'help' => [[$this->option(Option::STRING, 'category', 'Topic (omit for the general guide).', false, ['choices' => array_keys(Commands::HELP)])], 'How to play NHA — a categorised guide.'],
            'start' => [[], 'Open your private NHA control panel.'],
            'login' => [[], 'Create or reopen your personal NHA agent.'],
            'observe' => [[$agentOpt()], 'Observe your (or, with `agent`, another) NHA agent.'],
            'move' => [[
                $this->option(Option::INTEGER, 'dx', 'Horizontal movement delta.', true),
                $this->option(Option::INTEGER, 'dy', 'Vertical movement delta.', true),
                $agentOpt(),
            ], 'Queue movement for your (or, with `agent`, another) NHA agent.'],
        ];

        // Every player action also takes the `agent` selector; omitted = your own.
        foreach ($playerActionCommands as $name => [$options]) {
            $userSlashCommands[$name] = [[...$options, $agentOpt()], "Queue {$name} for your NHA agent (add `agent: bot` to run it as the bot)."];
        }

        foreach ($userSlashCommands as $name => [$options, $description]) {
            $this->createCommand($name, $description, $options);
        }
    }

    // --- builders ---------------------------------------------------------

    /**
     * Builds a slash-command option. `$constraints`:
     *   - `choices`  list (value = label) or [label => value] map, ≤25
     *   - `min`/`max` value bounds for INTEGER/NUMBER, length bounds for STRING
     */
    private function option(int $type, string $name, string $description, bool $required = false, array $constraints = []): Option
    {
        /** @var Option $option */
        $option = $this->nha->getFactory()->part(Option::class);
        $option->setType($type)->setName($name)->setDescription($description)->setRequired($required);

        if (array_key_exists('min', $constraints)) {
            $type === Option::STRING
                ? $option->setMinLength((int) $constraints['min'])
                : $option->setMinValue($constraints['min']);
        }
        if (array_key_exists('max', $constraints)) {
            $type === Option::STRING
                ? $option->setMaxLength((int) $constraints['max'])
                : $option->setMaxValue($constraints['max']);
        }
        foreach ($constraints['choices'] ?? [] as $label => $value) {
            $option->addChoice($this->nha->getFactory()->part(Choice::class, [
                'name' => is_int($label) ? (string) $value : $label,
                'value' => $value,
            ]));
        }

        return $option;
    }

    /** A SUB_COMMAND option carrying its own options. */
    private function sub(string $name, string $description, array $options = []): Option
    {
        $sub = $this->option(Option::SUB_COMMAND, $name, $description);
        foreach ($options as $o) {
            $sub->addOption($o);
        }

        return $sub;
    }

    /**
     * Creates the global command when missing, PATCHes it when its definition
     * signature has changed since the last successful registration, and does
     * nothing when it is already current. Every attempt and outcome is logged.
     */
    private function createCommand(string $name, string $description, array $options = []): void
    {
        $builder = CommandBuilder::new()->setName($name)->setType(Command::CHAT_INPUT)->setDescription($description);
        foreach ($options as $option) {
            $builder->addOption($option);
        }

        $definition = $builder->jsonSerialize();
        $hash = sha1(json_encode($definition));
        $current = $this->existing->get('name', $name);

        if ($current !== null && ($this->signatures[$name] ?? null) === $hash) {
            return;
        }

        $this->nha->logger->debug('[GLOBAL APPLICATION COMMAND] ' . ($current === null ? 'Creating' : 'Updating') . " `{$name}` command...");

        if ($current !== null) {
            $current->fill($definition);
            $save = $current->save("{$name} definition update");
        } else {
            $save = $builder->create($this->existing)->save("{$name} initial creation");
        }

        $save->then(
            function () use ($name, $hash, $current): void {
                $this->state->setCommandSignature($name, $hash);
                $this->nha->logger->info('[GLOBAL APPLICATION COMMAND] ' . ($current === null ? 'Created' : 'Updated') . " `{$name}` command.");
            },
            fn(\Throwable $e) => $this->nha->logger->error("[GLOBAL APPLICATION COMMAND] Failed to register `{$name}` command: {$e->getMessage()}"),
        );
    }
}
