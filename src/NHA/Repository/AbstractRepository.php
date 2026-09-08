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

use Discord\Discord;
use Discord\Repository\AbstractRepository as DiscordAbstractRepository;
use NHA\Http\Endpoint;
use NHA\Http\Http;
use NHA\NHA;
use NHA\Parts\Out;
use React\Promise\PromiseInterface;

/**
 * Base class for the NHA read repositories hung off {@see \NHA\Client}. Each
 * concrete repository groups the endpoints of one OpenAPI tag and resolves
 * {@see \NHA\Parts\Out} parts (or raw bodies for free-form schemas).
 *
 * Caching note: these repositories intentionally do NOT populate the inherited
 * DiscordPHP part cache (`$this->cache` / `$this->items`). NHA world state
 * (`/world`, `/market`, `/scene`, `/feed`, …) changes every ~2s tick, and the
 * server already single-flights it per tick (`X-Cache-Status`), so a client-side
 * copy would just serve stale data. Each `getX()` therefore performs a live
 * fetch. Durable, restart-surviving data (agent identity, last-known position)
 * belongs in {@see \NHA\StateStore}; a last-known observation snapshot lives on
 * {@see \NHA\NHA::getCachedObservation()}. The inherited `fetch()`/`freshen()`/
 * `save()`/`delete()` helpers are unconfigured here (no `$endpoints`) and reject
 * gracefully rather than being wired up.
 *
 * @link https://nha.recluse.lol/docs Interactive API documentation
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
abstract class AbstractRepository extends DiscordAbstractRepository
{
    use AbstractRepositoryTrait;

    /**
     * NHA repositories return one-off `Out` parts rather than a typed collection,
     * but the parent Discord repository constructor requires a non-null class name.
     *
     * @var string
     */
    protected $class = Out::class;

    /**
     * The extended HTTP client.
     *
     * @var Http Client.
     */
    protected $nha_http;

    /**
     * AbstractRepository constructor.
     *
     * @param NHA|Discord $discord
     * @param array       $vars    An array of variables used for the endpoint.
     */
    public function __construct(protected $discord, array $vars = [])
    {
        parent::__construct($discord, $vars);
        $this->nha_http = $discord->getNhaHttpClient();
    }

    /**
     * `GET $endpoint` and hydrate the JSON body into one `$class` {@see Out}
     * part — the shared shape of nearly every `getX()` on the concrete
     * repositories. Methods that return a raw body, build a list of parts in a
     * loop, or map an error to a synthetic part do that inline instead.
     *
     * @template T of Out
     *
     * @param class-string<T> $class    The `Out` subclass to hydrate.
     * @param Endpoint|string $endpoint A bound {@see Endpoint} or a raw path constant.
     *
     * @return PromiseInterface<T>
     */
    protected function fetchOut(string $class, Endpoint|string $endpoint): PromiseInterface
    {
        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part($class, (array) $data, true),
        );
    }
}
