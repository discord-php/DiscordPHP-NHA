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

use Discord\Builders\CommandBuilder;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Activity;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Discord\WebSockets\Event;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Promise\set_rejection_handler;

$autoload_path = file_exists($autoload_path = __DIR__ . '/vendor/autoload.php') ? $autoload_path
    : (file_exists($autoload_path = dirname(__DIR__) . '/vendor/autoload.php') ? $autoload_path : null);
$autoload_path ? require ($autoload_path) : throw new \Exception('Composer autoloader not found. Run `composer update` and try again.');

function loadEnv(string $filePath): void
{
    if (! file_exists($filePath)) {
        throw new \Exception('The .env file does not exist.');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filteredLines = array_filter(array_map('trim', $lines), fn($line) => $line && ! str_starts_with($line, '#'));

    array_walk($filteredLines, function ($line) {
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (! array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
        }
    });
}

$env_path = file_exists($env_path = getcwd() . '/.env') ? $env_path
    : (file_exists($env_path = dirname(getcwd()) . '/.env') ? $env_path : null);
$env_path ? loadEnv($env_path) : throw new \Exception('The .env file does not exist. Please create one in the root directory.');

$error_channel_id = getenv('ERROR_CHANNEL_ID') ?: null;
$channel_id = getenv('NHA_CHANNEL_ID') ?: null;
$poll_interval = (float) (getenv('NHA_POLL_INTERVAL') ?: 5);

$streamHandler = new StreamHandler('php://stdout', Level::Debug);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('NHA', [$streamHandler]);

$nha = new NHA([
    'logger' => $logger,
    'token' => getenv('TOKEN'),
    'prefix' => '!',
]);

/**
 * Relays a fatal error/rejection to the configured error channel, if any.
 */
$reportFatalError = function (\Throwable $e) use ($nha, $error_channel_id, $logger): void {
    $logger->warning("Unhandled error: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]");

    if ($error_channel_id) {
        $nha->getChannel($error_channel_id)?->sendMessage(NHA::createBuilder()->addComponent(Container::new()->addComponents([
            TextDisplay::new("⚠️ **Unhandled error**: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]"),
        ])));
    }
};

set_rejection_handler($reportFatalError);
set_exception_handler($reportFatalError);

$state = new StateStore(__DIR__ . '/var/state.json');
if ($token = $state->getDefaultAgentToken()) {
    $nha->setAgentToken($token);
}
$commands = new Commands($nha, $state);
$userCommands = new UserCommands($nha, $state);

/**
 * Builds a container carrying a single line of text, used for quick
 * confirmations/errors shared by every entry point.
 */
$text = fn(string $content): Container => Container::new()->addComponents([TextDisplay::new($content)]);

/**
 * Runs a Commands:: promise and reports the outcome back to a chat Message.
 */
$replyToMessage = function (Message $message, PromiseInterface $promise) use ($nha, $text): void {
    $promise->then(
        fn($builder) => $message->channel->sendMessage($builder),
        fn(\Throwable $e) => $message->channel->sendMessage($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
    );
};

/**
 * Runs a Commands:: promise and reports the outcome back to an Interaction,
 * deferring the response first since world requests are network calls.
 */
$replyToInteraction = function (Interaction $interaction, PromiseInterface $promise) use ($nha, $text): PromiseInterface {
    return $interaction->acknowledgeWithResponse()->then(fn() => $promise)->then(
        fn($builder) => $interaction->updateOriginalResponse($builder),
        fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
    );
};

/**
 * Flattens an interaction's (sub)command options into a plain assoc array.
 */
$flattenOptions = function (?iterable $options): array {
    $out = [];
    foreach ($options ?? [] as $option) {
        $out[$option->name] = $option->value;
    }

    return $out;
};

// -----------------------------------------------------------------------
// Chat commands (MessageCommandClient) — the primary interface. Every
// subcommand below is also reachable as a slash subcommand, and the most
// common ones additionally get a standalone top-level alias further down.
// -----------------------------------------------------------------------

$nha_cmd = $nha->registerCommand('nha', function (Message $message, array $args) {
    $message->channel->sendMessage(NHA::createBuilder()->addComponent(Container::new()->addComponents([
        TextDisplay::new("Try `!nha help` for a list of sub-commands, or use the `/nha` slash command."),
    ])));
}, [
    'description' => 'Control your NHA (https://nha.recluse.lol) agent.',
    'usage' => '<register|observe|act|move|mine|chop|gather|say|tell|world|market|roster|rules|agent> [args...]',
]);

$nha_cmd->registerSubCommand('register', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    [$name, $metal, $credits] = array_pad($args, 3, null);
    $replyToMessage($message, $commands->register($name, null !== $metal ? (int) $metal : null, null !== $credits ? (int) $credits : null));
}, ['description' => 'Register a new agent (becomes the default for future commands).', 'usage' => '[name] [metal] [credits]']);

$nha_cmd->registerSubCommand('observe', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $replyToMessage($message, $commands->observe(isset($args[0]) ? (int) $args[0] : null));
}, ['description' => 'Observe the world from your agent\'s perspective.', 'usage' => '[agent_id]', 'aliases' => ['obs']]);

$nha_cmd->registerSubCommand('act', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $verb = array_shift($args);
    if (! $verb) {
        $replyToMessage($message, \React\Promise\reject(new \InvalidArgumentException('Usage: `!nha act <verb> [json args]`')));

        return;
    }
    $replyToMessage($message, $commands->act(null, $verb, $args ? implode(' ', $args) : null));
}, ['description' => 'Send any raw verb + JSON args intent, e.g. `attack {"weapon":"kinetic_gun","target":7}`.', 'usage' => '<verb> [json]']);

$nha_cmd->registerSubCommand('move', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    [$dx, $dy] = array_pad($args, 2, 0);
    $replyToMessage($message, $commands->move(null, (int) $dx, (int) $dy));
}, ['description' => 'Move by (dx, dy).', 'usage' => '<dx> <dy>']);

foreach (['mine', 'chop', 'gather'] as $verb) {
    $nha_cmd->registerSubCommand($verb, function (Message $message, array $args) use ($commands, $replyToMessage, $verb): void {
        $replyToMessage($message, $commands->{$verb}(null, isset($args[0]) ? (int) $args[0] : null));
    }, ['description' => ucfirst($verb) . ' nearby resources.', 'usage' => '[n]']);
}

$nha_cmd->registerSubCommand('say', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $replyToMessage($message, $commands->say(null, implode(' ', $args)));
}, ['description' => 'Say something in the world chat.', 'usage' => '<text>']);

$nha_cmd->registerSubCommand('tell', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $to = (int) array_shift($args);
    $replyToMessage($message, $commands->tell(null, $to, implode(' ', $args)));
}, ['description' => 'Privately tell another agent something.', 'usage' => '<to> <text>']);

foreach (['world', 'map', 'market', 'roster', 'rules', 'contracts'] as $readOnly) {
    $nha_cmd->registerSubCommand($readOnly, function (Message $message) use ($commands, $replyToMessage, $readOnly): void {
        $replyToMessage($message, $commands->{$readOnly}());
    }, ['description' => "Show the current {$readOnly}."]);
}

$nha_cmd->registerSubCommand('agent', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $replyToMessage($message, $commands->agentInfo((int) ($args[0] ?? 0)));
}, ['description' => 'Look up any agent\'s public info.', 'usage' => '<agent_id>']);

// Standalone top-level aliases for the most common actions.
foreach (['observe', 'say', 'act'] as $alias) {
    $nha->registerCommand($alias, function (Message $message, array $args) use ($nha_cmd, $alias): void {
        $nha_cmd->handle($message, array_merge([$alias], $args));
    }, ['description' => "Shortcut for `!nha {$alias}`.", 'showHelp' => false]);
}

// -----------------------------------------------------------------------
// Slash commands — a second, equivalent interface over the same Commands
// handlers, registered lazily once the application/gateway are both ready.
// -----------------------------------------------------------------------

$registerSlashCommands = function (NHA $nha) use ($commands, $userCommands, $text, $replyToInteraction, $flattenOptions): void {
    $nha->application->commands->freshen()->then(function (GlobalCommandRepository $existing) use ($nha, $commands, $userCommands, $text, $replyToInteraction, $flattenOptions): void {
        $opt = function (int $type, string $name, string $description, bool $required = false) use ($nha): Option {
            /** @var Option $option */
            $option = $nha->getFactory()->part(Option::class);
            $option->setType($type)->setName($name)->setDescription($description)->setRequired($required);

            return $option;
        };

        $sub = function (string $name, string $description, array $options = []) use ($opt): Option {
            $subOption = $opt(Option::SUB_COMMAND, $name, $description);
            foreach ($options as $o) {
                $subOption->addOption($o);
            }

            return $subOption;
        };

        $agentIdOpt = fn() => $opt(Option::INTEGER, 'agent_id', 'Agent id (defaults to your registered agent).');

        $subCommands = [
            $sub('register', 'Register a new agent (becomes the default).', [
                $opt(Option::STRING, 'name', 'Agent name.'),
                $opt(Option::INTEGER, 'metal', 'Starting metal.'),
                $opt(Option::INTEGER, 'credits', 'Starting credits.'),
            ]),
            $sub('observe', 'Observe the world from your agent\'s perspective.', [$agentIdOpt()]),
            $sub('act', 'Send any raw verb + JSON args intent.', [
                $opt(Option::STRING, 'verb', 'Verb to perform, e.g. attack, trade, contract.', true),
                $opt(Option::STRING, 'args', 'JSON object of args, e.g. {"dx":1,"dy":0}.'),
                $agentIdOpt(),
            ]),
            $sub('move', 'Move by (dx, dy).', [
                $opt(Option::INTEGER, 'dx', 'Delta X.', true),
                $opt(Option::INTEGER, 'dy', 'Delta Y.', true),
                $agentIdOpt(),
            ]),
            $sub('mine', 'Mine nearby minerals.', [$opt(Option::INTEGER, 'n', 'Amount to mine.'), $agentIdOpt()]),
            $sub('chop', 'Chop nearby trees.', [$opt(Option::INTEGER, 'n', 'Amount to chop.'), $agentIdOpt()]),
            $sub('gather', 'Forage the nearest plant.', [$opt(Option::INTEGER, 'n', 'Amount to gather.'), $agentIdOpt()]),
            $sub('say', 'Say something in the world chat.', [$opt(Option::STRING, 'text', 'Message text.', true), $agentIdOpt()]),
            $sub('tell', 'Privately tell another agent something.', [
                $opt(Option::INTEGER, 'to', 'Target agent id.', true),
                $opt(Option::STRING, 'text', 'Message text.', true),
                $agentIdOpt(),
            ]),
            $sub('world', 'Show the current world state.'),
            $sub('market', 'Show the current market.'),
            $sub('roster', 'Show the agent roster.'),
            $sub('rules', 'Show the world rules.'),
            $sub('contracts', 'Show the open contracts board.'),
            $sub('agent', 'Look up any agent\'s public info.', [$opt(Option::INTEGER, 'agent_id', 'Agent id to look up.', true)]),
        ];

        $dispatch = function (string $sub, array $a) use ($commands): PromiseInterface {
            return match ($sub) {
                'register' => $commands->register($a['name'] ?? null, $a['metal'] ?? 40, $a['credits'] ?? 150),
                'observe' => $commands->observe($a['agent_id'] ?? null),
                'act' => $commands->act($a['agent_id'] ?? null, $a['verb'], $a['args'] ?? null),
                'move' => $commands->move($a['agent_id'] ?? null, (int) $a['dx'], (int) $a['dy']),
                'mine' => $commands->mine($a['agent_id'] ?? null, $a['n'] ?? null),
                'chop' => $commands->chop($a['agent_id'] ?? null, $a['n'] ?? null),
                'gather' => $commands->gather($a['agent_id'] ?? null, $a['n'] ?? null),
                'say' => $commands->say($a['agent_id'] ?? null, $a['text']),
                'tell' => $commands->tell($a['agent_id'] ?? null, (int) $a['to'], $a['text']),
                'world' => $commands->world(),
                'map' => $commands->map(),
                'market' => $commands->market(),
                'roster' => $commands->roster(),
                'rules' => $commands->rules(),
                'contracts' => $commands->contracts(),
                'agent' => $commands->agentInfo((int) $a['agent_id']),
                default => \React\Promise\reject(new \InvalidArgumentException("Unknown sub-command `{$sub}`.")),
            };
        };

        $nha->listenCommand('nha', function (Interaction $interaction) use ($replyToInteraction, $flattenOptions, $dispatch): PromiseInterface {
            $chosen = $interaction->data->options->first();
            $args = $flattenOptions($chosen->options ?? []);

            return $replyToInteraction($interaction, $dispatch($chosen->name, $args));
        });

        if (! $existing->get('name', 'nha')) {
            $nha->logger->debug('[GLOBAL APPLICATION COMMAND] Creating `nha` command...');
            $builder = CommandBuilder::new()
                ->setName('nha')
                ->setType(Command::CHAT_INPUT)
                ->setDescription('Control your NHA (https://nha.recluse.lol) agent.');
            foreach ($subCommands as $subCommand) {
                $builder->addOption($subCommand);
            }
            $builder->create($existing)->save('nha initial creation');
        }

        $nha->listenCommand('start', function (Interaction $interaction) use ($userCommands): PromiseInterface {
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $interaction->updateOriginalResponse($userCommands->start((string) $interaction->user->id)),
            );
        });

        $nha->listenCommand('login', function (Interaction $interaction) use ($userCommands, $nha, $text): PromiseInterface {
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $userCommands->login((string) $interaction->user->id),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('observe', function (Interaction $interaction) use ($userCommands, $nha, $text): PromiseInterface {
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $userCommands->observe((string) $interaction->user->id),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('move', function (Interaction $interaction) use ($userCommands, $nha, $text, $flattenOptions): PromiseInterface {
            $args = $flattenOptions($interaction->data->options ?? []);

            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $userCommands->move((string) $interaction->user->id, (int) $args['dx'], (int) $args['dy']),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $playerActionCommands = [
            'mine' => [[ $opt(Option::INTEGER, 'n', 'Amount to mine.') ], fn(array $args) => ['mine', ['n' => (int) ($args['n'] ?? 1)]]],
            'chop' => [[ $opt(Option::INTEGER, 'n', 'Amount to chop.') ], fn(array $args) => ['chop', ['n' => (int) ($args['n'] ?? 1)]]],
            'gather' => [[ $opt(Option::INTEGER, 'n', 'Amount to gather.') ], fn(array $args) => ['gather', ['n' => (int) ($args['n'] ?? 1)]]],
            'plant' => [[], fn(array $args) => ['plant', []]],
            'ride' => [[], fn(array $args) => ['ride', []]],
            'launch' => [[], fn(array $args) => ['launch', []]],
            'land' => [[], fn(array $args) => ['land', []]],
            'dock' => [[], fn(array $args) => ['dock', []]],
            'attune' => [[], fn(array $args) => ['attune', []]],
            'say' => [[ $opt(Option::STRING, 'text', 'World chat message.', true) ], fn(array $args) => ['say', ['text' => $args['text']]]],
            'tell' => [[
                $opt(Option::INTEGER, 'to', 'Target agent id.', true),
                $opt(Option::STRING, 'text', 'Private message.', true),
            ], fn(array $args) => ['tell', ['to' => (int) $args['to'], 'text' => $args['text']]]],
        ];

        foreach ($playerActionCommands as $name => [$options, $toIntent]) {
            $nha->listenCommand($name, function (Interaction $interaction) use ($userCommands, $nha, $text, $flattenOptions, $toIntent): PromiseInterface {
                $args = $flattenOptions($interaction->data->options ?? []);
                [$verb, $intentArgs] = $toIntent($args);

                return $interaction->acknowledgeWithResponse(true)->then(
                    fn() => $userCommands->act((string) $interaction->user->id, $verb, $intentArgs),
                )->then(
                    fn($builder) => $interaction->updateOriginalResponse($builder),
                    fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
                );
            });
        }

        $userSlashCommands = [
            'start' => [[], 'Open your private NHA control panel.'],
            'login' => [[], 'Create or reopen your personal NHA agent.'],
            'observe' => [[], 'Observe your personal NHA agent.'],
            'move' => [[
                $opt(Option::INTEGER, 'dx', 'Horizontal movement delta.', true),
                $opt(Option::INTEGER, 'dy', 'Vertical movement delta.', true),
            ], 'Queue movement for your personal NHA agent.'],
        ];

        foreach ($playerActionCommands as $name => [$options]) {
            $userSlashCommands[$name] = [$options, "Queue {$name} for your personal NHA agent."];
        }

        foreach ($userSlashCommands as $name => [$options, $description]) {
            if ($existing->get('name', $name)) {
                continue;
            }

            $builder = CommandBuilder::new()->setName($name)->setType(Command::CHAT_INPUT)->setDescription($description);
            foreach ($options as $option) {
                $builder->addOption($option);
            }
            $builder->create($existing)->save("{$name} initial creation");
        }
    });
};

$init_called = false;
$application_init_called = false;
$maybeStart = function () use (&$init_called, &$application_init_called, $nha, $registerSlashCommands): void {
    if (! $init_called || ! $application_init_called) {
        return;
    }
    $registerSlashCommands($nha);
    $nha->updatePresence(new Activity($nha, ['name' => 'nha.recluse.lol', 'type' => 0]));
};
$nha->once('init', function () use (&$init_called, $maybeStart): void {
    $init_called = true;
    $maybeStart();
});
$nha->once('application-init', function () use (&$application_init_called, $maybeStart): void {
    $application_init_called = true;
    $maybeStart();
});

// -----------------------------------------------------------------------
// Channel relay: periodically observe the default agent and forward new
// world chat/threat data into Discord, and forward plain messages posted
// in that Discord channel back into the world as `say` intents.
// -----------------------------------------------------------------------

if ($channel_id) {
    $seenMessageCount = 0;

    Loop::get()->addPeriodicTimer($poll_interval, function () use ($nha, $state, $channel_id, $text, &$seenMessageCount): void {
        $agent_id = $state->getDefaultAgent();
        if (! $agent_id) {
            return;
        }

        $nha->observe($agent_id)->then(function ($obs) use ($nha, $channel_id, $text, &$seenMessageCount): void {
            $messages = $obs->getMessages();
            $new = array_slice($messages, $seenMessageCount);
            $seenMessageCount = count($messages);

            if (! $new && ! $obs->getThreats()) {
                return;
            }

            $nha->getChannel($channel_id)?->sendMessage(NHA::createBuilder()->addComponent($obs->toContainer($nha)));
        });
    });

    $nha->on(Event::MESSAGE_CREATE, function (Message $message) use ($nha, $state, $channel_id): void {
        if ($message->channel_id != $channel_id || $message->author->bot || str_starts_with($message->content, $nha->options['prefix'])) {
            return;
        }

        if ($agent_id = $state->getDefaultAgent()) {
            $nha->say($agent_id, $message->content);
        }
    });
}

$nha->run();
