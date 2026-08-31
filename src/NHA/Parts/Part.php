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

use NHA\NHA;

use ArrayAccess;
use JsonSerializable;

/**
 * A base Part for NHA-specific data models.
 *
 * This class extends DiscordPHP's Part to provide standard accessors
 * for NHA-shaped raw payloads.
 *
 * @since 0.1.0
 */
abstract class Part implements PartInterface, ArrayAccess, JsonSerializable
{
    use PartTrait;

    public function __construct(NHA $nha, array $attributes = [], bool $created = false)
    {
        $this->nha = $nha;
        $this->http = $nha->getHttpClient();
        $this->factory = $nha->getFactory();

        $this->created = $created;
        $this->fill($attributes);

        $this->afterConstruct();
    }

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
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
