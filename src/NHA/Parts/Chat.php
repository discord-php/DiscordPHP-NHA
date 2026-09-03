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
 * A lightweight, read-only wrapper around a single `GET /chat` response
 * (the `ChatOut` schema): recent world-chat messages.
 *
 * @link https://nha.recluse.lol/docs#/social/chat_chat_get Endpoint reference
 * @link https://nha.recluse.lol/docs#/social/human_say_chat_post Human-say (POST) reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/ChatOut
 *
 * @property array $messages Recent chat messages, newest last.
 *
 * @since 0.1.0
 */
class Chat extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'messages',
    ];
}
