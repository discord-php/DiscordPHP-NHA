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
 * A lightweight, read-only wrapper around a single `GET /milestones` response
 * (the `MilestonesOut` schema): notable world firsts and achievements.
 *
 * @link https://nha.recluse.lol/docs#/history/milestones_milestones_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/MilestonesOut
 *
 * @property array $milestones Milestone rows, newest first.
 *
 * @since 0.1.0
 */
class Milestones extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'milestones',
    ];
}
