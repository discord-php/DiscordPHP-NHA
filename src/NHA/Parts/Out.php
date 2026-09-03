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

use Discord\Parts\Part as DiscordPart;

/**
 * Base class for read-only NHA response Parts.
 *
 * Every concrete subclass corresponds to one `*Out` schema in the NHA OpenAPI
 * document and declares that schema's documented fields in `$fillable` (which
 * drives IDE `@property` hints and {@see jsonSerialize()}).
 *
 * The NHA API contract states that "endpoints may return additional keys
 * (models allow extras), so clients should tolerate them". DiscordPHP's
 * {@see DiscordPart::fill()} silently drops any key not in `$fillable`, so this
 * base overrides fill/serialisation to keep the whole payload: undeclared keys
 * remain readable via `$part->someExtraKey` and survive `json_encode()`.
 *
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 * @link https://nha.recluse.lol/docs Interactive API documentation
 *
 * @since 0.1.0
 */
abstract class Out extends DiscordPart
{
    /**
     * Stores the full decoded payload, then applies any declared attribute
     * mutators. Unlike the parent, keys absent from `$fillable` are preserved
     * so the model tolerates the extra keys the NHA API is allowed to add.
     *
     * @param array $attributes The decoded response body.
     */
    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        parent::fill($attributes);
    }

    /**
     * Serialises back to the full raw payload (lossless), not just the declared
     * subset, so round-tripping an NHA response never discards fields.
     */
    public function jsonSerialize(): array
    {
        return $this->getRawAttributes();
    }
}
