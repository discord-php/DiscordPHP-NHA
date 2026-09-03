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
use NHA\Parts\Map;
use NHA\Parts\Rules;
use NHA\Parts\Scene;
use NHA\Parts\Station;
use NHA\Parts\Structures;
use NHA\Parts\World;
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `world` endpoints: global state, the biome map / 3D
 * scene, structures, the space station, the Expansion-era spectator boards and
 * the crafting rules codex.
 *
 * @link https://nha.recluse.lol/docs#/world Interactive API documentation (world tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class WorldRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = World::class;

    /**
     * Fetches the current world state (`GET /world` → `WorldOut`).
     *
     * @link https://nha.recluse.lol/docs#/world/world_world_get
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
     * Fetches the ASCII biome map (`GET /map` → `MapOut`).
     *
     * @link https://nha.recluse.lol/docs#/world/world_map_map_get
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
     * Fetches the 3D World-tab scene graph (`GET /scene` → `SceneOut`).
     *
     * @link https://nha.recluse.lol/docs#/world/scene_scene_get
     *
     * @param bool $static When true (default) request the static `biomes`/`deposits`
     *                     layers too; pass false to omit them (they arrive as null).
     *
     * @return PromiseInterface<Scene>
     */
    public function getScene(bool $static = true): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::SCENE);
        $endpoint->addQuery('static', $static ? 1 : 0);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Scene::class, (array) $data, true),
        );
    }

    /**
     * Fetches placed structures (`GET /structures` → `StructuresOut`).
     *
     * @link https://nha.recluse.lol/docs#/world/structures_ep_structures_get
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
     * Fetches the co-op orbital-station blueprint + progress
     * (`GET /station` → `StationOut`; `{}` outside the Space era).
     *
     * @link https://nha.recluse.lol/docs#/world/station_ep_station_get
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
     * Fetches a body's Expansion colony board (`GET /colony/{body}`).
     *
     * The response schema is free-form (empty schema / null off-era), so the
     * raw decoded body is resolved without wrapping it in a Part.
     *
     * @link https://nha.recluse.lol/docs#/world/colony_ep_colony__body__get
     *
     * @param string $body One of phobos/deimos/mars/venus.
     *
     * @return PromiseInterface
     */
    public function getColony(string $body): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::bind(Endpoint::COLONY)->bindAssoc(['body' => $body]));
    }

    /**
     * Fetches a planet's Expansion terraforming program
     * (`GET /terraform/{body}`; free-form / null off-era).
     *
     * @link https://nha.recluse.lol/docs#/world/terraform_ep_terraform__body__get
     *
     * @param string $body One of mars/venus.
     *
     * @return PromiseInterface
     */
    public function getTerraform(string $body): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::bind(Endpoint::TERRAFORM)->bindAssoc(['body' => $body]));
    }

    /**
     * Fetches the whole-Expansion-Era spectator summary
     * (`GET /expansion`; free-form / null off-era).
     *
     * @link https://nha.recluse.lol/docs#/world/expansion_ep_expansion_get
     *
     * @return PromiseInterface
     */
    public function getExpansion(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::EXPANSION);
    }

    /**
     * Fetches the crafting rules codex (`GET /rules` → `RulesOut`): resources +
     * physics tags, formation patterns, pending proposals and dynamic inventions.
     *
     * @link https://nha.recluse.lol/docs#/world/rules_rules_get
     * @link https://nha.recluse.lol/rules Human-readable rules page
     *
     * @return PromiseInterface<Rules>
     */
    public function getRules(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::RULES)->then(
            fn($data) => $this->factory->part(Rules::class, (array) $data, true),
        );
    }
}
