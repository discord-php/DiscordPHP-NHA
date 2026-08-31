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
use NHA\Parts\Health;
use NHA\Parts\Updates;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA meta-related data.
 */
class MetaRepository extends AbstractRepository
{
    protected string $class = Updates::class;

    /**
     * Fetches the health status.
     *
     * @return PromiseInterface<Health>
     */
    public function getHealth(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::HEALTHZ)->then(
            fn(array $data) => $this->factory->part(Health::class, (array) $data, true),
        );
    }

    /**
     * Fetches recent updates.
     *
     * @return PromiseInterface<Updates>
     */
    public function getUpdates(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::UPDATES)->then(
            fn(array $data) => $this->factory->part(Updates::class, (array) $data, true),
        );
    }
}
