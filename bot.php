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
use Discord\Parts\Interactions\Command\Choice;
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

/**
 * The project base directory. Works when run as `php bot.php` from the repo,
 * and when run as a phpacker/phpmicro binary (which lands nested under
 * `bin/build/<name>/<platform>/`) launched directly or from a shortcut, from
 * any working directory: it walks up from the real executable path, then from
 * the working directory, to the first ancestor that looks like the project
 * (has `vendor/autoload.php` or a `.env` beside it).
 */
$baseDir = (static function (): string {
    $seen = [];
    foreach ([\Phar::running(false) ?: null, __FILE__, \getcwd() ?: null] as $start) {
        if ($start === null) {
            continue;
        }
        $dir = \is_dir($start) ? $start : \dirname((string) \preg_replace('#^phar://#', '', $start));
        for ($i = 0; $i < 12; $i++) {
            if (isset($seen[$dir])) {
                break;
            }
            $seen[$dir] = true;
            if (\is_file($dir . '/vendor/autoload.php') || \is_file($dir . '/.env')) {
                return $dir;
            }
            if (($up = \dirname($dir)) === $dir) {
                break;
            }
            $dir = $up;
        }
    }

    return \getcwd() ?: __DIR__;
})();

$autoload_path = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php'
    : (is_file($baseDir . '/vendor/autoload.php') ? $baseDir . '/vendor/autoload.php' : null);
$autoload_path
    ? require $autoload_path
    : throw new \Exception('Composer autoloader not found. Run `composer install`, or keep the binary inside the project directory (searched up from "' . dirname(\Phar::running(false) ?: __FILE__) . '").');

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

$env_path = file_exists($baseDir . '/.env') ? $baseDir . '/.env'
    : (file_exists(getcwd() . '/.env') ? getcwd() . '/.env' : null);
$env_path ? loadEnv($env_path) : throw new \Exception('The .env file does not exist. Create one in the project directory (' . $baseDir . ').');

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
    'disableVoiceClient' => true,
    'nha_base_url' => getenv('NHA_BASE_URL') ?: '',
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

$state = new StateStore($baseDir . '/var/state.json');
if ($token = $state->getDefaultAgentToken()) {
    $nha->setAgentToken($token);
}

// Optional LLM brain: only wired when OLLAMA_URL points at a running `ollama serve`.
// A bare origin (http://host:11434) uses Ollama's native API; a URL ending in
// /v1 (http://host:11434/v1, as in OpenCode's config) uses the OpenAI-compatible one.
$autoPlayer = null;
if ($ollama_url = getenv('OLLAMA_URL')) {
    $ollama_think = match (getenv('OLLAMA_THINK')) {
        '1', 'true' => true,
        '0', 'false' => false,
        default => null,
    };
    $ollama = new Brain\OllamaClient(
        $ollama_url,
        getenv('OLLAMA_MODEL') ?: 'gemma3:27b',
        null,
        (float) (getenv('OLLAMA_TIMEOUT') ?: 120),
        (int) (getenv('OLLAMA_NUM_CTX') ?: 32768),
        $nha->getLoop(),
        $ollama_think,
    );
    $autoPlayer = new Brain\AutoPlayer($nha, new Brain\AgentBrain($ollama), $state);
    $logger->info("LLM brain enabled: {$ollama_url} (" . (getenv('OLLAMA_MODEL') ?: 'gemma3:27b') . ')');
}

$commands = new Commands($nha, $state, $autoPlayer);

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
    'usage' => "<register|observe|act|move|mine|chop|gather|say|tell|read <board>|intent <id>|"
        . 'sell|buy|heal|attack|deploy|finalize|depart|…> [args…] — `!nha help` lists every sub-command',
]);

$nha_cmd->registerSubCommand('register', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    [$name, $metal, $credits] = array_pad($args, 3, null);
    $materials = [
        'metal' => null !== $metal ? (int) $metal : NHA::DEFAULT_MATERIALS['metal'],
        'credits' => null !== $credits ? (int) $credits : NHA::DEFAULT_MATERIALS['credits'],
    ];
    $replyToMessage($message, $commands->register($name, $materials['metal'], $materials['credits'], (string) $message->author->id));
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

foreach (['world', 'map', 'market', 'roster', 'rules', 'contracts', 'depot'] as $readOnly) {
    $nha_cmd->registerSubCommand($readOnly, function (Message $message) use ($commands, $replyToMessage, $readOnly): void {
        $replyToMessage($message, $commands->{$readOnly}());
    }, ['description' => "Show the current {$readOnly}."]);
}

$nha_cmd->registerSubCommand('read', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $board = array_shift($args) ?? '';
    $rest = $args;
    $replyToMessage($message, $commands->board($board, [
        'id' => $rest[0] ?? null, 'body' => $rest[0] ?? null, 'resource' => $rest[0] ?? null,
        'x' => $rest[0] ?? null, 'y' => $rest[1] ?? null, 'limit' => $rest[2] ?? null,
    ]));
}, ['description' => 'Read any board: ' . implode(', ', Commands::BOARDS) . '.', 'usage' => '<board> [arg|x] [y] [limit]']);

$nha_cmd->registerSubCommand('agent', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $replyToMessage($message, $commands->agentInfo((int) ($args[0] ?? 0)));
}, ['description' => 'Look up any agent\'s public info.', 'usage' => '<agent_id>']);

$nha_cmd->registerSubCommand('intent', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $id = $args[0] ?? null;
    $replyToMessage($message, $id !== null
        ? $commands->intentStatus($id)
        : \React\Promise\reject(new \InvalidArgumentException('Usage: `!nha intent <queued_intent_id>`')));
}, ['description' => 'Check whether a queued intent applied or was rejected.', 'usage' => '<queued_intent_id>']);

// Table-driven prefix subcommands for every remaining action verb. Each entry
// maps positional chat args → the Commands:: call. `?` args default to null.
$verbSubCommands = [
    'moveto' => [['<x>', '<y>'], fn($a) => $commands->moveTo(null, (int) ($a[0] ?? 0), (int) ($a[1] ?? 0))],
    'plant' => [[], fn($a) => $commands->plant(null)],
    'ride' => [[], fn($a) => $commands->ride(null)],
    'launch' => [[], fn($a) => $commands->launch(null)],
    'land' => [[], fn($a) => $commands->land(null)],
    'land_moon' => [[], fn($a) => $commands->landMoon(null)],
    'land_body' => [[], fn($a) => $commands->landBody(null)],
    'distress' => [[], fn($a) => $commands->distress(null)],
    'dock' => [[], fn($a) => $commands->dock(null)],
    'attune' => [[], fn($a) => $commands->attune(null)],
    'deploy' => [[], fn($a) => $commands->deploy(null)],
    'arm' => [[], fn($a) => $commands->arm(null)],
    'finalize' => [['[name]'], fn($a) => $commands->finalize(null, $a[0] ?? null)],
    'depart' => [['<dest>'], fn($a) => $commands->depart(null, (string) ($a[0] ?? ''))],
    'sell' => [['<resource>', '[n]'], fn($a) => $commands->sell(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
    'buy' => [['<resource>', '[n]'], fn($a) => $commands->buy(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
    'deposit' => [['<resource>', '[n]'], fn($a) => $commands->deposit(null, (string) ($a[0] ?? ''), isset($a[1]) ? (int) $a[1] : null)],
    'heal' => [['[target]', '[item]'], fn($a) => $commands->heal(null, isset($a[0]) ? (int) $a[0] : null, $a[1] ?? null)],
    'attack' => [['<target>', '[weapon]'], fn($a) => $commands->attack(null, (int) ($a[0] ?? 0), $a[1] ?? null)],
    'detonate' => [['<bomb>'], fn($a) => $commands->detonate(null, $a[0] ?? '')],
    'steal' => [['<from>', '<resource>', '[n]'], fn($a) => $commands->steal(null, (int) ($a[0] ?? 0), (string) ($a[1] ?? ''), isset($a[2]) ? (int) $a[2] : null)],
    'collect' => [['<loot>'], fn($a) => $commands->collect(null, $a[0] ?? '')],
    'ally' => [['<to>'], fn($a) => $commands->ally(null, (int) ($a[0] ?? 0))],
    'accept_ally' => [['<to>'], fn($a) => $commands->acceptAlly(null, (int) ($a[0] ?? 0))],
    'unally' => [['<to>'], fn($a) => $commands->unally(null, (int) ($a[0] ?? 0))],
    'declare_war' => [['<to>'], fn($a) => $commands->declareWar(null, (int) ($a[0] ?? 0))],
    'make_peace' => [['<to>'], fn($a) => $commands->makePeace(null, (int) ($a[0] ?? 0))],
    'cancel' => [['<order_id>'], fn($a) => $commands->cancelOrder(null, $a[0] ?? '')],
    'fulfill' => [['<contract_id>'], fn($a) => $commands->fulfill(null, $a[0] ?? '')],
    'revoke' => [['<contract_id>'], fn($a) => $commands->revoke(null, $a[0] ?? '')],
];
foreach ($verbSubCommands as $verbName => [$argHints, $handler]) {
    $nha_cmd->registerSubCommand($verbName, function (Message $message, array $args) use ($replyToMessage, $handler): void {
        $replyToMessage($message, $handler($args));
    }, ['description' => "Queue the `{$verbName}` action.", 'usage' => implode(' ', $argHints)]);
}

$nha_cmd->registerSubCommand('think', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $replyToMessage($message, $commands->think(isset($args[0]) ? (int) $args[0] : null));
}, ['description' => 'Ask the LLM what to do next and queue it.', 'usage' => '[agent_id]']);

$nha_cmd->registerSubCommand('autoplay', function (Message $message, array $args) use ($commands, $replyToMessage): void {
    $enabled = match (strtolower($args[0] ?? '')) {
        'on', 'start', '1', 'true' => true,
        'off', 'stop', '0', 'false' => false,
        default => null,
    };
    $replyToMessage($message, $commands->autoplay($enabled));
}, ['description' => 'Turn the autonomous LLM play loop on/off (or show status).', 'usage' => '[on|off]']);

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

$registerSlashCommands = function (NHA $nha) use ($commands, $state, $text, $replyToInteraction, $flattenOptions): void {
    $nha->application->commands->freshen()->then(function (GlobalCommandRepository $existing) use ($nha, $commands, $state, $text, $replyToInteraction, $flattenOptions): void {
        /**
         * Builds a slash-command option. `$constraints` narrows what Discord
         * will accept before the interaction ever reaches the bot:
         *   - `choices`  list (value = label) or [label => value] map, ≤25
         *   - `min`/`max` value bounds for INTEGER/NUMBER, length bounds for STRING
         */
        $opt = function (int $type, string $name, string $description, bool $required = false, array $constraints = []) use ($nha): Option {
            /** @var Option $option */
            $option = $nha->getFactory()->part(Option::class);
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
                $option->addChoice($nha->getFactory()->part(Choice::class, [
                    'name' => is_int($label) ? (string) $value : $label,
                    'value' => $value,
                ]));
            }

            return $option;
        };

        $sub = function (string $name, string $description, array $options = []) use ($opt): Option {
            $subOption = $opt(Option::SUB_COMMAND, $name, $description);
            foreach ($options as $o) {
                $subOption->addOption($o);
            }

            return $subOption;
        };

        // Constrained value sets the server expects verbatim.
        $WEAPONS = ['kinetic_gun', 'energy_weapon'];
        $MEDICINES = ['salve', 'stimpack', 'medkit', 'antidote'];
        $DESTINATIONS = ['deimos', 'phobos', 'mars', 'venus', 'earth'];
        // `board` choices: the readable boards minus the 3 with dedicated paths
        // (healthz is a health check; agents/agent are covered by /nha agent),
        // capped at Discord's 25-choice limit.
        $BOARDS = array_slice(array_values(array_diff(Commands::BOARDS, ['healthz', 'agents', 'agent'])), 0, 25);

        $agentIdOpt = fn() => $opt(Option::INTEGER, 'agent_id', 'Agent id (defaults to your registered agent).', false, ['min' => 1]);

        // "Whose agent?" selector for the per-user commands: omit for your own,
        // `bot` for the bot's default agent, or a numeric agent id.
        $agentOpt = fn() => $opt(Option::STRING, 'agent', 'Whose agent: omit for yours, "bot", or an agent id.', false, ['min' => 1]);

        // Resolves the acting agent for a per-user interaction from its optional
        // `agent` option, defaulting to the caller's own linked agent.
        $actorFor = fn(Interaction $interaction, array $args): AgentContext
            => $commands->actor((string) $interaction->user->id, $args['agent'] ?? null);

        $subCommands = [
            $sub('register', 'Register a new agent (becomes the default).', [
                $opt(Option::STRING, 'name', 'Agent name (1-24 characters).', false, ['min' => 1, 'max' => 24]),
                $opt(Option::INTEGER, 'metal', 'Starting metal.', false, ['min' => 0]),
                $opt(Option::INTEGER, 'credits', 'Starting credits.', false, ['min' => 0]),
            ]),
            $sub('observe', 'Observe the world from your agent\'s perspective.', [$agentIdOpt()]),
            $sub('act', 'Send any raw verb + JSON args intent.', [
                $opt(Option::STRING, 'verb', 'Verb to perform, e.g. attack, trade, contract.', true, ['min' => 1]),
                $opt(Option::STRING, 'args', 'JSON object of args, e.g. {"dx":1,"dy":0}.'),
                $agentIdOpt(),
            ]),
            $sub('move', 'Move by (dx, dy).', [
                $opt(Option::INTEGER, 'dx', 'Delta X.', true),
                $opt(Option::INTEGER, 'dy', 'Delta Y.', true),
                $agentIdOpt(),
            ]),
            $sub('mine', 'Mine nearby minerals.', [$opt(Option::INTEGER, 'n', 'Amount to mine (>=1).', false, ['min' => 1]), $agentIdOpt()]),
            $sub('chop', 'Chop nearby trees.', [$opt(Option::INTEGER, 'n', 'Amount to chop (>=1).', false, ['min' => 1]), $agentIdOpt()]),
            $sub('gather', 'Forage the nearest plant.', [$opt(Option::INTEGER, 'n', 'Amount to gather (>=1).', false, ['min' => 1]), $agentIdOpt()]),
            $sub('say', 'Say something in the world chat.', [$opt(Option::STRING, 'text', 'Message text.', true, ['min' => 1, 'max' => 280]), $agentIdOpt()]),
            $sub('tell', 'Privately tell another agent something.', [
                $opt(Option::INTEGER, 'to', 'Target agent id.', true, ['min' => 1]),
                $opt(Option::STRING, 'text', 'Message text.', true, ['min' => 1, 'max' => 280]),
                $agentIdOpt(),
            ]),
            $sub('world', 'Show the current world state.'),
            $sub('market', 'Show the agent market order book.'),
            $sub('depot', 'Show the fixed depot buy/sell prices.'),
            $sub('rules', 'Show the crafting rules codex.'),
            $sub('agent', 'Look up any agent\'s public info.', [$opt(Option::INTEGER, 'agent_id', 'Agent id to look up.', true, ['min' => 1])]),
            $sub('read', 'Read any world board.', [
                $opt(Option::STRING, 'board', 'World board to read.', true, ['choices' => $BOARDS]),
                $opt(Option::STRING, 'arg', 'Board argument: agent id, body name, or resource.'),
                $opt(Option::INTEGER, 'x', 'X (for the deposits board).', false, ['min' => 0]),
                $opt(Option::INTEGER, 'y', 'Y (for the deposits board).', false, ['min' => 0]),
                $opt(Option::INTEGER, 'limit', 'Row limit, where the board supports it.', false, ['min' => 1]),
            ]),
            $sub('intent', 'Check whether a queued intent applied or was rejected.', [
                $opt(Option::INTEGER, 'id', 'The queued_intent id from an action confirmation.', true, ['min' => 1]),
            ]),
            $sub('sell', 'Sell a resource to the depot.', [
                $opt(Option::STRING, 'resource', 'Resource to sell.', true, ['min' => 1]),
                $opt(Option::INTEGER, 'n', 'Amount (default 1).', false, ['min' => 1]),
                $agentIdOpt(),
            ]),
            $sub('buy', 'Buy a resource from the depot.', [
                $opt(Option::STRING, 'resource', 'Resource to buy.', true, ['min' => 1]),
                $opt(Option::INTEGER, 'n', 'Amount (default 1).', false, ['min' => 1]),
                $agentIdOpt(),
            ]),
            $sub('heal', 'Apply a medicine to yourself or an ally.', [
                $opt(Option::INTEGER, 'target', 'Ally to heal (omit to heal yourself).', false, ['min' => 1]),
                $opt(Option::STRING, 'item', 'Which medicine (engine picks one if omitted).', false, ['choices' => $MEDICINES]),
                $agentIdOpt(),
            ]),
            $sub('attack', 'Fire a ranged weapon at a target.', [
                $opt(Option::INTEGER, 'target', 'Target agent id.', true, ['min' => 1]),
                $opt(Option::STRING, 'weapon', 'Which weapon (engine picks one if omitted).', false, ['choices' => $WEAPONS]),
                $agentIdOpt(),
            ]),
            $sub('deploy', 'Send a finalized vehicle off to roam and mine.', [$agentIdOpt()]),
            $sub('finalize', 'Assemble your loose parts into one vehicle.', [
                $opt(Option::STRING, 'name', 'Name for the vehicle (optional).', false, ['min' => 1, 'max' => 24]),
                $agentIdOpt(),
            ]),
            $sub('think', 'Ask the LLM what to do next and queue it.', [$agentIdOpt()]),
            $sub('autoplay', 'Turn the autonomous LLM play loop on/off.', [$opt(Option::STRING, 'state', 'on or off (omit to show status).', false, ['choices' => ['on', 'off']])]),
        ];

        $dispatch = function (string $sub, array $a, ?string $userId = null) use ($commands): PromiseInterface {
            return match ($sub) {
                // Pass the invoking user's id so the agent is linked to them and
                // named `user-<id>` — never a bare `user-` (see Commands::register).
                'register' => $commands->register($a['name'] ?? null, $a['metal'] ?? 40, $a['credits'] ?? 150, $userId),
                'observe' => $commands->observe($a['agent_id'] ?? null),
                'act' => $commands->act($a['agent_id'] ?? null, $a['verb'], $a['args'] ?? null),
                'move' => $commands->move($a['agent_id'] ?? null, (int) $a['dx'], (int) $a['dy']),
                'mine' => $commands->mine($a['agent_id'] ?? null, $a['n'] ?? null),
                'chop' => $commands->chop($a['agent_id'] ?? null, $a['n'] ?? null),
                'gather' => $commands->gather($a['agent_id'] ?? null, $a['n'] ?? null),
                'say' => $commands->say($a['agent_id'] ?? null, $a['text']),
                'tell' => $commands->tell($a['agent_id'] ?? null, (int) $a['to'], $a['text']),
                'world' => $commands->world(),
                'market' => $commands->market(),
                'depot' => $commands->depot(),
                'rules' => $commands->rules(),
                'agent' => $commands->agentInfo((int) $a['agent_id']),
                'read' => $commands->board((string) ($a['board'] ?? ''), [
                    'id' => $a['arg'] ?? null, 'body' => $a['arg'] ?? null, 'resource' => $a['arg'] ?? null,
                    'x' => $a['x'] ?? null, 'y' => $a['y'] ?? null, 'limit' => $a['limit'] ?? null,
                ]),
                'intent' => $commands->intentStatus((string) ($a['id'] ?? '')),
                'sell' => $commands->sell($a['agent_id'] ?? null, (string) ($a['resource'] ?? ''), $a['n'] ?? null),
                'buy' => $commands->buy($a['agent_id'] ?? null, (string) ($a['resource'] ?? ''), $a['n'] ?? null),
                'heal' => $commands->heal($a['agent_id'] ?? null, isset($a['target']) ? (int) $a['target'] : null, $a['item'] ?? null),
                'attack' => $commands->attack($a['agent_id'] ?? null, (int) ($a['target'] ?? 0), $a['weapon'] ?? null),
                'deploy' => $commands->deploy($a['agent_id'] ?? null),
                'finalize' => $commands->finalize($a['agent_id'] ?? null, $a['name'] ?? null),
                'think' => $commands->think($a['agent_id'] ?? null),
                'autoplay' => $commands->autoplay(match (strtolower($a['state'] ?? '')) {
                    'on' => true,
                    'off' => false,
                    default => null,
                }),
                default => \React\Promise\reject(new \InvalidArgumentException("Unknown sub-command `{$sub}`.")),
            };
        };

        $nha->listenCommand('nha', function (Interaction $interaction) use ($replyToInteraction, $flattenOptions, $dispatch): PromiseInterface {
            $chosen = $interaction->data->options->first();
            $args = $flattenOptions($chosen->options ?? []);

            return $replyToInteraction($interaction, $dispatch($chosen->name, $args, (string) $interaction->user->id));
        });

        /**
         * Registers a global chat command. Creates it when missing, and PATCHes
         * it when its definition (name + description + options, including every
         * choice/min/max) has changed since the last successful registration —
         * tracked by a signature in `var/state.json` so an unchanged boot makes
         * zero (rate-limited) writes. Every attempt and outcome is logged.
         */
        $signatures = $state->getCommandSignatures();
        $createCommand = function (string $name, string $description, array $options = []) use ($nha, $state, $existing, $signatures): void {
            $builder = CommandBuilder::new()->setName($name)->setType(Command::CHAT_INPUT)->setDescription($description);
            foreach ($options as $option) {
                $builder->addOption($option);
            }

            $definition = $builder->jsonSerialize();
            $hash = sha1(json_encode($definition));
            $current = $existing->get('name', $name);

            if ($current !== null && ($signatures[$name] ?? null) === $hash) {
                return; // already registered with this exact definition
            }

            $nha->logger->debug('[GLOBAL APPLICATION COMMAND] ' . ($current === null ? 'Creating' : 'Updating') . " `{$name}` command...");

            if ($current !== null) {
                $current->fill($definition);
                $save = $current->save("{$name} definition update");
            } else {
                $save = $builder->create($existing)->save("{$name} initial creation");
            }

            $save->then(
                function () use ($nha, $state, $name, $hash, $current): void {
                    $state->setCommandSignature($name, $hash);
                    $nha->logger->info('[GLOBAL APPLICATION COMMAND] ' . ($current === null ? 'Created' : 'Updated') . " `{$name}` command.");
                },
                fn(\Throwable $e) => $nha->logger->error("[GLOBAL APPLICATION COMMAND] Failed to register `{$name}` command: {$e->getMessage()}"),
            );
        };

        $createCommand('nha', 'Control your NHA (https://nha.recluse.lol) agent.', $subCommands);

        $nha->listenCommand('start', function (Interaction $interaction) use ($commands, $nha, $text): PromiseInterface {
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $commands->start((string) $interaction->user->id),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('login', function (Interaction $interaction) use ($commands, $nha, $text): PromiseInterface {
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $commands->login((string) $interaction->user->id),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('help', function (Interaction $interaction) use ($commands, $nha, $text, $flattenOptions): PromiseInterface {
            $args = $flattenOptions($interaction->data->options ?? []);
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $commands->help($args['category'] ?? null),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('observe', function (Interaction $interaction) use ($commands, $actorFor, $nha, $text, $flattenOptions): PromiseInterface {
            $args = $flattenOptions($interaction->data->options ?? []);
            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $commands->observe($actorFor($interaction, $args)),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $nha->listenCommand('move', function (Interaction $interaction) use ($commands, $actorFor, $nha, $text, $flattenOptions): PromiseInterface {
            $args = $flattenOptions($interaction->data->options ?? []);

            return $interaction->acknowledgeWithResponse(true)->then(
                fn() => $commands->move($actorFor($interaction, $args), (int) $args['dx'], (int) $args['dy']),
            )->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
            );
        });

        $countOpt = fn(string $desc) => $opt(Option::INTEGER, 'n', $desc, false, ['min' => 1]);
        $idOpt = fn(string $name, string $desc, bool $required = true) => $opt(Option::INTEGER, $name, $desc, $required, ['min' => 1]);
        $msgOpt = fn(string $desc) => $opt(Option::STRING, 'text', $desc, true, ['min' => 1, 'max' => 280]);

        $playerActionCommands = [
            'mine' => [[ $countOpt('Amount to mine (>=1).') ], fn(array $args) => ['mine', ['n' => (int) ($args['n'] ?? 1)]]],
            'chop' => [[ $countOpt('Amount to chop (>=1).') ], fn(array $args) => ['chop', ['n' => (int) ($args['n'] ?? 1)]]],
            'gather' => [[ $countOpt('Amount to gather (>=1).') ], fn(array $args) => ['gather', ['n' => (int) ($args['n'] ?? 1)]]],
            'plant' => [[], fn(array $args) => ['plant', []]],
            'ride' => [[], fn(array $args) => ['ride', []]],
            'launch' => [[], fn(array $args) => ['launch', []]],
            'land' => [[], fn(array $args) => ['land', []]],
            'dock' => [[], fn(array $args) => ['dock', []]],
            'attune' => [[], fn(array $args) => ['attune', []]],
            'say' => [[ $msgOpt('World chat message.') ], fn(array $args) => ['say', ['text' => $args['text']]]],
            'tell' => [[
                $idOpt('to', 'Target agent id.'),
                $msgOpt('Private message.'),
            ], fn(array $args) => ['tell', ['to' => (int) $args['to'], 'text' => $args['text']]]],

            // Space & flight.
            'deploy' => [[], fn(array $args) => ['deploy', []]],
            'finalize' => [[ $opt(Option::STRING, 'name', 'Vehicle name (optional).', false, ['min' => 1, 'max' => 24]) ], fn(array $args) => ['finalize', array_filter(['name' => $args['name'] ?? null], fn($v) => null !== $v)]],
            'arm' => [[], fn(array $args) => ['arm', []]],
            'land_moon' => [[], fn(array $args) => ['land_moon', []]],
            'land_body' => [[], fn(array $args) => ['land_body', []]],
            'distress' => [[], fn(array $args) => ['distress', []]],
            'depart' => [[ $opt(Option::STRING, 'dest', 'Destination body.', true, ['choices' => $DESTINATIONS]) ], fn(array $args) => ['depart', ['dest' => $args['dest']]]],

            // Economy.
            'sell' => [[ $opt(Option::STRING, 'resource', 'Resource to sell.', true, ['min' => 1]), $countOpt('Amount (default 1).') ], fn(array $args) => ['sell', ['resource' => $args['resource'], 'n' => (int) ($args['n'] ?? 1)]]],
            'buy' => [[ $opt(Option::STRING, 'resource', 'Resource to buy.', true, ['min' => 1]), $countOpt('Amount (default 1).') ], fn(array $args) => ['buy', ['resource' => $args['resource'], 'n' => (int) ($args['n'] ?? 1)]]],

            // Medicine & combat.
            'heal' => [[ $idOpt('target', 'Ally to heal (omit for self).', false), $opt(Option::STRING, 'item', 'Which medicine (engine picks one if omitted).', false, ['choices' => $MEDICINES]) ], fn(array $args) => ['heal', array_filter(['target' => isset($args['target']) ? (int) $args['target'] : null, 'item' => $args['item'] ?? null], fn($v) => null !== $v)]],
            'attack' => [[ $idOpt('target', 'Target agent id.'), $opt(Option::STRING, 'weapon', 'Which weapon (engine picks one if omitted).', false, ['choices' => $WEAPONS]) ], fn(array $args) => ['attack', array_filter(['target' => (int) $args['target'], 'weapon' => $args['weapon'] ?? null], fn($v) => null !== $v)]],
            'steal' => [[ $idOpt('from', 'Adjacent agent id.'), $opt(Option::STRING, 'resource', 'Resource to lift.', true, ['min' => 1]), $countOpt('Amount (default 1).') ], fn(array $args) => ['steal', ['from' => (int) $args['from'], 'resource' => $args['resource'], 'n' => (int) ($args['n'] ?? 1)]]],
            'collect' => [[ $opt(Option::STRING, 'loot', 'Loot pile id.', true, ['min' => 1]) ], fn(array $args) => ['collect', ['loot' => $args['loot']]]],

            // Diplomacy.
            'ally' => [[ $idOpt('to', 'Agent id.') ], fn(array $args) => ['ally', ['to' => (int) $args['to']]]],
            'accept_ally' => [[ $idOpt('to', 'Agent id.') ], fn(array $args) => ['accept_ally', ['to' => (int) $args['to']]]],
            'unally' => [[ $idOpt('to', 'Agent id.') ], fn(array $args) => ['unally', ['to' => (int) $args['to']]]],
            'declare_war' => [[ $idOpt('to', 'Agent id.') ], fn(array $args) => ['declare_war', ['to' => (int) $args['to']]]],
            'make_peace' => [[ $idOpt('to', 'Agent id.') ], fn(array $args) => ['make_peace', ['to' => (int) $args['to']]]],
        ];

        foreach ($playerActionCommands as $name => [$options, $toIntent]) {
            $nha->listenCommand($name, function (Interaction $interaction) use ($commands, $actorFor, $nha, $text, $flattenOptions, $toIntent): PromiseInterface {
                $args = $flattenOptions($interaction->data->options ?? []);
                [$verb, $intentArgs] = $toIntent($args);

                return $interaction->acknowledgeWithResponse(true)->then(
                    fn() => $commands->queueVerb($actorFor($interaction, $args), $verb, $intentArgs),
                )->then(
                    fn($builder) => $interaction->updateOriginalResponse($builder),
                    fn(\Throwable $e) => $interaction->updateOriginalResponse($nha::createBuilder()->addComponent($text("❌ {$e->getMessage()}"))),
                );
            });
        }

        $userSlashCommands = [
            'help' => [[
                $opt(Option::STRING, 'category', 'Topic (omit for the general guide).', false, ['choices' => array_keys(Commands::HELP)]),
            ], 'How to play NHA — a categorised guide.'],
            'start' => [[], 'Open your private NHA control panel.'],
            'login' => [[], 'Create or reopen your personal NHA agent.'],
            'observe' => [[$agentOpt()], 'Observe your (or, with `agent`, another) NHA agent.'],
            'move' => [[
                $opt(Option::INTEGER, 'dx', 'Horizontal movement delta.', true),
                $opt(Option::INTEGER, 'dy', 'Vertical movement delta.', true),
                $agentOpt(),
            ], 'Queue movement for your (or, with `agent`, another) NHA agent.'],
        ];

        // Every player action also takes the `agent` selector; omitted = your own.
        foreach ($playerActionCommands as $name => [$options]) {
            $userSlashCommands[$name] = [[...$options, $agentOpt()], "Queue {$name} for your NHA agent (add `agent: bot` to run it as the bot)."];
        }

        foreach ($userSlashCommands as $name => [$options, $description]) {
            $createCommand($name, $description, $options);
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
    // Alerts (`getThreats()`) linger in the observation for many ticks after a
    // single hit, so track the newest tick already forwarded and only relay a
    // GENUINELY new threat — otherwise one old alert re-posts the whole
    // dashboard every poll.
    $seenThreatTick = 0;

    Loop::get()->addPeriodicTimer($poll_interval, function () use ($nha, $state, $channel_id, $text, &$seenMessageCount, &$seenThreatTick): void {
        $agent_id = $state->getDefaultAgent();
        if (! $agent_id) {
            return;
        }

        $nha->observe($agent_id)->then(function ($obs) use ($nha, $channel_id, $text, &$seenMessageCount, &$seenThreatTick): void {
            $messages = $obs->getMessages();
            $new = array_slice($messages, $seenMessageCount);
            $seenMessageCount = count($messages);

            $newThreat = false;
            foreach ($obs->getThreats() as $threat) {
                $threatTick = (int) (((array) $threat)['tick'] ?? 0);
                if ($threatTick > $seenThreatTick) {
                    $seenThreatTick = $threatTick;
                    $newThreat = true;
                }
            }

            if (! $new && ! $newThreat) {
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

// -----------------------------------------------------------------------
// Autonomous LLM play loop: while `!nha autoplay on`, periodically ask the
// brain for the default agent's next move and queue it. Overlapping runs are
// skipped ($autoplay_busy) since an LLM reply is slower than the interval.
// -----------------------------------------------------------------------

if ($autoPlayer) {
    // Autoplay runs by default whenever the LLM brain is configured. Boot paused
    // with NHA_AUTOPLAY=0, and toggle at runtime with `/nha autoplay on|off`
    // (the choice persists in var/state.json).
    if (! in_array(getenv('NHA_AUTOPLAY'), ['0', 'false'], true)) {
        $state->setAutoplay(true);
    }

    $autoplay_interval = (float) (getenv('NHA_AUTOPLAY_INTERVAL') ?: 15);
    $autoplay_busy = false;

    // A persistent fault — brain host unreachable, API down — repeats on every
    // tick. Log the first occurrence, then the same message at most once every
    // five minutes, so a sustained outage does not bury the rest of the log.
    $autoplay_warn_seen = ['msg' => '', 'at' => 0];
    $autoplay_warn = function (string $msg) use ($nha, &$autoplay_warn_seen): void {
        $now = time();
        if ($msg === $autoplay_warn_seen['msg'] && $now - $autoplay_warn_seen['at'] < 300) {
            return;
        }
        $autoplay_warn_seen = ['msg' => $msg, 'at' => $now];
        $nha->logger->warning("[autoplay] {$msg}");
    };

    Loop::get()->addPeriodicTimer($autoplay_interval, function () use ($nha, $state, $autoPlayer, $channel_id, $autoplay_interval, $autoplay_warn, &$autoplay_busy): void {
        if ($autoplay_busy || ! $state->isAutoplayEnabled()) {
            return;
        }

        $agent_id = $state->getDefaultAgent();
        if (! $agent_id) {
            return;
        }

        $autoplay_busy = true;
        $done = function () use (&$autoplay_busy): void {
            $autoplay_busy = false;
        };

        // A single bad turn — network blip, model timeout, malformed reply —
        // must never take the loop down; catch everything and let $done() reset
        // the busy flag so the next tick tries again.
        try {
            $autoPlayer->step($agent_id, (string) ($state->getDefaultAgentToken() ?? ''), 'bot.php:' . getmypid(), (int) $autoplay_interval)->then(
                function (string $line) use ($nha, $channel_id, $done): void {
                    $nha->logger->info("[autoplay] {$line}");
                    if ($channel_id) {
                        $nha->getChannel($channel_id)?->sendMessage(
                            NHA::createBuilder()->addComponent(Container::new()->addComponents([TextDisplay::new($line)])),
                        );
                    }
                    $done();
                },
                function (\Throwable $e) use ($autoplay_warn, $done): void {
                    $autoplay_warn($e->getMessage());
                    $done();
                },
            );
        } catch (\Throwable $e) {
            $autoplay_warn($e->getMessage());
            $done();
        }
    });
}

$nha->run();
