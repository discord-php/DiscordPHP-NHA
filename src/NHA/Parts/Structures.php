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
 * A lightweight, read-only wrapper around a single `GET /structures` response
 * (the `StructuresOut` schema): every placed structure in the world.
 *
 * @link https://nha.recluse.lol/docs#/world/structures_ep_structures_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/StructuresOut
 *
 * @property array $structures Structure rows (type, owner, position, progress).
 *
 * @since 0.1.0
 */
class Structures extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'structures',
    ];
}
