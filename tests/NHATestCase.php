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
use PHPUnit\Framework\TestCase;

class NHATestCase extends TestCase
{
    protected static Channel $channel;

    public static function setUpBeforeClass(): void
    {
        /** @var Channel $channel */
        $channel = wait(function (Discord $discord, $resolve) {
            $channel = $discord->getChannel(getenv('TEST_CHANNEL'));
            $resolve($channel);
        });
        assert(self::$channel instanceof Channel, 'Channel not found. Please check your environment variables and ensure TEST_CHANNEL is set.');
        self::$channel = $channel;
    }

    protected function channel()
    {
        return self::$channel;
    }
}
