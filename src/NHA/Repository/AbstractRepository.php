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

use Discord\Http\Drivers\React;
use Discord\Repository\AbstractRepository as DiscordAbstractRepository;
use NHA\Http\Http;
use NHA\Http\Endpoint;
use NHA\NHA;
use NHA\Client;
use React\Promise\PromiseInterface;

/**
 * Base class for all NHA-specific repositories.
 */
abstract class AbstractRepository extends DiscordAbstractRepository
{
    /**
     * @var NHA
     */
    protected NHA $client;

    /**
     * The extended NHA client.
     *
     * @var Http The extended NHA client.
     */
    protected Http $nha_http;

    /**
     * The class used to wrap the response data.
     */
    protected $class;

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->nha_http = new Http(
            'Bot '.$this->token,
            $this->loop,
            $this->options['logger'] ?? null,
            new React($this->loop, $options['socket_options'] ?? [])
        );
        $this->client = $this->factory->part(Client::class, (array) $this->client);
    }

    /**
     * @return PromiseInterface
     */
    protected function fetchAll(string|Endpoint $endpoint): PromiseInterface
    {
        return $this->client->fetch($endpoint)->then(
            fn(array $data) => new $this->class($data)
        );
    }
}
