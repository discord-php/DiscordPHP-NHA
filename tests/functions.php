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

use NHA\NHA;
use Psr\Log\NullLogger;

const TIMEOUT = 10;

function wait(callable $callback, float $timeout = TIMEOUT, ?callable $timeoutFn = null)
{
    $nha = NHASingleton::get();

    $result = null;
    $finally = null;
    $timedOut = false;

    $nha->getLoop()->futureTick(function () use ($callback, $nha, &$result, &$finally) {
        $resolve = function ($x = null) use ($nha, &$result) {
            $result = $x;
            $nha->getLoop()->stop();
        };

        try {
            $finally = $callback($nha, $resolve);
        } catch (\Throwable $e) {
            $resolve($e);
        }
    });

    $timeout = $nha->getLoop()->addTimer($timeout, function () use ($nha, &$timedOut) {
        $timedOut = true;
        $nha->getLoop()->stop();
    });

    $nha->getLoop()->run();
    $nha->getLoop()->cancelTimer($timeout);

    if ($result instanceof Exception) {
        throw $result;
    }

    if (is_callable($finally)) {
        $finally();
    }

    if ($timedOut) {
        if ($timeoutFn != null) {
            $timeoutFn();
        } else {
            throw new \Exception('Timed out');
        }
    }

    return $result;
}

function getMockNha(): NHA
{
    return new NHA(['token' => '', 'logger' => new NullLogger()]);
}
