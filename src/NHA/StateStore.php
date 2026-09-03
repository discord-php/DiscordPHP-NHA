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

use NHA\Parts\AgentObservation;

/**
 * Tiny JSON-file backed store for data that must survive a bot restart: the
 * default agent id + token, the per-Discord-user identity map, and each agent's
 * last-known world position. Volatile per-tick world state (market, scene, feed,
 * …) is deliberately NOT stored here — it is re-fetched live every time.
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

    /**
     * Snapshots the position + tick from a fresh observation. No-op when the
     * payload carries no position. Called for every {@see NHA::observe()} once
     * the store is attached via {@see NHA::setStateStore()}.
     */
    public function recordObservation(int $agent_id, AgentObservation $observation): void
    {
        $position = $observation->getPosition();
        if ($position === null) {
            return;
        }

        $tick = $observation->get('tick');
        $this->setAgentPosition(
            $agent_id,
            $position['x'],
            $position['y'],
            is_numeric($tick) ? (int) $tick : null,
        );
    }

    /**
     * Records an agent's last-known world position (from `GET /observe/:id`),
     * so a later turn can show it without a fresh fetch or detect that the
     * agent has moved. Written under `agent_positions` keyed by agent id.
     *
     * @param int      $agent_id
     * @param int      $x
     * @param int      $y
     * @param int|null $tick     The observation tick, when known.
     */
    public function setAgentPosition(int $agent_id, int $x, int $y, ?int $tick = null): void
    {
        $entry = ['x' => $x, 'y' => $y, 'updated_at' => time()];
        if (null !== $tick) {
            $entry['tick'] = $tick;
        }

        $this->data['agent_positions'][(string) $agent_id] = $entry;
        $this->save();
    }

    /**
     * Gets an agent's last-known position, if one has been recorded.
     *
     * @return array{x: int, y: int, tick?: int, updated_at: int}|null
     */
    public function getAgentPosition(int $agent_id): ?array
    {
        $entry = $this->data['agent_positions'][(string) $agent_id] ?? null;
        if (! is_array($entry) || ! isset($entry['x'], $entry['y'])) {
            return null;
        }

        $position = [
            'x' => (int) $entry['x'],
            'y' => (int) $entry['y'],
            'updated_at' => (int) ($entry['updated_at'] ?? 0),
        ];
        if (isset($entry['tick'])) {
            $position['tick'] = (int) $entry['tick'];
        }

        return $position;
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
