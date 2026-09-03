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
 * A lightweight, read-only wrapper around a single `GET /guild/pending`
 * response (the `GuildPendingOut` schema): open invention proposals awaiting a
 * ruling, each with its ingredients' physics for the referee.
 *
 * The referee records its ruling via `POST /guild/verdict`
 * ({@see \NHA\Repository\SocialRepository::submitGuildVerdict()}).
 *
 * @link https://nha.recluse.lol/docs#/guild/guild_pending_guild_pending_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/GuildPendingOut
 *
 * @property array $pending Open proposals with ingredient physics.
 *
 * @since 0.1.0
 */
class GuildPending extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'pending',
    ];
}
