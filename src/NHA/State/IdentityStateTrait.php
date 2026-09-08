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

namespace NHA\State;

/**
 * {@see \NHA\StateStore} slice: the stable operational config — the default
 * agent id + token, the per-Discord-user identity map, the autoplay on/off
 * flag, and the registered slash-command signatures.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait IdentityStateTrait
{
    /** The stored default agent id, or null when none has been registered. */
    public function getDefaultAgent(): ?int
    {
        return isset($this->data['default_agent']) ? (int) $this->data['default_agent'] : null;
    }

    /**
     * Sets the default agent id and, when known, its NHA action token.
     *
     * Passing `null` for `$token` leaves any previously stored token intact,
     * so callers that only know the agent id don't clobber it.
     */
    public function setDefaultAgent(int $agent_id, ?string $token = null): void
    {
        $this->data['default_agent'] = $agent_id;
        if (null !== $token) {
            $this->data['default_agent_token'] = $token;
        }
        $this->save();
    }

    /**
     * Gets the NHA action token for the default agent, if known.
     */
    public function getDefaultAgentToken(): ?string
    {
        return isset($this->data['default_agent_token']) ? (string) $this->data['default_agent_token'] : null;
    }

    /**
     * Gets the NHA identity assigned to a Discord user.
     *
     * @return array{agent_id: int, name: string, token: string}|null
     */
    public function getDiscordUserAgent(string $discord_user_id): ?array
    {
        $agent = $this->data['discord_users'][$discord_user_id] ?? null;
        if (! is_array($agent) || ! isset($agent['agent_id'], $agent['name'], $agent['token'])) {
            return null;
        }

        return [
            'agent_id' => (int) $agent['agent_id'],
            'name' => (string) $agent['name'],
            'token' => (string) $agent['token'],
        ];
    }

    /**
     * Saves a Discord user's NHA identity for later turns.
     */
    public function setDiscordUserAgent(string $discord_user_id, int $agent_id, string $name, string $token): void
    {
        $this->data['discord_users'][$discord_user_id] = [
            'agent_id' => $agent_id,
            'name' => $name,
            'token' => $token,
        ];
        $this->save();
    }

    /**
     * Whether the autonomous LLM play loop is currently enabled. Persisted so a
     * `!nha autoplay on` survives a restart.
     */
    public function isAutoplayEnabled(): bool
    {
        return (bool) ($this->data['autoplay'] ?? false);
    }

    /**
     * Turns the autonomous LLM play loop on or off.
     */
    public function setAutoplay(bool $enabled): void
    {
        $this->data['autoplay'] = $enabled;
        $this->save();
    }

    /**
     * The signature of the last successfully registered slash command by name.
     * Used to decide whether a command's definition changed since the last boot
     * and needs pushing to Discord (a rate-limited write).
     *
     * @return array<string, string> `command name => sha1(definition)`
     */
    public function getCommandSignatures(): array
    {
        return array_map('strval', (array) ($this->data['command_signatures'] ?? []));
    }

    /** Records that `$name` was registered with definition signature `$hash`. */
    public function setCommandSignature(string $name, string $hash): void
    {
        $this->data['command_signatures'][$name] = $hash;
        $this->save();
    }
}
