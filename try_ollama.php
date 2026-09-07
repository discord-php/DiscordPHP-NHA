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
 * Standalone smoke test for the LLM half of the autoplay pipeline.
 *
 *   php try_ollama.php                 # just ping the model
 *   php try_ollama.php <agent_id>      # + run a real observe -> brain.decide()
 *
 * Reads OLLAMA_URL / OLLAMA_MODEL / OLLAMA_NUM_CTX / OLLAMA_TIMEOUT from .env
 * (or the environment). OLLAMA_URL may be a bare origin (native /api/chat) or end
 * in /v1 for the OpenAI-compatible endpoint. Does NOT submit any intent.
 */

use NHA\Brain\AgentBrain;
use NHA\Brain\OllamaClient;
use NHA\NHA;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

use function React\Async\await;

require __DIR__ . '/vendor/autoload.php';

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

$url = getenv('OLLAMA_URL') ?: exit("Set OLLAMA_URL in .env (e.g. http://gemma-host:11434 or http://gemma-host:11434/v1)\n");
$model = getenv('OLLAMA_MODEL') ?: 'gemma3:27b';
$numCtx = (int) (getenv('OLLAMA_NUM_CTX') ?: 32768);
$timeout = (float) (getenv('OLLAMA_TIMEOUT') ?: 120);
$think = match (getenv('OLLAMA_THINK')) {
    '1', 'true' => true,
    '0', 'false' => false,
    default => null,
};

echo "→ {$url}  model={$model}  num_ctx={$numCtx}  timeout={$timeout}s  think=" . var_export($think, true) . "\n\n";

$loop = Loop::get();
$ollama = new OllamaClient($url, $model, null, $timeout, $numCtx, $loop, $think);

$t0 = microtime(true);
try {
    $reply = await($ollama->chat([
        ['role' => 'user', 'content' => 'Reply with exactly: {"verb":"wait","args":{},"reason":"ping"}'],
    ]));
    printf("ping OK in %.1fs\nreply: %s\n\n", microtime(true) - $t0, $reply);
} catch (\Throwable $e) {
    exit('ping FAILED: ' . $e->getMessage() . "\n");
}

$agentId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($agentId <= 0) {
    echo "OK. Pass an agent id to also test a real observe -> decide.\n";

    return;
}

// `observe` is a public NHA endpoint, but NHA extends the Discord client and its
// bootstrap fires an authenticated `applications/@me` fetch that 401s with the
// empty token used here. That rejection is unrelated to the LLM pipeline under
// test — swallow it so it doesn't bury the output in a stack trace.
\React\Promise\set_rejection_handler(static function (\Throwable $e): void {
    if (! $e instanceof \Discord\Http\Exceptions\InvalidTokenException) {
        fwrite(STDERR, 'unhandled rejection: ' . $e->getMessage() . "\n");
    }
});

$nha = new NHA(['nha_token' => getenv('NHA_TOKEN') ?: '', 'token' => '', 'logger' => new NullLogger(), 'loop' => $loop]);
$nha->emit('init', [$nha]);
$brain = new AgentBrain($ollama);

$t0 = microtime(true);
$observation = await($nha->observe($agentId));
echo "observed agent #{$agentId} at " . json_encode($observation->getPosition()) . " (tick " . $observation->get('tick') . ")\n";

$decision = await($brain->decide($observation));
printf("decided in %.1fs: %s\n", microtime(true) - $t0, json_encode($decision) ?: 'null (wait)');
echo "\n(no intent was submitted — use `!nha think` / `!nha autoplay on` in the bot for that)\n";
