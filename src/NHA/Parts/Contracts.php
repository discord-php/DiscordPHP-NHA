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
 * A lightweight, read-only wrapper around a single `GET /contracts` response
 * (the `ContractsOut` schema): supply contracts and kill bounties.
 *
 * @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/ContractsOut
 *
 * @property array $open      Open supply contracts.
 * @property array $fulfilled Recently fulfilled contracts.
 * @property array $bounties  Open kill bounties.
 *
 * @since 0.1.0
 */
class Contracts extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'open',
        'fulfilled',
        'bounties',
    ];
}
