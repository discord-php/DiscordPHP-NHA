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
use NHA\Parts\Feed;
use NHA\Parts\Inventors;
use NHA\Parts\Log;
use NHA\Parts\Milestones;
use NHA\Parts\Records;
use NHA\Parts\Timeline;
use React\Promise\PromiseInterface;

/**
 * Repository for querying NHA history related data.
 */
class HistoryRepository extends AbstractRepository
{
    /**
     * Fetches the feed.
     *
     * @return PromiseInterface<Feed>
     */
    public function getFeed(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::FEED)->then(
            fn($data) => $this->factory->part(Feed::class, (array) $data, true),
        );
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
     * @return PromiseInterface<Inventors>
     */
    public function getInventors(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::INVENTORS)->then(
            fn($data) => $this->factory->part(Inventors::class, (array) $data, true),
        );
    }

    /**
     * Fetches records.
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
     * Fetches the system log.
     *
     * @return PromiseInterface<Log>
     */
    public function getLog(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::LOG)->then(
            fn($data) => $this->factory->part(Log::class, (array) $data, true),
        );
    }

    /**
     * Fetches milestones.
     *
     * @return PromiseInterface<Milestones>
     */
    public function getMilestones(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::MILESTONES)->then(
            fn($data) => $this->factory->part(Milestones::class, (array) $data, true),
        );
    }

    /**
     * Fetches the timeline.
     *
     * @return PromiseInterface<Timeline>
     */
    public function getTimeline(): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::TIMELINE)->then(
            fn($data) => $this->factory->part(Timeline::class, (array) $data, true),
        );
    }
}
