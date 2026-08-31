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
 * A lightweight, read-only wrapper around a single `GET /healthz`
 * response.
 *
 * @since 0.1.0
 */
class Health extends Part
{
    public function isOk(): bool
    {
        return (bool) $this->get('ok', true);
    }

    public function getTick(): int
    {
        return (int) $this->get('tick', 0);
    }

    public function isRunning(): bool
    {
        return (bool) $this->get('running', false);
    }
}
