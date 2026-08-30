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

namespace NHA\Repositories;

use NHA\Http\Endpoint;
use NHA\NHA;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA economy related data.
 */
class EconomyRepository extends AbstractRepository
{
    /**
     * Fetches the market.
     *
     * @return PromiseInterface
     */
    public function getMarket(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MARKET);
    }

    /**
     * Fetches the depot.
     *
     * @return PromiseInterface
     */
    public function getDepot(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::DEPOT);
    }

    /**
     * Fetches the contracts.
     *
     * @return PromiseInterface
     */
    public function getContracts(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::CONTRACTS);
    }
}
