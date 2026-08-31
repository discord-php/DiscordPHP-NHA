<?php

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

use NHA\NHA;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\EventLoop\Loop;

class NHASingleton
{
    private static $nha;

    /**
     * @return NHA
     */
    public static function get()
    {
        if (! self::$nha) {
            self::new_cache();
        }

        return self::$nha;
    }

    private static function new_cache()
    {
        $loop = Loop::get();

        $logger = new Logger('NHAPHP-UnitTests');
        $handler = new StreamHandler(fopen(__DIR__ . '/../phpunit.log', 'w'));
        $formatter = new LineFormatter(null, null, true, true);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        $nha = new NHA([
            'token' => getenv('NHA_TOKEN'),
            'loop' => $loop,
            'logger' => $logger,
        ]);

        $e = null;

        $timer = $nha->getLoop()->addTimer(10, function () use (&$e) {
            $e = new Exception('Timed out trying to connect to NHA.');
        });

        $nha->on('ready', function (NHA $nha) use ($timer) {
            $nha->getLoop()->cancelTimer($timer);
            $nha->getLoop()->stop();
        });

        self::$nha = $nha;

        $nha->run();

        if ($e !== null) {
            throw $e;
        }
    }

    private static function new()
    {
        $logger = new Logger('NHAPHP-UnitTests');
        $handler = new StreamHandler(fopen(__DIR__ . '/../phpunit.log', 'w'));
        $formatter = new LineFormatter(null, null, true, true);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        $nha = new NHA([
            'token' => getenv('NHA_TOKEN'),
            'logger' => $logger,
        ]);

        $e = null;

        $timer = $nha->getLoop()->addTimer(10, function () use (&$e) {
            $e = new Exception('Timed out trying to connect to NHA.');
        });

        $nha->on('ready', function (NHA $nha) use ($timer) {
            $nha->getLoop()->cancelTimer($timer);
            $nha->getLoop()->stop();
        });

        $nha->getLoop()->run();

        if ($e !== null) {
            throw $e;
        }

        self::$nha = $nha;
    }
}
