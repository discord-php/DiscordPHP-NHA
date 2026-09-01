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
 * A lightweight, read-only wrapper around the server timeline.
 *
 * @since 0.1.0
 */
class Timeline extends Out
{
    /** @inheritdoc */
    protected $attributes = [
        'timeline',
    ];
}
