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
 * Repository for querying NHA meta-related data.
 */
class MetaRepository extends AbstractRepository
{
    /**
     * Fetches the health status.
     *
     * @return PromiseInterface
     */
    public function getHealth(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::HEALTHZ);
    }

    /**
     * Fetches recent updates.
     *
     * @return PromiseInterface
     */
    public function getUpdates(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::UPDATES);
    }
}
