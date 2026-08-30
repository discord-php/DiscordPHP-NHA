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
 * Represents an HTTP request against the NHA world.
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
