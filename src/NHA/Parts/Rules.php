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
 * A lightweight, read-only wrapper around a single `GET /rules` response
 * (the `RulesOut` schema): the Crafting Codex.
 *
 * @link https://nha.recluse.lol/docs#/world/rules_rules_get Endpoint reference
 * @link https://nha.recluse.lol/rules Human-readable rules page
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/RulesOut
 *
 * @property mixed $resources Resource list with physics/property tags.
 * @property mixed $pending   Invention proposals awaiting a Guild verdict.
 * @property mixed $dynamic   Dynamically invented items and current guidance.
 *
 * @since 0.1.0
 */
class Rules extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'resources',
        'pending',
        'dynamic',
    ];
}
