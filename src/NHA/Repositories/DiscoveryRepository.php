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
 * Repository for querying NHA discovery and intent related data.
 */
class DiscoveryRepository extends AbstractRepository
{
    /**
     * Fetches nearby deposits.
     *
     * @param float $x
     * @param float $y
     * @param int $radius
     * @param string|null $resource
     * @param int $limit
     * @return PromiseInterface
     */
    public function getDeposits(float $x, float $y, int $radius = 100, ?string $resource = null, int $limit = 10): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::DEPOSITS);

        return $this->client->getNhaHttpClient()->get((string) $endpoint, [
            'x' => (string) $x,
            'y' => (string) $y,
            'radius' => (string) $radius,
            'resource' => $resource,
            'limit' => (string) $limit,
        ]);
    }
}
