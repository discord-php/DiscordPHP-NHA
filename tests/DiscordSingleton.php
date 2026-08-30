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
use Discord\WebSockets\Intents;
use Psr\Log\NullLogger;

class DiscordSingleton
{
    protected static ?Discord $discord = null;
    protected static ?Discord $liveDiscord = null;

    public static function get(): Discord
    {
        if (null === self::$discord) {
            self::$discord = getMockDiscord();
        }

        return self::$discord;
    }

    public static function getLive(): Discord
    {
        if (null === self::$liveDiscord) {
            $token = getenv('DISCORD_TOKEN') ?: getenv('TOKEN');
            if (! $token) {
                throw new \RuntimeException('DISCORD_TOKEN or TOKEN is required for integration tests.');
            }

            self::$liveDiscord = self::connect($token);
        }

        return self::$liveDiscord;
    }

    protected static function connect(string $token): Discord
    {
        $discord = new Discord([
            'token' => $token,
            'logger' => new NullLogger(),
            'intents' => Intents::getDefaultIntents(),
            'useTransportCompression' => false,
            'usePayloadCompression' => false,
        ]);

        $error = null;
        $timer = $discord->getLoop()->addTimer(TIMEOUT, function () use ($discord, &$error): void {
            $error = new \RuntimeException('Timed out trying to connect to Discord.');
            $discord->getLoop()->stop();
        });

        $discord->on('ready', function (Discord $discord) use ($timer): void {
            $discord->getLoop()->cancelTimer($timer);
            $discord->getLoop()->stop();
        });

        $discord->run();

        if (null !== $error) {
            throw $error;
        }

        return $discord;
    }
}
