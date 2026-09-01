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

namespace NHA\Parts;

/**
 * A lightweight, read-only wrapper around a single `GET /station`
 * response.
 *
 * The upstream OpenAPI schema currently returns an empty object, so this
 * wrapper is intentionally permissive and preserves any extra keys.
 *
 * @since 0.1.0
 */
class Station extends Out
{
    /**
     * Empty object schema: permit any server-provided keys without hard-coding
     * a stale or incomplete set.
     */
    protected $attributes = [];
}
