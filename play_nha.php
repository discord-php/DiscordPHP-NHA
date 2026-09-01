<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use NHA\NHA;
use NHA\StateStore;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

use function React\Async\await;

function loadEnvironment(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

function persistEnvironment(array $values): void
{
    $path = __DIR__ . '/.env';
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $remaining = array_fill_keys(array_keys($values), true);

    foreach ($lines as &$line) {
        foreach ($values as $name => $value) {
            if (str_starts_with($line, "{$name}=")) {
                $line = "{$name}={$value}";
                unset($remaining[$name]);
                break;
            }
        }
    }
    unset($line);

    foreach (array_keys($remaining) as $name) {
        $lines[] = "{$name}={$values[$name]}";
    }

    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

function observationSummary(int $agentId, array $observation): string
{
    $position = (array) ($observation['position'] ?? $observation['pos'] ?? []);
    $inventory = (array) ($observation['inventory'] ?? []);

    return json_encode([
        'agent_id' => $agentId,
        'tick' => $observation['tick'] ?? null,
        'position' => $position === [] ? null : $position,
        'hp' => $observation['hp'] ?? $observation['health'] ?? null,
        'inventory' => $inventory,
        'notices' => $observation['system_notices'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

loadEnvironment(__DIR__ . '/.env');

$logger = new Logger('NHA-SIM', [new StreamHandler('php://stdout', Level::Info)]);
$move = in_array('--move', $argv, true);

$nha = new NHA([
    'logger' => $logger,
    'token' => getenv('TOKEN') ?: '',
    'nha_token' => getenv('NHA_AGENT_TOKEN') ?: '',
    'disableVoiceClient' => true,
]);

echo "Starting NHA agent turn\n";

try {
    $agentId = getenv('NHA_AGENT_ID');
    $agentId = $agentId === false || $agentId === '' ? null : (int) $agentId;

    if ($agentId === null) {
        $legacyAgentId = (new StateStore(__DIR__ . '/var/play_nha_state.json'))->getDefaultAgent();
        if ($legacyAgentId !== null) {
            $agentId = $legacyAgentId;
            persistEnvironment(['NHA_AGENT_ID' => (string) $agentId]);
            echo "Migrated existing agent #{$agentId} to .env\n";
        }
    }

    if ($agentId === null) {
        $name = 'SimAgent' . random_int(100000, 999999);
        echo "Registering {$name}\n";
        $identity = await($nha->registerAgentIdentity($name, ['metal' => 40, 'credits' => 150]));
        $agentId = $identity['agent_id'];
        persistEnvironment([
            'NHA_AGENT_ID' => (string) $agentId,
            'NHA_AGENT_NAME' => $name,
            'NHA_AGENT_TOKEN' => $identity['token'],
        ]);
        echo "Registered agent #{$agentId}\n";
    } else {
        echo "Reusing agent #{$agentId}\n";
    }

    $observation = await($nha->observe($agentId));
    echo observationSummary($agentId, $observation->raw) . PHP_EOL;

    if ($move) {
        await($nha->move($agentId, 1, 1));
        echo "Queued move (1, 1) for agent #{$agentId}; it may apply on a later tick.\n";
    } else {
        echo "No action queued. Pass --move to queue one movement intent.\n";
    }

} catch (\Throwable $e) {
    fwrite(STDERR, "NHA agent turn failed: {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    $nha->close();
}

exit($exitCode ?? 0);
