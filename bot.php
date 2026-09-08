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
use Discord\Parts\User\Activity;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use NHA\Bot\AutoplayLoop;
use NHA\Bot\ChannelRelay;
use NHA\Bot\ChatCommands;
use NHA\Bot\Env;
use NHA\Bot\Replies;
use NHA\Bot\SlashCommands;

use function React\Promise\set_rejection_handler;

/**
 * The project base directory. Works when run as `php bot.php` from the repo,
 * and when run as a phpacker/phpmicro binary (which lands nested under
 * `bin/build/<name>/<platform>/`) launched directly or from a shortcut, from
 * any working directory: it walks up from the real executable path, then from
 * the working directory, to the first ancestor that looks like the project
 * (has `vendor/autoload.php` or a `.env` beside it).
 *
 * This runs BEFORE the Composer autoloader, so it stays inline.
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

$autoloadPath = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php'
    : (is_file($baseDir . '/vendor/autoload.php') ? $baseDir . '/vendor/autoload.php' : null);
$autoloadPath
    ? require $autoloadPath
    : throw new \Exception('Composer autoloader not found. Run `composer install`, or keep the binary inside the project directory (searched up from "' . dirname(\Phar::running(false) ?: __FILE__) . '").');

// --- configuration ---------------------------------------------------------

$envPath = Env::locate($baseDir);
$envPath ? Env::load($envPath)
    : throw new \Exception('The .env file does not exist. Create one in the project directory (' . $baseDir . ').');

$errorChannelId = Env::string('ERROR_CHANNEL_ID');
$channelId = Env::string('NHA_CHANNEL_ID');
// Optional separate channel for the autoplay play-by-play ("thinking dialogue"),
// keeping the main channel for controls / inventory / the world dashboard.
$brainChannelId = Env::string('NHA_BRAIN_CHANNEL_ID') ?? $channelId;

$streamHandler = new StreamHandler('php://stdout', Level::Debug);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('NHA', [$streamHandler]);

$nha = new NHA([
    'logger' => $logger,
    'token' => getenv('TOKEN'),
    'prefix' => '!',
    'disableVoiceClient' => true,
    'nha_base_url' => Env::string('NHA_BASE_URL') ?? '',
]);

// Relay a fatal error / unhandled rejection to the error channel, if configured.
$reportFatalError = function (\Throwable $e) use ($nha, $errorChannelId, $logger): void {
    $logger->warning("Unhandled error: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]");

    if ($errorChannelId) {
        $nha->getChannel($errorChannelId)?->sendMessage(NHA::createBuilder()->addComponent(Container::new()->addComponents([
            TextDisplay::new("⚠️ **Unhandled error**: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]"),
        ])));
    }
};
set_rejection_handler($reportFatalError);
set_exception_handler($reportFatalError);

// --- state + optional LLM brain ------------------------------------------

$state = new StateStore($baseDir . '/var/state.json');
if ($token = $state->getDefaultAgentToken()) {
    $nha->setAgentToken($token);
}

// Only wired when OLLAMA_URL points at a running `ollama serve`. A bare origin
// (http://host:11434) uses Ollama's native API; a URL ending in /v1 uses the
// OpenAI-compatible one.
$autoPlayer = null;
if ($ollamaUrl = Env::string('OLLAMA_URL')) {
    $ollamaModel = Env::string('OLLAMA_MODEL') ?? 'gemma3:27b';
    $ollama = new Brain\OllamaClient(
        $ollamaUrl,
        $ollamaModel,
        null,
        Env::float('OLLAMA_TIMEOUT', 120),
        Env::int('OLLAMA_NUM_CTX', 32768),
        $nha->getLoop(),
        match (getenv('OLLAMA_THINK')) {
            '1', 'true' => true, '0', 'false' => false, default => null
        },
    );
    $autoPlayer = new Brain\AutoPlayer($nha, new Brain\AgentBrain($ollama), $state);
    $logger->info("LLM brain enabled: {$ollamaUrl} ({$ollamaModel})");
}

$commands = new Commands($nha, $state, $autoPlayer);
$replies = new Replies($nha);

// --- interfaces ---------------------------------------------------------

// Chat commands (the `!nha` prefix tree) can register immediately.
(new ChatCommands($nha, $commands, $replies))->register();

// Slash commands need both the gateway and the application ready.
$slash = new SlashCommands($nha, $commands, $state, $replies);
$ready = ['init' => false, 'application-init' => false];
$maybeStartSlash = function () use (&$ready, $nha, $slash): void {
    if (! $ready['init'] || ! $ready['application-init']) {
        return;
    }
    $slash->register();
    $nha->updatePresence(new Activity($nha, ['name' => 'nha.recluse.lol', 'type' => 0]));
};
$nha->once('init', function () use (&$ready, $maybeStartSlash): void {
    $ready['init'] = true;
    $maybeStartSlash();
});
$nha->once('application-init', function () use (&$ready, $maybeStartSlash): void {
    $ready['application-init'] = true;
    $maybeStartSlash();
});

// Two-way Discord ↔ world bridge for the default agent.
if ($channelId) {
    (new ChannelRelay($nha, $state, $channelId, Env::float('NHA_POLL_INTERVAL', 5)))->start();
}

// The autonomous LLM play loop (defaults ON when a brain is configured).
if ($autoPlayer) {
    if (Env::flag('NHA_AUTOPLAY')) {
        $state->setAutoplay(true);
    }
    (new AutoplayLoop($nha, $state, $autoPlayer, $brainChannelId, Env::float('NHA_AUTOPLAY_INTERVAL', 15)))->start();
}

$nha->run();
