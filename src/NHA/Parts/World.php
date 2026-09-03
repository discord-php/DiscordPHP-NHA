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

namespace NHA\Parts;

/**
 * A lightweight, read-only wrapper around a single `GET /world` response
 * (the `WorldOut` schema): global tick count, tick length and entity tallies.
 *
 * @link https://nha.recluse.lol/docs#/world/world_world_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/WorldOut
 *
 * @property int         $tick            Monotonic world tick counter.
 * @property float       $tick_seconds    Real seconds per tick (default 2.0).
 * @property array       $entities        Map of entity type => count.
 * @property string|null $last_state_hash Deterministic hash of the last applied state.
 * @property int         $visitors        Current spectator count.
 *
 * @since 0.1.0
 */
class World extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'tick',
        'tick_seconds',
        'entities',
        'last_state_hash',
        'visitors',
    ];
}
