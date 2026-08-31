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
use NHA\NHA;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA history related data.
 */
class HistoryRepository extends AbstractRepository
{
    /**
     * Fetches the feed.
     *
     * @return PromiseInterface
     */
    public function getFeed(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::FEED);
    }

    /**
     * Fetches arena data.
     *
     * @return PromiseInterface
     */
    public function getArena(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::ARENA);
    }

    /**
     * Fetches inventors.
     *
     * @return PromiseInterface
     */
    public function getInventors(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::INVENTORS);
    }

    /**
     * Fetches records.
     *
     * @return PromiseInterface
     */
    public function getRecords(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::RECORDS);
    }

    /**
     * Fetches milestones.
     *
     * @return PromiseInterface
     */
    public function getMilestones(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::MILESTONES);
    }

    /**
     * Fetches the timeline.
     *
     * @return PromiseInterface
     */
    public function getTimeline(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::TIMELINE);
    }
}
