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
use NHA\Parts\World;
use NHA\Parts\Map;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA world state.
 */
class WorldRepository extends AbstractRepository
{
    /**
     * Fetches the current world state.
     *
     * @return PromiseInterface<World>
     */
    public function getWorld(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::WORLD)->then(
            fn(array $data) => $this->factory->part(World::class, (array) $data, true)
        );
    }

    /**
     * Fetches the map.
     *
     * @return PromiseInterface<Map>
     */
    public function getMap(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::MAP)->then(
            fn(array $data) => $this->factory->part(Map::class, (array) $data, true)
        );
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

    /**
     * Fetches colony information.
     *
     * @param string $body
     * @return PromiseInterface
     */
    public function getColony(string $body): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::COLONY)->bindAssoc(['body' => $body]);

        return $this->client->fetch((string) $endpoint);
    }

    /**
     * Fetches terraform information.
     *
     * @param string $body
     * @return PromiseInterface
     */
    public function getTerraform(string $body): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::TERRAFORM)->bindAssoc(['body' => $body]);

        return $this->client->fetch((string) $endpoint);
    }

    /**
     * Fetches expansion information.
     *
     * @return PromiseInterface
     */
    public function getExpansion(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::EXPANSION);
    }

    /**
     * Fetches the rules.
     *
     * @return PromiseInterface
     */
    public function getRules(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::RULES);
    }
}
