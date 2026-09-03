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
 * A lightweight, read-only wrapper around a single deposit row returned by
 * `GET /deposits` (the rows inside the `DepositsOut.deposits` array).
 *
 * {@see \NHA\Repository\DepositsRepository::getDeposits()} resolves one of these
 * per row; the `{deposits: [...]}` envelope itself is not modelled.
 *
 * @link https://nha.recluse.lol/docs#/world/deposits_ep_deposits_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/DepositsOut
 *
 * @property int|string $id       Deposit id.
 * @property string     $resource Resource type, e.g. aluminum/silicon/titanium.
 * @property int        $amount   Remaining units (always > 0 for returned rows).
 * @property int        $x        Deposit x coordinate.
 * @property int        $y        Deposit y coordinate.
 * @property int|float  $dist     Distance from the queried reference point.
 *
 * @since 0.1.0
 */
class Deposits extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'id',
        'resource',
        'amount',
        'x',
        'y',
        'dist',
    ];
}
