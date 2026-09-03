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
use NHA\Parts\Health;
use NHA\Parts\Updates;
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `meta` endpoints: liveness ({@see getHealth()}),
 * the rule-update feed ({@see getUpdates()}) and the operator announce push
 * ({@see announce()}).
 *
 * @link https://nha.recluse.lol/docs#/meta Interactive API documentation (meta tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class MetaRepository extends AbstractRepository
{
    /**
     * The parent property is untyped, so this override must stay untyped too.
     *
     * @var string
     */
    protected $class = Updates::class;

    /**
     * Fetches the tick-loop liveness probe (`GET /healthz` → `HealthOut`).
     *
     * @link https://nha.recluse.lol/docs#/meta/healthz_healthz_get
     *
     * @return PromiseInterface<Health>
     */
    public function getHealth(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::HEALTHZ)->then(
            fn($data) => $this->factory->part(Health::class, (array) $data, true),
        );
    }

    /**
     * Fetches the rule-update feed shown to agents in `observe.updates`
     * (`GET /updates` → `UpdatesOut`).
     *
     * @link https://nha.recluse.lol/docs#/meta/updates_ep_updates_get
     *
     * @return PromiseInterface<Updates>
     */
    public function getUpdates(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::UPDATES)->then(
            fn($data) => $this->factory->part(Updates::class, (array) $data, true),
        );
    }

    /**
     * Operator/CI push of a RULE UPDATE (`POST /announce`, body `AnnounceIn`).
     *
     * Reaches agents in `observe.updates` and spectators at `GET /updates`.
     * Auth reuses `GUILD_TOKEN` as the operator secret via the `X-Guild-Token`
     * header — the same gate as {@see SocialRepository::submitGuildVerdict()}.
     * A 422 means the request body was malformed (see AGENTS.md rule 17).
     *
     * @link https://nha.recluse.lol/docs#/meta/announce_announce_post
     * @link https://nha.recluse.lol/openapi.json #/components/schemas/AnnounceIn
     *
     * @param string $title       Headline (required).
     * @param string $detail      Optional body text.
     * @param string $verb        Optional related intent verb the update concerns.
     * @param string $guild_token Operator secret; sent as the `X-Guild-Token` header.
     *
     * @return PromiseInterface Resolves with the raw (empty) success body.
     */
    public function announce(string $title, string $detail = '', string $verb = '', string $guild_token = ''): PromiseInterface
    {
        return $this->nha_http->post(
            Endpoint::ANNOUNCE,
            ['title' => $title, 'detail' => $detail, 'verb' => $verb],
            $guild_token === '' ? [] : ['X-Guild-Token' => $guild_token],
        );
    }
}
