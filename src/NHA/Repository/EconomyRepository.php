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
use NHA\NHA;
use NHA\Parts\Contracts;
use NHA\Parts\Depot;
use NHA\Parts\Market;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA economy related data.
 */
class EconomyRepository extends AbstractRepository
{
    /**
     * Fetches the market.
     *
     * @return PromiseInterface<Market>
     */
    public function getMarket(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::MARKET)->then(
            fn(array $data) => $this->factory->part(Market::class, (array) $data, true)
        );
    }

    /**
     * Fetches the depot.
     *
     * @return PromiseInterface<Depot>
     */
    public function getDepot(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::DEPOT)->then(
            fn(array $data) => $this->factory->part(Depot::class, (array) $data, true)
        );
    }

    /**
     * Fetches the contracts.
     *
     * @return PromiseInterface<Contracts>
     */
    public function getContracts(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::CONTRACTS)->then(
            fn(array $data) => $this->factory->part(Contracts::class, (array) $data, true)
        );
    }
}
