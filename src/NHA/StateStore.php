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

namespace NHA;

use NHA\State\AutoplayLeaseTrait;
use NHA\State\CapabilityLedgerTrait;
use NHA\State\CombineMemoryTrait;
use NHA\State\DecisionLogTrait;
use NHA\State\IdentityStateTrait;
use NHA\State\LoopStrategyStateTrait;
use NHA\State\PositionStateTrait;

/**
 * Tiny JSON-file backed store for data that must survive a bot restart: the
 * default agent id + token, the per-Discord-user identity map, each agent's
 * last-known world position, the autoplay flag + driver lease, the brain's
 * decision log, its `combine` / loop-guard / stance memory, and the registered
 * slash-command signatures. Volatile per-tick world state (market, scene, feed,
 * …) is deliberately NOT stored here — it is re-fetched live every time. Writes
 * are atomic (temp file + rename).
 *
 * The accessors are grouped into cohesive traits under {@see \NHA\State}; this
 * class is just the JSON file — load, the shared `$data` array, and the atomic
 * {@see save()} they all call. No NHA API schema backs it; it is local bot
 * state only. Attached to the client via {@see NHA::setStateStore()} so
 * {@see NHA::observe()} can snapshot position for every caller.
 *
 * @since 3.0.0
 */
class StateStore
{
    use AutoplayLeaseTrait;
    use CapabilityLedgerTrait;
    use CombineMemoryTrait;
    use DecisionLogTrait;
    use IdentityStateTrait;
    use LoopStrategyStateTrait;
    use PositionStateTrait;

    protected array $data;

    /**
     * Loads the store from `$path` (an empty state when the file is missing)
     * and sweeps any `.tmp` file left behind by a crash mid-{@see save()}.
     *
     * @param string $path Absolute path to the JSON state file.
     */
    public function __construct(protected readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode(file_get_contents($path), true) : [];

        // Sweep any temp file orphaned by a previous crash mid-{@see save()}.
        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /**
     * Atomically persists the current state: writes a sibling temp file then
     * renames it over the target, so a crash (or SIGTERM) mid-write can never
     * leave a truncated file. Silently no-ops if the temp write fails.
     */
    protected function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        // Write to a sibling temp file then rename, so a crash (or a SIGTERM
        // mid-write) can never leave a truncated / half-written state file.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }
        @rename($tmp, $this->path);
    }
}
