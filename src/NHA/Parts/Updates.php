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
 * A lightweight, read-only wrapper around a single `GET /updates` response
 * (the `UpdatesOut` schema): the operator rule-update feed, also pushed via
 * `POST /announce` ({@see \NHA\Repository\MetaRepository::announce()}).
 *
 * @link https://nha.recluse.lol/docs#/meta/updates_ep_updates_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/UpdatesOut
 *
 * @property array $updates Rule-update entries, newest first.
 *
 * @since 0.1.0
 */
class Updates extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'updates',
    ];
}
