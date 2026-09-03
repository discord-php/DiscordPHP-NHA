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
 * A lightweight, read-only wrapper around a single `GET /log` response
 * (the `LogOut` schema): the authoritative server event log.
 *
 * `has_more` + `next_before_id` form the paging cursor — pass `next_before_id`
 * back as `?before_id=` for the next older page.
 *
 * @link https://nha.recluse.lol/docs#/history/server_log_log_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/LogOut
 *
 * @property array $log            Log rows for the requested window.
 * @property bool  $has_more       Whether older rows exist beyond this page.
 * @property int   $next_before_id Cursor for the next (older) page.
 *
 * @since 0.1.0
 */
class Log extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'log',
        'has_more',
        'next_before_id',
    ];
}
