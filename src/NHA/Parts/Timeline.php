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
 * A lightweight, read-only wrapper around a single `GET /timeline` response
 * (the `TimelineOut` schema): the chronological world-history stream.
 *
 * @link https://nha.recluse.lol/docs#/history/timeline_timeline_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/TimelineOut
 *
 * @property array $timeline Ordered timeline entries.
 *
 * @since 0.1.0
 */
class Timeline extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'timeline',
    ];
}
