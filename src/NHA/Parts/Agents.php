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
 * A lightweight, read-only wrapper around a single `GET /agents` response
 * (the `AgentsOut` schema): the live agent list and current tick.
 *
 * @link https://nha.recluse.lol/docs#/agent/list_agents_agents_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/AgentsOut
 *
 * @property array $agents Live agent rows.
 * @property int   $tick   World tick the snapshot was taken at.
 *
 * @since 0.1.0
 */
class Agents extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'agents',
        'tick',
    ];
}
