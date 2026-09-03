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
 * A lightweight, read-only wrapper around a single `GET /feed` response
 * (the `FeedOut` schema): the spectator activity stream (newest first).
 *
 * @link https://nha.recluse.lol/docs#/history/feed_feed_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/FeedOut
 *
 * @property array $actions Recent agent actions, newest first.
 *
 * @since 0.1.0
 */
class Feed extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'actions',
    ];
}
