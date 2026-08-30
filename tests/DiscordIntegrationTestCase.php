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

use Discord\Discord;
use Discord\Parts\Channel\Channel;

class DiscordIntegrationTestCase extends DiscordTestCase
{
    protected static ?Channel $channel = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! (getenv('DISCORD_TOKEN') ?: getenv('TOKEN'))) {
            static::markTestSkipped('DISCORD_TOKEN or TOKEN is required for integration tests.');

            return;
        }

        try {
            $channel = waitForDiscord(function (Discord $discord, callable $resolve) {
                $resolve($discord->getChannel(getenv('TEST_CHANNEL')));
            });
        } catch (\Throwable $e) {
            static::markTestSkipped('Could not connect to Discord: ' . $e->getMessage());

            return;
        }

        if (! $channel instanceof Channel) {
            static::markTestSkipped('Channel not found. Ensure TEST_CHANNEL is configured correctly.');

            return;
        }

        self::$channel = $channel;
    }

    protected function channel(): Channel
    {
        return self::$channel;
    }
}
