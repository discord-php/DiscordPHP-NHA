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
 * Represents a generic part of the NHA world.
 * Since the NHA API returns heterogeneous data, this can serve as a
 * base or a generic container if specific Part classes are not created.
 */
class NHAData implements JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data
    ) {}

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * Access data using dot notation.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->data;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }

        return $current;
    }
}
