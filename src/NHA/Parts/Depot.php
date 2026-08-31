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
 * A lightweight, read-only wrapper around a single `GET /depot`
 * response.
 *
 * @since 0.1.0
 */
class Depot extends Part
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return (array) $this->get('items', []);
    }

    /**
     * @return float
     */
    public function getTotalValue(): float
    {
        return (float) $this->get('total_value', 0.0);
    }
}
