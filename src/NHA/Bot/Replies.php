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
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use NHA\NHA;
use React\Promise\PromiseInterface;

/**
 * Shared plumbing for turning a {@see \NHA\Commands} promise into a chat or
 * slash-command response, with a uniform `❌ <error>` on rejection.
 *
 * @since 3.1.27
 */
final class Replies
{
    public function __construct(private readonly NHA $nha) {}

    /** A container carrying one line of text (quick confirmations / errors). */
    public function text(string $content): Container
    {
        return Container::new()->addComponents([TextDisplay::new($content)]);
    }

    /** Sends a `Commands::` promise's result (a MessageBuilder) back to a chat message. */
    public function toMessage(Message $message, PromiseInterface $promise): void
    {
        $promise->then(
            fn($builder) => $message->channel->sendMessage($builder),
            fn(\Throwable $e) => $message->channel->sendMessage(
                NHA::createBuilder()->addComponent($this->text("❌ {$e->getMessage()}")),
            ),
        );
    }

    /**
     * Defers the interaction (world calls are network round-trips), then sends
     * the work's result — or the error — as the original response.
     *
     * @param PromiseInterface|callable():PromiseInterface $work      An already-started
     *                                                                promise, or a callable
     *                                                                run only after the defer lands.
     * @param bool                                         $ephemeral Whether the response is private to the caller.
     */
    public function toInteraction(Interaction $interaction, PromiseInterface|callable $work, bool $ephemeral = false): PromiseInterface
    {
        return $interaction->acknowledgeWithResponse($ephemeral)
            ->then(fn() => is_callable($work) ? $work() : $work)
            ->then(
                fn($builder) => $interaction->updateOriginalResponse($builder),
                fn(\Throwable $e) => $interaction->updateOriginalResponse(
                    NHA::createBuilder()->addComponent($this->text("❌ {$e->getMessage()}")),
                ),
            );
    }

    /** Flattens an interaction's (sub)command options into a plain assoc array. */
    public static function flattenOptions(?iterable $options): array
    {
        $out = [];
        foreach ($options ?? [] as $option) {
            $out[$option->name] = $option->value;
        }

        return $out;
    }
}
