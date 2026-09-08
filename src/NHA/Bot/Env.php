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

/**
 * `.env` loading for the `bot.php` / `autoplay.php` entry points.
 *
 * The base-directory walk-up that has to run BEFORE the Composer autoloader
 * stays inline in the entry scripts; this class covers everything after it.
 *
 * @since 3.1.27
 */
final class Env
{
    /**
     * Finds the `.env` file: beside `$baseDir`, else in the current working
     * directory. Returns `null` when neither exists.
     */
    public static function locate(string $baseDir): ?string
    {
        if (is_file($baseDir . '/.env')) {
            return $baseDir . '/.env';
        }
        if (is_file(getcwd() . '/.env')) {
            return getcwd() . '/.env';
        }

        return null;
    }

    /**
     * Loads `KEY=VALUE` lines from `$path` into the process environment via
     * `putenv()`. Blank lines and `#` comments are skipped, and a key already
     * present in `$_ENV` is left alone (a real environment variable wins).
     *
     * @throws \RuntimeException When the file does not exist.
     */
    public static function load(string $path): void
    {
        if (! is_file($path)) {
            throw new \RuntimeException("The .env file does not exist: {$path}");
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($name !== '' && ! array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
            }
        }
    }

    /** `getenv($key)` coerced to a non-empty string, or `null`. */
    public static function string(string $key): ?string
    {
        $v = getenv($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** `getenv($key)` as a float, or `$default` when unset/blank. */
    public static function float(string $key, float $default): float
    {
        $v = self::string($key);

        return $v === null ? $default : (float) $v;
    }

    /** `getenv($key)` as an int, or `$default` when unset/blank. */
    public static function int(string $key, int $default): int
    {
        $v = self::string($key);

        return $v === null ? $default : (int) $v;
    }

    /** True unless `getenv($key)` is one of the "off" spellings. */
    public static function flag(string $key, bool $default = true): bool
    {
        return match (strtolower((string) getenv($key))) {
            '0', 'false', 'off', 'no' => false,
            '1', 'true', 'on', 'yes' => true,
            default => $default,
        };
    }
}
