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
use NHA\Parts\Rules;
use NHA\Parts\Scene;
use NHA\Parts\Station;
use NHA\Parts\Structures;
use NHA\Parts\Expansion;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA world state.
 */
class WorldRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = World::class;

    /**
     * Fetches the current world state.
     *
     * @return PromiseInterface<World>
     */
    public function getWorld(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::WORLD)->then(
            fn($data) => $this->factory->part(World::class, (array) $data, true),
        );
    }

    /**
     * Fetches the map.
     *
     * @return PromiseInterface<Map>
     */
    public function getMap(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::MAP)->then(
            fn($data) => $this->factory->part(Map::class, (array) $data, true),
        );
    }

    /**
     * Fetches the current scene.
     *
     * @return PromiseInterface
     */
    public function getScene(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::SCENE)->then(
            fn($data) => $this->factory->part(Scene::class, (array) $data, true),
        );
    }

    /**
     * Fetches the structures in the scene.
     *
     * @return PromiseInterface<Structures>
     */
    public function getStructures(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::STRUCTURES)->then(
            fn($data) => $this->factory->part(Structures::class, (array) $data, true),
        );
    }

    /**
     * Fetches the station state.
     *
     * @return PromiseInterface<Station>
     */
    public function getStation(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::STATION)->then(
            fn($data) => $this->factory->part(Station::class, (array) $data, true),
        );
    }

    /**
     * Fetches colony information.
     *
     * @param  string           $body
     * @return PromiseInterface
     */
    public function getColony(string $body): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::COLONY)->bindAssoc(['body' => $body]);

        return $this->nha_http->get((string) $endpoint);
    }

    /**
     * Fetches terraform information.
     *
     * @param  string           $body
     * @return PromiseInterface
     */
    public function getTerraform(string $body): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::TERRAFORM)->bindAssoc(['body' => $body]);

        return $this->nha_http->get((string) $endpoint);
    }

    /**
     * Fetches expansion information.
     *
     * @return PromiseInterface
     */
    public function getExpansion(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::EXPANSION);
    }

    /**
     * Fetches the rules.
     *
     * @return PromiseInterface
     */
    public function getRules(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::RULES)->then(
            fn($data) => $this->factory->part(Rules::class, (array) $data, true),
        );
    }
}
