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

use Discord\Repository\AbstractRepository as DiscordAbstractRepository;
use NHA\NHA;

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
     * @param mixed $discord
     * @param array $vars
     */
    public function __construct($discord, array $vars = [])
    {
        parent::__construct($discord, $vars);
        /** @var NHA $discord */
        $this->client = $discord;
    }
}
