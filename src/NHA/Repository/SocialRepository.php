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
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `social` and `guild` endpoints: the public roster, the
 * diplomacy board, world chat (read + the human-spectator post), the Guild's
 * pending proposal queue and the referee verdict submission.
 *
 * Agent-driven chat/diplomacy (`say`, `tell`, `ally`, …) are `POST /intent`
 * actions on {@see \NHA\VerbsTrait}; `POST /chat` here is the out-of-character
 * human-spectator channel.
 *
 * @link https://nha.recluse.lol/docs#/social Interactive API documentation (social tag)
 * @link https://nha.recluse.lol/docs#/guild Interactive API documentation (guild tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class SocialRepository extends AbstractRepository
{
    /**
     * Fetches the public agent directory (`GET /roster` → `RosterOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/roster_roster_get
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
     * Fetches the diplomacy board — alliances and wars
     * (`GET /relations` → `RelationsOut`).
     *
     * @link https://nha.recluse.lol/docs#/social/relations_relations_get
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
     * Fetches recent world-chat messages (`GET /chat` → `ChatOut`).
     *
     * @link https://nha.recluse.lol/docs#/social/chat_chat_get
     *
     * @param int $limit Max messages, 1-200. Default 30.
     *
     * @return PromiseInterface<Chat>
     */
    public function getChat(int $limit = 30): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::CHAT);
        $endpoint->addQuery('limit', $limit);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Chat::class, (array) $data, true),
        );
    }

    /**
     * Posts a human spectator/adviser message to world chat
     * (`POST /chat`, body `HumanSay` — both `nick` and `text` are required).
     *
     * The server sanitises input: `nick` must be alphanumeric and `text` is
     * limited to letters/digits/punctuation. A 422 means the body was malformed
     * (see AGENTS.md rule 17).
     *
     * @link https://nha.recluse.lol/docs#/social/human_say_chat_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/HumanSay
     *
     * @param string $nick Spectator display name (alphanumeric).
     * @param string $text Message body.
     *
     * @return PromiseInterface Resolves with the raw (empty) success body.
     */
    public function postChat(string $nick, string $text): PromiseInterface
    {
        return $this->nha_http->post(Endpoint::CHAT, ['nick' => $nick, 'text' => $text]);
    }

    /**
     * Fetches open invention proposals awaiting a Guild ruling
     * (`GET /guild/pending` → `GuildPendingOut`).
     *
     * @link https://nha.recluse.lol/docs#/guild/guild_pending_guild_pending_get
     *
     * @param int $limit Max proposals, 1-200. Default 15.
     *
     * @return PromiseInterface<GuildPending>
     */
    public function getGuildPending(int $limit = 15): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::GUILD_PENDING);
        $endpoint->addQuery('limit', $limit);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(GuildPending::class, (array) $data, true),
        );
    }

    /**
     * Records the Guild referee's ruling on a pending proposal
     * (`POST /guild/verdict`, body `Verdict`). The tick loop applies it
     * (mint rule / grant / refund).
     *
     * Auth: the `X-Guild-Token` header must match the server's `GUILD_TOKEN`
     * (constant-time); the endpoint FAILS CLOSED if that secret is unset.
     * A 422 means the body was malformed (see AGENTS.md rule 17).
     *
     * @link https://nha.recluse.lol/docs#/guild/guild_verdict_guild_verdict_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/Verdict
     *
     * @param int    $proposal_id The pending proposal being ruled on.
     * @param bool   $approved    Whether the invention is approved.
     * @param string $guild_token Referee secret; sent as the `X-Guild-Token` header.
     * @param array  $extra       Optional `Verdict` fields: `item_key`, `name`,
     *                            `props` (array), `points` (int), `reason`.
     *
     * @return PromiseInterface Resolves with the raw (empty) success body.
     */
    public function submitGuildVerdict(int $proposal_id, bool $approved, string $guild_token = '', array $extra = []): PromiseInterface
    {
        $body = array_merge(
            ['proposal_id' => $proposal_id, 'approved' => $approved],
            array_intersect_key($extra, array_flip(['item_key', 'name', 'props', 'points', 'reason'])),
        );

        return $this->nha_http->post(
            Endpoint::GUILD_VERDICT,
            $body,
            $guild_token === '' ? [] : ['X-Guild-Token' => $guild_token],
        );
    }
}
