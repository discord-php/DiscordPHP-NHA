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

use NHA\Parts\AgentObservation;

/**
 * {@see \NHA\StateStore} slice: each agent's last-known world position + tick,
 * snapshotted from `GET /observe/:id` so a later turn can show it (or detect
 * movement) without a fresh fetch.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait PositionStateTrait
{
    /**
     * Snapshots the position + tick from a fresh observation. No-op when the
     * payload carries no position. Called for every {@see \NHA\NHA::observe()}
     * once the store is attached via {@see \NHA\NHA::setStateStore()}.
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
}
