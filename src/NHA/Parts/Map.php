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
 * A lightweight, read-only wrapper around a single `GET /map` response
 * (the `MapOut` schema): the ASCII biome map and agent pins.
 *
 * @link https://nha.recluse.lol/docs#/world/world_map_map_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/MapOut
 *
 * @property int         $seed    World generation seed.
 * @property int         $w       Map width in tiles.
 * @property int         $h       Map height in tiles.
 * @property string|null $ascii   Rendered ASCII map, or null while loading.
 * @property array       $agents  Agent position pins.
 * @property bool        $loading Whether the map is still being generated.
 *
 * @since 0.1.0
 */
class Map extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'seed',
        'w',
        'h',
        'ascii',
        'agents',
        'loading',
    ];
}
