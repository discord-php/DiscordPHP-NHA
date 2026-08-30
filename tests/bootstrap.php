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

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) > 1 && (
            ('"' === $value[0] && '"' === substr($value, -1))
            || ("'" === $value[0] && "'" === substr($value, -1))
        )) {
            $value = substr($value, 1, -1);
        }

        if ('' !== $key && false === getenv($key)) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

require __DIR__ . '/functions.php';
require __DIR__ . '/DiscordSingleton.php';
require __DIR__ . '/DiscordTestCase.php';
require __DIR__ . '/DiscordIntegrationTestCase.php';
