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
use NHA\Parts\Relations;
use NHA\Parts\Social;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA social and communication data.
 */
class SocialRepository extends AbstractRepository
{
    protected string $class = Social::class;

    /**
     * Fetches the chat.
     *
     * @return PromiseInterface<Social>
     */
    public function getChat(): PromiseInterface
    {
        return $this->fetchAll(Endpoint::CHAT);
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
     * Fetches relations.
     *
     * @return PromiseInterface<Relations>
     */
    public function getRelations(): PromiseInterface
    {
        return $this->client->fetch(Endpoint::RELATIONS)->then(fn(array $data) => $this->factory->part(Relations::class,(array) $data, true));
    }
}
