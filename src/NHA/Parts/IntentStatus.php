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
 * {@see \NHA\Repository\IntentRepository::getIntentStatus()} adds one synthetic
 * value, `gone`, for an id the world no longer retains (it applied or was
 * rejected long ago); it is terminal and carries no meaningful `result`.
 *
 * @link https://nha.recluse.lol/docs#/agent/intent_status_intent__intent_id__get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/IntentStatusOut
 *
 * @property int         $id     Queued intent id.
 * @property int         $agent  Agent the intent belongs to.
 * @property string      $verb   The submitted verb.
 * @property string      $status `pending` | `applied` | `rejected` | `gone`.
 * @property string|null $result Human-readable outcome, or null while pending.
 *
 * Note: the API field `created` (the tick the intent was queued on) collides
 * with DiscordPHP's `Part::$created` bool — read it via `$part['created']` or
 * `$part->getRawAttributes()['created']`, not `$part->created`.
 *
 * @since 3.0.0
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
