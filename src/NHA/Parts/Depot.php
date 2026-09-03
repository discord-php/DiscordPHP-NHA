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
 * A lightweight, read-only wrapper around a single `GET /depot` response
 * (the `DepotOut` schema): the fixed depot buy/sell price sheet.
 *
 * @link https://nha.recluse.lol/docs#/economy/depot_depot_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/DepotOut
 *
 * @property array|null $prices Map of resource => price, or null when the depot is closed.
 *
 * @since 0.1.0
 */
class Depot extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'prices',
    ];
}
