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
 * A lightweight, read-only wrapper around a single `GET /records` response
 * (the `RecordsOut` schema): the records board — space firsts, fastest
 * aircraft, top inventor/builder, richest, wonders.
 *
 * The schema is deliberately free-form (keys vary), so no fields are declared;
 * read the raw payload via {@see \Discord\Parts\Part::getRawAttributes()}.
 *
 * @link https://nha.recluse.lol/docs#/history/records_records_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/RecordsOut
 *
 * @since 0.1.0
 */
class Records extends Out
{
    /**
     * Free-form schema: permit any server-provided keys without hard-coding
     * a stale set.
     */
    protected $fillable = [];
}
