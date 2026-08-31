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
 * A Part for NHA social relations data.
 *
 * @since 0.1.0
 */
class Relations extends Part
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelations(): array
    {
        return $this->get('relations', []);
    }
}
