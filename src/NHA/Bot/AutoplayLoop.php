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

use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use NHA\Brain\AutoPlayer;
use NHA\NHA;
use NHA\StateStore;
use React\EventLoop\Loop;

/**
 * The autonomous LLM play loop: while `isAutoplayEnabled()`, every
 * `$interval` seconds ask {@see AutoPlayer::step()} for the default agent's
 * next move and queue it, posting the play-by-play ("thinking dialogue") to
 * `$brainChannelId`.
 *
 * Overlapping runs are skipped (an LLM reply is slower than the interval), a
 * single bad turn never takes the loop down, and a persistent fault (brain
 * host unreachable, API down) is logged once then at most once every 5 minutes.
 *
 * @since 3.1.27
 */
final class AutoplayLoop
{
    private bool $busy = false;

    /** @var array{msg: string, at: int} */
    private array $lastWarn = ['msg' => '', 'at' => 0];

    public function __construct(
        private readonly NHA $nha,
        private readonly StateStore $state,
        private readonly AutoPlayer $autoPlayer,
        private readonly ?string $brainChannelId,
        private readonly float $interval,
    ) {}

    public function start(): void
    {
        Loop::get()->addPeriodicTimer($this->interval, function (): void {
            if ($this->busy || ! $this->state->isAutoplayEnabled()) {
                return;
            }

            $agentId = $this->state->getDefaultAgent();
            if (! $agentId) {
                return;
            }

            $this->busy = true;
            $done = function (): void {
                $this->busy = false;
            };

            try {
                $this->autoPlayer->step(
                    $agentId,
                    (string) ($this->state->getDefaultAgentToken() ?? ''),
                    'bot.php:' . getmypid(),
                    (int) $this->interval,
                )->then(
                    function (string $line) use ($done): void {
                        $this->nha->logger->info("[autoplay] {$line}");
                        if ($this->brainChannelId) {
                            $this->nha->getChannel($this->brainChannelId)?->sendMessage(
                                NHA::createBuilder()->addComponent(Container::new()->addComponents([TextDisplay::new($line)])),
                            );
                        }
                        $done();
                    },
                    function (\Throwable $e) use ($done): void {
                        $this->warn($e->getMessage());
                        $done();
                    },
                );
            } catch (\Throwable $e) {
                $this->warn($e->getMessage());
                $done();
            }
        });
    }

    /** Log a warning, de-duping an identical message to at most once per 5 minutes. */
    private function warn(string $msg): void
    {
        $now = time();
        if ($msg === $this->lastWarn['msg'] && $now - $this->lastWarn['at'] < 300) {
            return;
        }
        $this->lastWarn = ['msg' => $msg, 'at' => $now];
        $this->nha->logger->warning("[autoplay] {$msg}");
    }
}
