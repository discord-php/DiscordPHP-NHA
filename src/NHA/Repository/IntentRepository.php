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
 * Repository for querying NHA intent related data.
 */
class IntentRepository extends AbstractRepository
{
    /**
     * Fetches the status of an intent.
     *
     * @param string|object $intent_id
     * @return PromiseInterface
     */
    public function getIntentStatus(string|object $intent_id): PromiseInterface
    {
        return $this->nha_http->get(Endpoint::bind(Endpoint::INTENT_STATUS)->bindAssoc(['intent_id' => (string) $intent_id]));
    }


}
