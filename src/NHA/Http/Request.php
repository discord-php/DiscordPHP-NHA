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

namespace NHA\Http;

use Discord\Http\Request as DiscordRequest;

/**
 * Represents a single queued HTTP request against the NHA world. Identical in
 * behaviour to the DiscordPHP request it extends; it exists only so NHA
 * transport code depends on an NHA-owned type rather than reaching into
 * `Discord\Http` directly.
 *
 * @see \Discord\Http\Request The DiscordPHP request this extends
 * @see \NHA\Http\Http Where these are created and sorted into rate-limit buckets
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class Request extends DiscordRequest
{
    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return Http::BASE_URL . '/' . $this->url;
    }
}
