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
 * A lightweight, read-only wrapper around a single `GET /scene` response
 * (the `SceneOut` schema): the 3D World-tab scene graph.
 *
 * The `biomes`/`deposits` static layers are sent only for `?static=1`
 * (see {@see \NHA\Repository\WorldRepository::getScene()}) and are `null`
 * otherwise — do not treat a missing layer as "empty".
 *
 * @link https://nha.recluse.lol/docs#/world/scene_scene_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/SceneOut
 *
 * @property int               $w          Scene width.
 * @property int               $h          Scene height.
 * @property array             $agents     Agents in the scene.
 * @property bool              $loading    Whether the scene is still building.
 * @property array|null        $biomes     Static biome polygons (only with ?static=1).
 * @property array|null        $deposits   Static deposit markers (only with ?static=1).
 * @property array|null        $structures Placed structures.
 * @property array|null        $vehicles   Vehicles in the scene.
 * @property array|null        $artifacts  Ancient artifacts.
 * @property array|null        $asteroids  Dockable asteroids (space era).
 * @property array|null        $geese      Roaming geese.
 * @property array|null        $bombs      Armed/placed bombs.
 * @property array|object|null $storm      Active storm cell, if any.
 *
 * @since 0.1.0
 */
class Scene extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'w',
        'h',
        'agents',
        'loading',
        'biomes',
        'deposits',
        'structures',
        'vehicles',
        'artifacts',
        'asteroids',
        'geese',
        'bombs',
        'storm',
    ];
}
