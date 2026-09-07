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

/*
 * Standalone long-running LLM autoplay runner.
 *
 * Loops observe -> AgentBrain::decide -> submit intent for one agent, forever,
 * until the user stops it (Ctrl+C / SIGTERM). This is the headless twin of the
 * `!nha autoplay on` loop in bot.php — it uses the same NHA\Brain\AutoPlayer, so
 * the behaviour is identical, but it needs no Discord connection.
 *
 *   php autoplay.php                 # the default agent from var/state.json
 *   php autoplay.php <agent_id>      # a specific agent id
 *
 * The agent's action token comes from NHA_TOKEN or, failing that, var/state.json.
 *
 * Env (.env or the environment):
 *   OLLAMA_URL            required — bare origin (native /api/chat) or …/v1 (OpenAI-compatible)
 *   OLLAMA_MODEL          model tag on that server            (default: gemma3:27b)
 *   OLLAMA_NUM_CTX        context window, native mode         (default: 32768)
 *   OLLAMA_TIMEOUT        per-request seconds                 (default: 120)
 *   OLLAMA_THINK          native mode: 0 disables reasoning; unset = model default
 *   NHA_TOKEN             agent action token; falls back to var/state.json
 *   NHA_AUTOPLAY_INTERVAL seconds between turns               (default: 15)
 *   NHA_AUTOPLAY_DRY      1 = decide and print, do NOT submit the intent
 *
 * The loop already shrugs off per-turn failures; to also survive a hard crash of
 * the PHP process, run it under a supervisor:
 *   POSIX:   while true; do php autoplay.php; echo "restarting in 3s"; sleep 3; done
 *   Windows: run-autoplay.bat   (or: `:loop` / `php autoplay.php` / `timeout /t 3` / `goto loop`)
 */

namespace NHA;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use NHA\Brain\AgentBrain;
use NHA\Brain\AutoPlayer;
use NHA\Brain\OllamaClient;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

use function React\Promise\set_rejection_handler;

require __DIR__ . '/vendor/autoload.php';

// --- .env (only fills vars not already in the environment) -------------------
$envPath = __DIR__ . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || ! str_contains($l, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $l, 2));
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

$url = getenv('OLLAMA_URL')
    ?: exit("Set OLLAMA_URL (e.g. http://192.168.0.91:11434/v1 or a bare origin for the native API).\n");
$model = getenv('OLLAMA_MODEL') ?: 'gemma3:27b';
$numCtx = (int) (getenv('OLLAMA_NUM_CTX') ?: 32768);
$reqTimeout = (float) (getenv('OLLAMA_TIMEOUT') ?: 120);
$think = match (getenv('OLLAMA_THINK')) {
    '1', 'true' => true,
    '0', 'false' => false,
    default => null,
};
$interval = max(1.0, (float) (getenv('NHA_AUTOPLAY_INTERVAL') ?: 15));
$dryRun = in_array(getenv('NHA_AUTOPLAY_DRY'), ['1', 'true'], true);

$loop = Loop::get();

$logger = new Logger('autoplay', [
    (new StreamHandler('php://stdout', Level::Debug))
        ->setFormatter(new LineFormatter(null, 'H:i:s', true, true, true)),
]);

$state = new StateStore(__DIR__ . '/var/state.json');
$agentId = isset($argv[1]) ? (int) $argv[1] : (int) ($state->getDefaultAgent() ?? 0);
if ($agentId <= 0) {
    exit("No agent id. Pass one (php autoplay.php <id>) or set a default agent in var/state.json.\n");
}
// var/state.json is the bot's source of truth (written by /nha register|login);
// prefer it, and treat NHA_TOKEN only as an override for when it has none.
$token = (string) ($state->getDefaultAgentToken() ?? (getenv('NHA_TOKEN') ?: ''));
$tokenSource = $state->getDefaultAgentToken() !== null ? 'state.json' : (getenv('NHA_TOKEN') ? 'NHA_TOKEN' : 'none');
if ($token === '' && ! $dryRun) {
    $logger->warning('No agent token (var/state.json / NHA_TOKEN) — intent submission will fail. Use NHA_AUTOPLAY_DRY=1 to test.');
}

// NHA extends the Discord client and its constructor unconditionally opens a
// Discord gateway connection, which 4004s with the empty token used here. That
// is harmless for autoplay (which only needs the NHA REST client), but noisy:
// give the Discord half a silent logger and swallow its auth-failure rejection.
set_rejection_handler(static function (\Throwable $e) use ($logger): void {
    if ($e instanceof \Discord\Http\Exceptions\InvalidTokenException) {
        return;
    }
    $logger->warning('unhandled rejection: ' . $e->getMessage());
});

$nha = new NHA([
    'nha_token' => $token,
    'token' => '',
    'logger' => new NullLogger(),
    'loop' => $loop,
    'disableVoiceClient' => true,
]);
$brain = new AgentBrain(new OllamaClient($url, $model, null, $reqTimeout, $numCtx, $loop, $think));
$player = new AutoPlayer($nha, $brain, $state);

$logger->info(sprintf(
    'autoplay agent #%d  model=%s  every=%.0fs  think=%s  token=%s%s  ->  %s',
    $agentId,
    $model,
    $interval,
    var_export($think, true),
    $tokenSource . ($token !== '' ? ' …' . substr($token, -4) : ''),
    $dryRun ? '  [DRY RUN]' : '',
    $url,
));

$busy = false;
$stopping = false;
$leaseHolder = 'autoplay.php:' . getmypid();

$turn = function () use (&$busy, &$stopping, $player, $brain, $nha, $agentId, $token, $dryRun, $logger, $loop, $interval, $leaseHolder): void {
    if ($busy || $stopping) {
        return;
    }
    $busy = true;

    $done = function () use (&$busy, &$stopping, $loop): void {
        $busy = false;
        if ($stopping) {
            $loop->stop();
        }
    };

    try {
        $promise = $dryRun
            ? $nha->observe($agentId)->then(fn($obs) => $brain->decide($obs))->then(
                static fn(?array $d) => $d === null
                    ? "💤 #{$agentId}: brain chose to wait"
                    : "🧪 #{$agentId} would → {$d['verb']} " . json_encode($d['args'], JSON_UNESCAPED_SLASHES)
                        . ($d['reason'] !== '' ? "\n> {$d['reason']}" : ''),
            )
            : $player->step($agentId, $token, $leaseHolder, (int) $interval);

        $promise->then(
            static fn(string $line) => $logger->info($line),
            static fn(\Throwable $e) => $logger->warning("turn failed: {$e->getMessage()}"),
        )->finally($done);
    } catch (\Throwable $e) {
        $logger->warning("turn threw: {$e->getMessage()}");
        $done();
    }
};

// Graceful stop where the platform supports POSIX signals (Linux/macOS with
// ext-pcntl / ext-uv). On Windows, Ctrl+C hard-terminates the process, which is
// still a clean "user stopped it".
$installSignal = static function (int $signal) use ($loop, &$stopping, &$busy, $logger, $state, $leaseHolder): void {
    try {
        $loop->addSignal($signal, function () use ($loop, &$stopping, &$busy, $logger, $state, $leaseHolder): void {
            if ($stopping) {
                $loop->stop();

                return;
            }
            $stopping = true;
            // Hand the lease back now so bot.php's loop can pick up on its very
            // next tick instead of waiting out the TTL. Any in-flight turn has
            // already passed its acquire check and won't touch the lease again.
            $state->releaseAutoplayLease($leaseHolder);
            $logger->info($busy ? 'stop requested — finishing the current turn…' : 'stop requested');
            if (! $busy) {
                $loop->stop();
            }
        });
    } catch (\Throwable) {
        // Signals unsupported on this platform; Ctrl+C will just kill the process.
    }
};
$installSignal(defined('SIGINT') ? SIGINT : 2);
$installSignal(defined('SIGTERM') ? SIGTERM : 15);

$loop->futureTick($turn);                 // first turn now, not after one interval
$loop->addPeriodicTimer($interval, $turn);

$loop->run();

$logger->info('autoplay stopped.');
