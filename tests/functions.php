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
use Discord\DiscordCommandClient;
use Psr\Log\NullLogger;

const TIMEOUT = 10;

function wait(callable $callback, float $timeout = TIMEOUT, ?callable $timeoutFn = null)
{
    $discord = DiscordSingleton::get();

    return waitWithDiscord($discord, $callback, $timeout, $timeoutFn);
}

function waitForDiscord(callable $callback, float $timeout = TIMEOUT, ?callable $timeoutFn = null)
{
    return waitWithDiscord(DiscordSingleton::getLive(), $callback, $timeout, $timeoutFn);
}

function waitWithDiscord(Discord $discord, callable $callback, float $timeout, ?callable $timeoutFn)
{
    $result = null;
    $finally = null;
    $timedOut = false;

    $discord->getLoop()->futureTick(function () use ($callback, $discord, &$result, &$finally): void {
        $resolve = function ($value = null) use ($discord, &$result): void {
            $result = $value;
            $discord->getLoop()->stop();
        };

        try {
            $finally = $callback($discord, $resolve);
        } catch (\Throwable $e) {
            $resolve($e);
        }
    });

    $timer = $discord->getLoop()->addTimer($timeout, function () use ($discord, &$timedOut): void {
        $timedOut = true;
        $discord->getLoop()->stop();
    });

    $discord->getLoop()->run();
    $discord->getLoop()->cancelTimer($timer);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    if (is_callable($finally)) {
        $finally();
    }

    if ($timedOut) {
        if (null !== $timeoutFn) {
            $timeoutFn();
        } else {
            throw new \RuntimeException('Timed out');
        }
    }

    return $result;
}

function getMockDiscord(): Discord
{
    return new Discord(['token' => '', 'logger' => new NullLogger()]);
}

function getMockDiscordCommandClient(): DiscordCommandClient
{
    return new DiscordCommandClient([
        'token' => '',
        'discordOptions' => ['logger' => new NullLogger()],
    ]);
}
