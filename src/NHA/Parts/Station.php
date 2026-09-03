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
 * A lightweight, read-only wrapper around a single `GET /station` response
 * (the `StationOut` schema): the co-op orbital-station blueprint + live
 * per-module progress. Empty/dormant outside the Space era.
 *
 * The schema declares no fixed fields (keys vary by era), so nothing is listed
 * in `$fillable`; the {@see Out} base still keeps every key the server sends.
 *
 * @link https://nha.recluse.lol/docs#/world/station_ep_station_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/StationOut
 *
 * @since 0.1.0
 */
class Station extends Out
{
    /**
     * Empty-object schema: permit any server-provided keys without hard-coding
     * a stale or incomplete set.
     */
    protected $fillable = [];
}
