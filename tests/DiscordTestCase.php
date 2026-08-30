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

use PHPUnit\Framework\TestCase;

use function React\Promise\set_rejection_handler;

class DiscordTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        set_rejection_handler(function (\Throwable $e): void {});
    }
}
