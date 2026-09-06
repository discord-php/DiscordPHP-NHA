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
 * Resolves the {@see AgentContext} a dual-mode command runs as.
 *
 * The consuming class must expose a `StateStore $state` property. Every
 * command entry point that can act either as the caller or as the bot funnels
 * its "whose agent?" selector through {@see actor()}, so the choice lives in
 * exactly one place.
 *
 * @since 0.2.0
 */
trait ActorTrait
{
    /**
     * Works out who an action runs as.
     *
     * With `$as === null` (the default) it is the invoking Discord user's own
     * linked agent. `$as` overrides that:
     *   - `"bot"` / `"default"` / `"self"` → the bot's default agent;
     *   - a numeric string or int          → that agent id, using the bot token.
     *
     * When the caller has no linked agent and gave no override, it falls back
     * to the bot's default agent if one exists.
     *
     * @param string|null     $discord_user_id The invoking user's id, or null for a bot-only entry point.
     * @param string|int|null $as              Selector, usually straight from a slash-command option.
     *
     * @throws \RuntimeException When nothing can be acted as.
     */
    public function actor(?string $discord_user_id, string|int|null $as = null): AgentContext
    {
        if (is_int($as) || (is_string($as) && ctype_digit($as))) {
            return new AgentContext((int) $as, null, "agent #{$as}");
        }

        if (is_string($as) && in_array(strtolower(trim($as)), ['bot', 'default', 'self'], true)) {
            return AgentContext::bot($this->requireDefaultAgent());
        }

        if ($discord_user_id !== null && ($linked = $this->state->getDiscordUserAgent($discord_user_id))) {
            return AgentContext::user($linked['agent_id'], $linked['token']);
        }

        if ($default = $this->state->getDefaultAgent()) {
            return AgentContext::bot($default);
        }

        throw new \RuntimeException($discord_user_id !== null
            ? 'No agent is linked to your Discord account yet. Use `/login` first, or pass `agent: bot`.'
            : 'No default agent is configured. Register one with `/nha register` first.');
    }

    private function requireDefaultAgent(): int
    {
        return $this->state->getDefaultAgent()
            ?? throw new \RuntimeException('No default agent is configured. Register one with `/nha register` first.');
    }
}
