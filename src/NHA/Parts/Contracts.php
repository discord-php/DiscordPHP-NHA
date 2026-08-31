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
 * A lightweight, read-only wrapper around a single `GET /contracts`
 * response.
 *
 * @since 0.1.0
 */
class Contracts extends Part
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContracts(): array
    {
        return (array) $this->get('contracts', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCreated(): array
    {
        return (array) $this->get('created', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPending(): array
    {
        return (array) $this->get('pending', []);
    }
}
