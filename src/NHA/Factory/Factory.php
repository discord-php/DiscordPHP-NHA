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

namespace NHA\Factory;

use Discord\Factory\Factory as DiscordFactory;
use Discord\Http\Http;
use Discord\Repository\AbstractRepository;
use Discord\Client;
use NHA\NHA;
use NHA\Http\Http as NHAHttp;
use NHA\Parts\Part;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;

/**
 * The NHA Factory.
 *
 * This class extends the Discord factory to support NHA-specific object creation,
 * such as NHA Parts and Repositories.
 */
class Factory extends DiscordFactory
{
    /**
     * @var NHA The NHA client.
     */
    protected NHA $nha;

    /**
     * @var NHAHttp The NHA HTTP client.
     */
    protected NHAHttp $nhaHttp;

    /**
     * Create a new Factory instance.
     *
     * @param LoopInterface $loop The event loop.
     * @param LoggerInterface $logger The logger.
     * @param Client $client The Discord client.
     * @param NHA $nha The NHA client.
     * @param NHAHttp $nhaHttp The NHA HTTP client.
     */
    public function __construct(
        LoopInterface $loop,
        LoggerInterface $logger,
        Client $client,
        NHA $nha,
        NHAHttp $nhaHttp
    ) {
        parent::__construct($loop, $logger, $client);
        $this->nha = $nha;
        $this->nhaHttp = $nhaHttp;
    }

    /**
     * Get the NHA client.
     *
     * @return NHA
     */
    public function getNHA(): NHA
    {
        return $this->nha;
    }

    /**
     * Get the NHA HTTP client.
     *
     * @return NHAHttp
     */
    public function getNHAHttp(): NHAHttp
    {
        return $this->nhaHttp;
    }

    /**
     * Create a new Part instance.
     *
     * @param string $class The class to create.
     * @param array|object $data The data to use for the part.
     * @param bool $created Whether the part is already created.
     *
     * @return Part
     */
    public function part(string $class, array|object $data, bool $created = false): Part
    {
        // In NHA, we want to ensure that parts are created using our specific logic
        // if they are NHA parts. 
        // For now, we delegate to the parent but we can override if we need to 
        // intercept specific NHA Part class instantiation.
        
        return parent::part($class, (array) $data, $created);
    }

    /**
     * Create a new Repository instance.
     *
     * @param string $class The class to create.
     * @param array $attributes The attributes to use for the repository.
     *
     * @return AbstractRepository
     */
    public function repository(string $class, array $attributes = []): AbstractRepository
    {
        // Override to ensure NHA repositories are handled correctly if needed.
        return parent::repository($class, $attributes);
    }
}
