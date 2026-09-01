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
 * Tiny JSON-file backed store for the default agent id, so chat/slash
 * commands and the relay loop can omit `agent_id` once an agent has been
 * registered.
 *
 * @since 0.1.0
 */
class StateStore
{
    protected array $data;

    public function __construct(protected readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode(file_get_contents($path), true) : [];
    }

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

    protected function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT));
    }
}
