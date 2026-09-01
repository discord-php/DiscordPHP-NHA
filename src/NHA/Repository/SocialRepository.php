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
use NHA\Parts\Chat;
use NHA\Parts\GuildPending;
use NHA\Parts\Relations;
use NHA\Parts\Roster;
use NHA\Parts\Rules;
use React\Promise\PromiseInterface;

/**
 * Repository for social and guild-related NHA data.
 */
class SocialRepository extends AbstractRepository
{
    /**
     * Fetches the server roster.
     *
     * @return PromiseInterface<Roster>
     */
    public function getRoster(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::ROSTER)->then(
            fn($data) => $this->factory->part(Roster::class, (array) $data, true),
        );
    }

    /**
     * Fetches the current agent relations.
     *
     * @return PromiseInterface<Relations>
     */
    public function getRelations(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::RELATIONS)->then(
            fn($data) => $this->factory->part(Relations::class, (array) $data, true),
        );
    }

    /**
     * Fetches recent chat messages.
     *
     * @return PromiseInterface<Chat>
     */
    public function getChat(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::CHAT)->then(
            fn($data) => $this->factory->part(Chat::class, (array) $data, true),
        );
    }

    /**
     * Posts a chat message.
     *
     * @param string $text
     *
     * @return PromiseInterface<Chat>
     */
    public function postChat(string $text): PromiseInterface
    {
        return $this->nha_http->post(Endpoint::CHAT, ['text' => $text])->then(
            fn($data) => $this->factory->part(Chat::class, (array) $data, true),
        );
    }

    /**
     * Fetches the guild pending list.
     *
     * @return PromiseInterface<GuildPending>
     */
    public function getGuildPending(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::GUILD_PENDING)->then(
            fn($data) => $this->factory->part(GuildPending::class, (array) $data, true),
        );
    }

    /**
     * Returns the game rules metadata.
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
