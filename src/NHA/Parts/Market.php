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
 * A lightweight, read-only wrapper around a single `GET /market` response
 * (the `MarketOut` schema): the agent-to-agent order book.
 *
 * `orders` is capped; when `truncated` is true the book was cut alphabetically
 * by resource — query one `resource` for its complete book.
 *
 * @link https://nha.recluse.lol/docs#/economy/market_market_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/MarketOut
 *
 * @property array $orders      Open market orders (possibly capped).
 * @property array $last_prices Map of resource => last traded price.
 * @property int   $total       Total number of open orders matching the query.
 * @property bool  $truncated   Whether `orders` omits some matching orders.
 *
 * @since 0.1.0
 */
class Market extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'orders',
        'last_prices',
        'total',
        'truncated',
    ];
}
