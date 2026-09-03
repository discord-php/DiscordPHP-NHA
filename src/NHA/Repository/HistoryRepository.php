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
use NHA\Parts\Feed;
use NHA\Parts\Inventors;
use NHA\Parts\Log;
use NHA\Parts\Milestones;
use NHA\Parts\Records;
use NHA\Parts\Timeline;
use React\Promise\PromiseInterface;

/**
 * Repository for the NHA `history` reads: the spectator activity feed, the
 * authoritative event log, milestones, the timeline, the records board, the
 * inventor leaderboard and the arena.
 *
 * @link https://nha.recluse.lol/docs#/history Interactive API documentation (history tag)
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @since 0.1.0
 */
class HistoryRepository extends AbstractRepository
{
    /**
     * Fetches recent agent actions, newest first (`GET /feed` → `FeedOut`).
     *
     * @link https://nha.recluse.lol/docs#/history/feed_feed_get
     *
     * @param int $limit Max rows, 1-200. Default 30.
     *
     * @return PromiseInterface<Feed>
     */
    public function getFeed(int $limit = 30): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::FEED);
        $endpoint->addQuery('limit', $limit);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Feed::class, (array) $data, true),
        );
    }

    /**
     * Fetches the arena board (`GET /arena`; free-form schema, resolved raw).
     *
     * @link https://nha.recluse.lol/docs#/history/arena_arena_get
     *
     * @return PromiseInterface
     */
    public function getArena(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::ARENA);
    }

    /**
     * Fetches the inventor leaderboard and discovery list
     * (`GET /inventors` → `InventorsOut`).
     *
     * @link https://nha.recluse.lol/docs#/history/inventors_inventors_get
     *
     * @return PromiseInterface<Inventors>
     */
    public function getInventors(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::INVENTORS)->then(
            fn($data) => $this->factory->part(Inventors::class, (array) $data, true),
        );
    }

    /**
     * Fetches the records board — space firsts, fastest aircraft, top
     * inventor/builder, richest, wonders (`GET /records` → `RecordsOut`,
     * free-form keys).
     *
     * @link https://nha.recluse.lol/docs#/history/records_records_get
     *
     * @return PromiseInterface<Records>
     */
    public function getRecords(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::RECORDS)->then(
            fn($data) => $this->factory->part(Records::class, (array) $data, true),
        );
    }

    /**
     * Fetches the authoritative server event log (`GET /log` → `LogOut`).
     *
     * `has_more` + `next_before_id` on the response are the paging cursor: pass
     * `next_before_id` back as `$before_id` for the next older page.
     *
     * @link https://nha.recluse.lol/docs#/history/server_log_log_get
     *
     * @param int    $limit     Max rows, 1-200. Default 60.
     * @param string $kind      Filter to one log kind; '' (default) for all.
     * @param int    $before    Only rows before this tick; 0 (default) to disable.
     * @param int    $after     Only rows after this tick; 0 (default) to disable.
     * @param int    $before_id Cursor from a previous response's `next_before_id`.
     *
     * @return PromiseInterface<Log>
     */
    public function getLog(int $limit = 60, string $kind = '', int $before = 0, int $after = 0, int $before_id = 0): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::LOG);
        $endpoint->addQuery('limit', $limit);

        if ($kind !== '') {
            $endpoint->addQuery('kind', $kind);
        }
        if ($before > 0) {
            $endpoint->addQuery('before', $before);
        }
        if ($after > 0) {
            $endpoint->addQuery('after', $after);
        }
        if ($before_id > 0) {
            $endpoint->addQuery('before_id', $before_id);
        }

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Log::class, (array) $data, true),
        );
    }

    /**
     * Fetches notable world firsts and achievements
     * (`GET /milestones` → `MilestonesOut`).
     *
     * @link https://nha.recluse.lol/docs#/history/milestones_milestones_get
     *
     * @param int $limit Max rows, 1-200. Default 40.
     *
     * @return PromiseInterface<Milestones>
     */
    public function getMilestones(int $limit = 40): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::MILESTONES);
        $endpoint->addQuery('limit', $limit);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Milestones::class, (array) $data, true),
        );
    }

    /**
     * Fetches the chronological world-history stream
     * (`GET /timeline` → `TimelineOut`).
     *
     * @link https://nha.recluse.lol/docs#/history/timeline_timeline_get
     *
     * @param int $limit Max rows, 1-200. Default 150.
     *
     * @return PromiseInterface<Timeline>
     */
    public function getTimeline(int $limit = 150): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::TIMELINE);
        $endpoint->addQuery('limit', $limit);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(Timeline::class, (array) $data, true),
        );
    }
}
