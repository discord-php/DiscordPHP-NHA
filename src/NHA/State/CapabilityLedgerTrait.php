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
 * {@see \NHA\StateStore} slice: the **capability ledger** — one table of "this
 * exact action was refused, and here is the class of reason" so the brain stops
 * re-attempting (and stops *holding for*) something it cannot currently do.
 *
 * Fed from the world's own `GET /agent/{id}.recent` feed by
 * {@see \NHA\Brain\AutoPlayer}: each rejected `act` there is run through
 * {@see \NHA\Brain\RejectionClassifier} and, when the class is durable, landed
 * here. Each class carries its own invalidation:
 *
 *  - `needs_part` / `capability` — a hull limitation `finalize` cannot amend →
 *    cleared when a new `finalize` applies ({@see clearCapabilityClass()}).
 *  - `needs_item` — a held consumable is missing → cleared when the item is
 *    seen in inventory ({@see clearCapabilitiesWithItem()}).
 *  - `needs_enum` — a bad enum arg (wrong `construct` module, unknown `build`
 *    part) → sticky for the run; the ladder just picks a valid value.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.3.0
 */
trait CapabilityLedgerTrait
{
    /**
     * Record (or refresh) a blocked capability.
     *
     * @param string $key    stable id — `depart:deimos`, `construct:module`, `build:thruster`
     * @param string $class  `needs_part` | `capability` | `needs_item` | `needs_enum`
     * @param string $reason the engine's human string, kept for the prompt / logs
     * @param string $item   for `needs_item`: the inventory key whose presence clears this
     */
    public function recordCapability(int $agent_id, string $key, string $class, string $reason, int $tick, string $item = ''): void
    {
        $this->data['agent_capabilities'][(string) $agent_id][$key] = array_filter([
            'class' => $class,
            'reason' => $reason,
            'tick' => $tick,
            'item' => $item,
        ], static fn($v): bool => $v !== '' && $v !== null);
        $this->save();
    }

    /** Whether `$key` is currently blocked. */
    public function capabilityBlocked(int $agent_id, string $key): bool
    {
        return isset($this->data['agent_capabilities'][(string) $agent_id][$key]);
    }

    /** The stored reason string for a blocked `$key`, or `null`. */
    public function capabilityReason(int $agent_id, string $key): ?string
    {
        return $this->data['agent_capabilities'][(string) $agent_id][$key]['reason'] ?? null;
    }

    /**
     * Every blocked key that starts with `"{$prefix}:"`, without the prefix —
     * so `capabilityTargets($id, 'depart')` → `['deimos', 'phobos']`.
     *
     * @return list<string>
     */
    public function capabilityTargets(int $agent_id, string $prefix): array
    {
        $out = [];
        foreach (array_keys((array) ($this->data['agent_capabilities'][(string) $agent_id] ?? [])) as $key) {
            if (str_starts_with((string) $key, "{$prefix}:")) {
                $out[] = substr((string) $key, strlen($prefix) + 1);
            }
        }

        return $out;
    }

    /** The whole ledger for an agent (`key => {class, reason, …}`), for prompts / debug. */
    public function capabilities(int $agent_id): array
    {
        return (array) ($this->data['agent_capabilities'][(string) $agent_id] ?? []);
    }

    /** Drop every entry of a given class (e.g. `needs_part` on a fresh `finalize`). */
    public function clearCapabilityClass(int $agent_id, string $class): void
    {
        $key = (string) $agent_id;
        $before = $this->data['agent_capabilities'][$key] ?? [];
        $after = array_filter($before, static fn(array $e): bool => ($e['class'] ?? '') !== $class);
        if ($after !== $before) {
            $this->data['agent_capabilities'][$key] = $after;
            $this->save();
        }
    }

    /**
     * Drop every `needs_item` entry whose gating item is now on hand.
     *
     * @param array<string,int|float> $inventory the observation's inventory map
     */
    public function clearCapabilitiesWithItem(int $agent_id, array $inventory): void
    {
        $key = (string) $agent_id;
        $before = $this->data['agent_capabilities'][$key] ?? [];
        $after = array_filter($before, static function (array $e) use ($inventory): bool {
            $item = (string) ($e['item'] ?? '');

            return ! (($e['class'] ?? '') === 'needs_item' && $item !== '' && (int) ($inventory[$item] ?? 0) > 0);
        });
        if ($after !== $before) {
            $this->data['agent_capabilities'][$key] = $after;
            $this->save();
        }
    }

    /** The highest activity-feed tick already folded into the ledger. */
    public function capabilityReviewTick(int $agent_id): int
    {
        return (int) ($this->data['agent_capability_review_tick'][(string) $agent_id] ?? 0);
    }

    public function setCapabilityReviewTick(int $agent_id, int $tick): void
    {
        if ($tick <= $this->capabilityReviewTick($agent_id)) {
            return;
        }
        $this->data['agent_capability_review_tick'][(string) $agent_id] = $tick;
        $this->save();
    }
}
