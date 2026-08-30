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
 * Repository for querying NHA world state.
 */
class WorldRepository extends AbstractRepository
{
    /**
     * Fetches the current world state.
     *
     * @return PromiseInterface
     */
    public function getWorld(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::WORLD);
    }

    /**
     * Fetches the map.
     *
     * @return PromiseInterface
     */
    public function getMap(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MAP);
    }

    /**
     * Fetches the current scene.
     *
     * @return PromiseInterface
     */
    public function getScene(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::SCENE);
    }

    /**
     * Fetches the structures in the scene.
     *
     * @return PromiseInterface
     */
    public function getStructures(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::STRUCTURES);
    }
}
