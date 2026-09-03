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
 * A lightweight, read-only wrapper around a single `GET /healthz` response
 * (the `HealthOut` schema): the tick loop liveness probe.
 *
 * @link https://nha.recluse.lol/docs#/meta/healthz_healthz_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/HealthOut
 *
 * @property bool $ok      Whether the service is healthy.
 * @property int  $tick    Current world tick.
 * @property bool $running Whether the tick loop is advancing.
 * @property int  $drift   Scheduler drift in milliseconds.
 *
 * @since 0.1.0
 */
class Health extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'ok',
        'tick',
        'running',
        'drift',
    ];
}
