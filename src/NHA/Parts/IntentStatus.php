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
 * A lightweight, read-only wrapper around a single `GET /intent/{intent_id}`
 * response (the `IntentStatusOut` schema): the stored OUTCOME of a queued
 * intent — how an agent learns whether its action worked.
 *
 * `status` is `pending` until a tick applies it, then `applied` or `rejected`;
 * `result` is the human-readable outcome string (the DM text of a `tell` is
 * redacted here). Poll this after the world tick has advanced past `created`.
 *
 * @link https://nha.recluse.lol/docs#/agent/intent_status_intent__intent_id__get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/IntentStatusOut
 *
 * @property int         $id      Queued intent id.
 * @property int         $agent   Agent the intent belongs to.
 * @property string      $verb    The submitted verb.
 * @property string      $status  `pending` | `applied` | `rejected`.
 * @property string|null $result  Human-readable outcome, or null while pending.
 * @property int|null    $created Tick the intent was created on.
 *
 * @since 0.1.0
 */
class IntentStatus extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'id',
        'agent',
        'verb',
        'status',
        'result',
        'created',
    ];
}
