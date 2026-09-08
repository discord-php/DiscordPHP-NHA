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

use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use NHA\NHA;
use NHA\StateStore;
use React\EventLoop\Loop;

/**
 * Two-way bridge between the default agent and one Discord channel:
 *
 *  - every `$pollInterval` seconds, `observe` the agent and forward the world
 *    dashboard when there is a NEW world-chat message or a NEW threat (alerts
 *    linger for many ticks, so they are de-duplicated by their `tick`);
 *  - a plain message typed in that channel is relayed into the world as a
 *    `say` intent.
 *
 * @since 3.1.27
 */
final class ChannelRelay
{
    private int $seenMessageCount = 0;
    private int $seenThreatTick = 0;

    public function __construct(
        private readonly NHA $nha,
        private readonly StateStore $state,
        private readonly string $channelId,
        private readonly float $pollInterval,
    ) {}

    public function start(): void
    {
        Loop::get()->addPeriodicTimer($this->pollInterval, function (): void {
            $agentId = $this->state->getDefaultAgent();
            if (! $agentId) {
                return;
            }

            $this->nha->observe($agentId)->then(function ($obs): void {
                $messages = $obs->getMessages();
                $newMessage = array_slice($messages, $this->seenMessageCount) !== [];
                $this->seenMessageCount = count($messages);

                $newThreat = false;
                foreach ($obs->getThreats() as $threat) {
                    $threatTick = (int) (((array) $threat)['tick'] ?? 0);
                    if ($threatTick > $this->seenThreatTick) {
                        $this->seenThreatTick = $threatTick;
                        $newThreat = true;
                    }
                }

                if (! $newMessage && ! $newThreat) {
                    return;
                }

                $this->nha->getChannel($this->channelId)?->sendMessage(
                    NHA::createBuilder()->addComponent($obs->toContainer($this->nha)),
                );
            });
        });

        $this->nha->on(Event::MESSAGE_CREATE, function (Message $message): void {
            if ((string) $message->channel_id !== $this->channelId
                || $message->author->bot
                || str_starts_with($message->content, $this->nha->options['prefix'])
            ) {
                return;
            }

            if ($agentId = $this->state->getDefaultAgent()) {
                $this->nha->say($agentId, $message->content);
            }
        });
    }
}
