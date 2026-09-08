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

namespace NHA\State;

/**
 * {@see \NHA\StateStore} slice: durable `combine` bookkeeping so
 * {@see \NHA\Brain\AutoPlayer} never re-submits a set that mints nothing —
 * every set tried this session (capped, deduped), and the confirmed-dead
 * subset the Inventors' Guild has rejected. Both survive a restart: a restart
 * is not a fresh invention budget.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait CombineMemoryTrait
{
    /** Cap on the "every set tried" list per agent. */
    private const TRIED_COMBINES_CAP = 2000;

    /** Cap on the confirmed-dead list per agent. */
    private const DEAD_COMBINES_CAP = 5000;

    /**
     * Records that a `combine` set — identified by its sorted `"a+b"` signature —
     * has been submitted for this agent, so {@see \NHA\Brain\AutoPlayer} can
     * refuse to resubmit it (the world mints nothing for a repeat). Kept as a
     * capped, de-duplicated list that also survives a restart.
     *
     * @since 3.1.6
     */
    public function recordCombineSignature(int $agent_id, string $signature): void
    {
        $signature = trim($signature);
        if ($signature === '') {
            return;
        }

        $key = (string) $agent_id;
        $sigs = array_values(array_filter(
            (array) ($this->data['agent_combine_sigs'][$key] ?? []),
            'is_string',
        ));
        if (in_array($signature, $sigs, true)) {
            return;
        }

        $sigs[] = $signature;
        $this->data['agent_combine_sigs'][$key] = array_slice($sigs, -self::TRIED_COMBINES_CAP);
        $this->save();
    }

    /**
     * Records a `combine` set the world has PROVEN cannot make anything — the
     * Inventors' Guild rejected the submission. Unlike {@see recordCombineSignature()}
     * (every set the agent tried), this is the confirmed-dead subset: it is never
     * worth another intent, ever, so {@see \NHA\Brain\AutoPlayer} treats it like a
     * world-known recipe and never lets it through — not even a production recipe.
     *
     * @since 3.1.11
     */
    public function recordDeadCombine(int $agent_id, string $signature): void
    {
        $signature = trim($signature);
        if ($signature === '') {
            return;
        }

        $key = (string) $agent_id;
        $sigs = array_values(array_filter(
            (array) ($this->data['agent_dead_combines'][$key] ?? []),
            'is_string',
        ));
        if (in_array($signature, $sigs, true)) {
            return;
        }

        $sigs[] = $signature;
        $this->data['agent_dead_combines'][$key] = array_slice($sigs, -self::DEAD_COMBINES_CAP);
        $this->save();
    }

    /**
     * Every `combine` signature this agent has already submitted, oldest first.
     *
     * @return list<string>
     *
     * @since 3.1.6
     */
    public function getTriedCombineSignatures(int $agent_id): array
    {
        return array_values(array_filter(
            (array) ($this->data['agent_combine_sigs'][(string) $agent_id] ?? []),
            'is_string',
        ));
    }

    /**
     * Every `combine` signature the Guild has rejected for this agent — sets
     * proven to make nothing. Oldest first.
     *
     * @return list<string>
     *
     * @since 3.1.11
     */
    public function getDeadCombines(int $agent_id): array
    {
        return array_values(array_filter(
            (array) ($this->data['agent_dead_combines'][(string) $agent_id] ?? []),
            'is_string',
        ));
    }
}
