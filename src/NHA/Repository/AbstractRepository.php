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
use NHA\Http\Http;
use NHA\NHA;

/**
 * Repositories provide a way to store and update parts on the NHA server.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
abstract class AbstractRepository extends DiscordAbstractRepository
{
    use AbstractRepositoryTrait;

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
}
