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

namespace NHA;

/**
 * Identifies which agent a command runs as, and with which NHA action token.
 *
 * A `null` {@see $token} means "use whatever token is configured on the
 * {@see NHA} client" — i.e. the bot's own default agent. A non-null token is
 * an explicit per-Discord-user credential and is sent verbatim on
 * `POST /intent`, so the same handler can act as the bot or as the caller
 * without any other code path changing.
 *
 * Build one through {@see ActorTrait::actor()} rather than by hand.
 *
 * @since 0.2.0
 */
final class AgentContext
{
    /**
     * @param int         $agentId The acting agent's id.
     * @param string|null $token   The agent's NHA token, or null to use the bot's ambient token.
     * @param string      $label   Short human label ("you", "the bot", "agent #12") for confirmations.
     */
    public function __construct(
        public readonly int $agentId,
        public readonly ?string $token = null,
        public readonly string $label = 'the agent',
    ) {}

    /** Context for the bot's own default agent (its ambient token is used). */
    public static function bot(int $agentId): self
    {
        return new self($agentId, null, 'the bot');
    }

    /** Context for a Discord user's linked agent, carrying that user's token. */
    public static function user(int $agentId, string $token): self
    {
        return new self($agentId, $token, 'you');
    }
}
