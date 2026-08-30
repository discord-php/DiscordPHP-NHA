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
 * Repository for querying NHA social and communication data.
 */
class SocialRepository extends AbstractRepository
{
    /**
     * Fetches the chat.
     *
     * @return PromiseInterface
     */
    public function getChat(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::CHAT);
    }

    /**
     * Fetches the log.
     *
     * @return PromiseInterface
     */
    public function getLog(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::LOG);
    }

    /**
     * Fetches the roster.
     *
     * @return PromiseInterface
     */
    public function getRoster(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::ROSTER);
    }

    /**
     * Fetches the guild pending information.
     *
     * @return PromiseInterface
     */
    public function getGuildPending(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::GUILD_PENDING);
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
