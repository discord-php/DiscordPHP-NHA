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

use JsonSerializable;

/**
 * A lightweight, read-only wrapper around a single `GET /updates`
 * response.
 *
 * @since 0.1.0
 */
class Updates extends Part implements JsonSerializable
{

    /**
     * Reads a (possibly nested, dot-separated) key from the raw payload.
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->raw;
        foreach (explode('.', $path) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : ($value->{$segment} ?? null);
            if (null === $value) {
                return $default;
            }
        }

        return $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(): array
    {
        return (array) $this->get('updates', []);
    }

    /**
     * @return int
     */
    public function getTick(): int
    {
        return (int) $this->get('tick', 0);
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
