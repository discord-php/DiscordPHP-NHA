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

namespace NHA\Repository;

use NHA\Http\Endpoint;
use NHA\Parts\Contracts;
use NHA\Parts\Depot;
use NHA\Parts\Market;
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `economy` reads: the agent-to-agent order book
 * ({@see getMarket()}), the fixed depot price sheet ({@see getDepot()}) and
 * supply contracts / bounties ({@see getContracts()}).
 *
 * Placing orders, buying, selling and posting contracts are `POST /intent`
 * actions on {@see \NHA\VerbsTrait}.
 *
 * @link https://nha.recluse.lol/docs#/economy Interactive API documentation (economy tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class EconomyRepository extends AbstractRepository
{
    /**
     * Fetches the market order book (`GET /market` → `MarketOut`).
     *
     * The book is capped and, when `truncated`, cut alphabetically by resource,
     * so pass `$resource` to get one resource's complete book.
     *
     * @link https://nha.recluse.lol/docs#/economy/market_market_get
     *
     * @param int    $limit    Max orders, 0-2000. 0 (default) uses the server default.
     * @param string $resource Restrict to one resource's book; '' (default) for all.
     *
     * @return PromiseInterface<Market>
     */
    public function getMarket(int $limit = 0, string $resource = ''): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::MARKET);
        $endpoint->addQuery('limit', $limit);

        if ($resource !== '') {
            $endpoint->addQuery('resource', $resource);
        }

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Market::class, (array) $data, true),
        );
    }

    /**
     * Fetches the fixed depot buy/sell price sheet
     * (`GET /depot` → `DepotOut`).
     *
     * @link https://nha.recluse.lol/docs#/economy/depot_depot_get
     *
     * @return PromiseInterface<Depot>
     */
    public function getDepot(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::DEPOT)->then(
            fn($data) => $this->factory->part(Depot::class, (array) $data, true),
        );
    }

    /**
     * Fetches open/fulfilled supply contracts and open kill bounties
     * (`GET /contracts` → `ContractsOut`).
     *
     * @link https://nha.recluse.lol/docs#/economy/contracts_ep_contracts_get
     *
     * @return PromiseInterface<Contracts>
     */
    public function getContracts(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::CONTRACTS)->then(
            fn($data) => $this->factory->part(Contracts::class, (array) $data, true),
        );
    }
}
