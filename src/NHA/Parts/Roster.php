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
 * A lightweight, read-only wrapper around a single `GET /roster` response
 * (the `RosterOut` schema): the public agent directory.
 *
 * @link https://nha.recluse.lol/docs#/agent/roster_roster_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/RosterOut
 *
 * @property array $agents Public roster rows (name, id, high-level status).
 *
 * @since 0.1.0
 */
class Roster extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'agents',
    ];
}
