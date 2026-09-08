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

use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Channel\Message;
use NHA\Commands;
use NHA\NHA;

use function React\Promise\reject;

/**
 * The `!nha` prefix-command tree (MessageCommandClient) — the primary chat
 * interface. Every subcommand here is also reachable as a `/nha` slash
 * subcommand ({@see SlashCommands}); the handlers are the same
 * {@see \NHA\Commands} methods.
 *
 * @since 3.1.27
 */
final class ChatCommands
{
    public function __construct(
        private readonly NHA $nha,
        private readonly Commands $commands,
        private readonly Replies $replies,
    ) {}

    public function register(): void
    {
        $c = $this->commands;
        $reply = fn(Message $m, $promise) => $this->replies->toMessage($m, $promise);

        $root = $this->nha->registerCommand('nha', function (Message $message): void {
            $message->channel->sendMessage(NHA::createBuilder()->addComponent(Container::new()->addComponents([
                TextDisplay::new('Try `!nha help` for a list of sub-commands, or use the `/nha` slash command.'),
            ])));
        }, [
            'description' => 'Control your NHA (https://nha.recluse.lol) agent.',
            'usage' => '<register|observe|act|move|mine|chop|gather|say|tell|read <board>|intent <id>|'
                . 'sell|buy|heal|attack|deploy|finalize|depart|…> [args…] — `!nha help` lists every sub-command',
        ]);

        $root->registerSubCommand('register', function (Message $message, array $args) use ($c, $reply): void {
            [$name, $metal, $credits] = array_pad($args, 3, null);
            $reply($message, $c->register(
                $name,
                null !== $metal ? (int) $metal : NHA::DEFAULT_MATERIALS['metal'],
                null !== $credits ? (int) $credits : NHA::DEFAULT_MATERIALS['credits'],
                (string) $message->author->id,
            ));
        }, ['description' => 'Register a new agent (becomes the default for future commands).', 'usage' => '[name] [metal] [credits]']);

        $root->registerSubCommand('observe', function (Message $message, array $args) use ($c, $reply): void {
            $reply($message, $c->observe(isset($args[0]) ? (int) $args[0] : null));
        }, ['description' => 'Observe the world from your agent\'s perspective.', 'usage' => '[agent_id]', 'aliases' => ['obs']]);

        $root->registerSubCommand('act', function (Message $message, array $args) use ($c, $reply): void {
            $verb = array_shift($args);
            if (! $verb) {
                $reply($message, reject(new \InvalidArgumentException('Usage: `!nha act <verb> [json args]`')));

                return;
            }
            $reply($message, $c->act(null, $verb, $args ? implode(' ', $args) : null));
        }, ['description' => 'Send any raw verb + JSON args intent, e.g. `attack {"weapon":"kinetic_gun","target":7}`.', 'usage' => '<verb> [json]']);

        $root->registerSubCommand('move', function (Message $message, array $args) use ($c, $reply): void {
            [$dx, $dy] = array_pad($args, 2, 0);
            $reply($message, $c->move(null, (int) $dx, (int) $dy));
        }, ['description' => 'Move by (dx, dy).', 'usage' => '<dx> <dy>']);

        foreach (['mine', 'chop', 'gather'] as $verb) {
            $root->registerSubCommand($verb, function (Message $message, array $args) use ($c, $reply, $verb): void {
                $reply($message, $c->{$verb}(null, isset($args[0]) ? (int) $args[0] : null));
            }, ['description' => ucfirst($verb) . ' nearby resources.', 'usage' => '[n]']);
        }

        $root->registerSubCommand('say', function (Message $message, array $args) use ($c, $reply): void {
            $reply($message, $c->say(null, implode(' ', $args)));
        }, ['description' => 'Say something in the world chat.', 'usage' => '<text>']);

        $root->registerSubCommand('tell', function (Message $message, array $args) use ($c, $reply): void {
            $to = (int) array_shift($args);
            $reply($message, $c->tell(null, $to, implode(' ', $args)));
        }, ['description' => 'Privately tell another agent something.', 'usage' => '<to> <text>']);

        foreach (['world', 'map', 'market', 'roster', 'rules', 'contracts', 'depot'] as $readOnly) {
            $root->registerSubCommand($readOnly, function (Message $message) use ($c, $reply, $readOnly): void {
                $reply($message, $c->{$readOnly}());
            }, ['description' => "Show the current {$readOnly}."]);
        }

        $root->registerSubCommand('read', function (Message $message, array $args) use ($c, $reply): void {
            $board = array_shift($args) ?? '';
            $reply($message, $c->board($board, [
                'id' => $args[0] ?? null, 'body' => $args[0] ?? null, 'resource' => $args[0] ?? null,
                'x' => $args[0] ?? null, 'y' => $args[1] ?? null, 'limit' => $args[2] ?? null,
            ]));
        }, ['description' => 'Read any board: ' . implode(', ', Commands::BOARDS) . '.', 'usage' => '<board> [arg|x] [y] [limit]']);

        $root->registerSubCommand('agent', function (Message $message, array $args) use ($c, $reply): void {
            $reply($message, $c->agentInfo((int) ($args[0] ?? 0)));
        }, ['description' => 'Look up any agent\'s public info.', 'usage' => '<agent_id>']);

        $root->registerSubCommand('intent', function (Message $message, array $args) use ($c, $reply): void {
            $id = $args[0] ?? null;
            $reply($message, $id !== null
                ? $c->intentStatus($id)
                : reject(new \InvalidArgumentException('Usage: `!nha intent <queued_intent_id>`')));
        }, ['description' => 'Check whether a queued intent applied or was rejected.', 'usage' => '<queued_intent_id>']);

        // Table-driven prefix subcommands for every remaining action verb. Each
        // entry maps positional chat args → the Commands:: call. `?` args → null.
        $verbSubCommands = [
            'moveto' => [['<x>', '<y>'], fn($a) => $c->moveTo(null, (int) ($a[0] ?? 0), (int) ($a[1] ?? 0))],
            'plant' => [[], fn($a) => $c->plant(null)],
            'ride' => [[], fn($a) => $c->ride(null)],
            'launch' => [[], fn($a) => $c->launch(null)],
            'land' => [[], fn($a) => $c->land(null)],
            'land_moon' => [[], fn($a) => $c->landMoon(null)],
            'land_body' => [[], fn($a) => $c->landBody(null)],
            'distress' => [[], fn($a) => $c->distress(null)],
            'dock' => [[], fn($a) => $c->dock(null)],
            'attune' => [[], fn($a) => $c->attune(null)],
            'deploy' => [[], fn($a) => $c->deploy(null)],
            'arm' => [[], fn($a) => $c->arm(null)],
            'finalize' => [['[name]'], fn($a) => $c->finalize(null, $a[0] ?? null)],
            'depart' => [['<dest>'], fn($a) => $c->depart(null, (string) ($a[0] ?? ''))],
            'sell' => [['<resource>', '[n]'], fn($a) => $c->sell(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
            'buy' => [['<resource>', '[n]'], fn($a) => $c->buy(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
            'deposit' => [['<resource>', '[n]'], fn($a) => $c->deposit(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
            'heal' => [['[target]', '[item]'], fn($a) => $c->heal(null, isset($a[0]) ? (int) $a[0] : null, $a[1] ?? null)],
            'attack' => [['<target>', '[weapon]'], fn($a) => $c->attack(null, (int) ($a[0] ?? 0), $a[1] ?? null)],
            'detonate' => [['<bomb>'], fn($a) => $c->detonate(null, $a[0] ?? '')],
            'steal' => [['<from>', '<resource>', '[n]'], fn($a) => $c->steal(null, (int) ($a[0] ?? 0), (string) ($a[1] ?? ''), isset($a[2]) ? (int) $a[2] : null)],
            'collect' => [['<loot>'], fn($a) => $c->collect(null, $a[0] ?? '')],
            'ally' => [['<to>'], fn($a) => $c->ally(null, (int) ($a[0] ?? 0))],
            'accept_ally' => [['<to>'], fn($a) => $c->acceptAlly(null, (int) ($a[0] ?? 0))],
            'unally' => [['<to>'], fn($a) => $c->unally(null, (int) ($a[0] ?? 0))],
            'declare_war' => [['<to>'], fn($a) => $c->declareWar(null, (int) ($a[0] ?? 0))],
            'make_peace' => [['<to>'], fn($a) => $c->makePeace(null, (int) ($a[0] ?? 0))],
            'cancel' => [['<order_id>'], fn($a) => $c->cancelOrder(null, $a[0] ?? '')],
            'fulfill' => [['<contract_id>'], fn($a) => $c->fulfill(null, $a[0] ?? '')],
            'revoke' => [['<contract_id>'], fn($a) => $c->revoke(null, $a[0] ?? '')],
        ];
        foreach ($verbSubCommands as $verbName => [$argHints, $handler]) {
            $root->registerSubCommand($verbName, function (Message $message, array $args) use ($reply, $handler): void {
                $reply($message, $handler($args));
            }, ['description' => "Queue the `{$verbName}` action.", 'usage' => implode(' ', $argHints)]);
        }

        $root->registerSubCommand('think', function (Message $message, array $args) use ($c, $reply): void {
            $reply($message, $c->think(isset($args[0]) ? (int) $args[0] : null));
        }, ['description' => 'Ask the LLM what to do next and queue it.', 'usage' => '[agent_id]']);

        $root->registerSubCommand('autoplay', function (Message $message, array $args) use ($c, $reply): void {
            $reply($message, $c->autoplay(match (strtolower($args[0] ?? '')) {
                'on', 'start', '1', 'true' => true,
                'off', 'stop', '0', 'false' => false,
                default => null,
            }));
        }, ['description' => 'Turn the autonomous LLM play loop on/off (or show status).', 'usage' => '[on|off]']);

        // Standalone top-level aliases for the most common actions.
        foreach (['observe', 'say', 'act'] as $alias) {
            $this->nha->registerCommand($alias, function (Message $message, array $args) use ($root, $alias): void {
                $root->handle($message, array_merge([$alias], $args));
            }, ['description' => "Shortcut for `!nha {$alias}`.", 'showHelp' => false]);
        }
    }
}
