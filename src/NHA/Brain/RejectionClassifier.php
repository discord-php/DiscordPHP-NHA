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

namespace NHA\Brain;

/**
 * Turns one rejected `act` from the world's `GET /agent/{id}.recent` feed into a
 * capability-ledger entry, or `null` when the rejection is transient (a timing
 * race, a one-turn stock shortage) and should NOT gate a retry.
 *
 * The point: instead of every verb growing its own bespoke skip-list, one place
 * reads the engine's human reason string and assigns a **class** whose
 * invalidation rule the {@see \NHA\State\CapabilityLedgerTrait} knows:
 *
 *  - `needs_part`   — a part `finalize` can't add to a built hull (`landing_gear`).
 *  - `capability`   — a hull limit it can never meet (thrust-to-weight).
 *  - `needs_item`   — a held consumable is missing (`acid_skin` for Venus).
 *  - `needs_enum`   — a bad enum arg (wrong `construct` module / unknown part).
 *
 * Transient (→ `null`, no ledger entry): "window is CLOSED", "already in
 * transit", "not enough X", "insufficient", "you were … short".
 *
 * @since 3.3.0
 */
final class RejectionClassifier
{
    /** Substrings that mean "try again later", never "can't do this". */
    private const TRANSIENT = [
        'window is closed', 'opens again', 'already in transit', 'already at',
        'not enough', 'insufficient', 'short this turn', 'need more', 'too low',
        'loop detected', 'no loose parts', 'downed', 'on cooldown',
    ];

    /**
     * @param array<string,mixed> $args   the intent's args (`dest`, `module`, `part`, …)
     * @param string              $result the engine's rejection string
     *
     * @return array{key: string, class: string, reason: string, item: string}|null
     */
    public static function classify(string $verb, array $args, string $result): ?array
    {
        $verb = strtolower(trim($verb));
        $why = strtolower(trim($result));
        if ($why === '') {
            return null;
        }
        foreach (self::TRANSIENT as $t) {
            if (str_contains($why, $t)) {
                return null;
            }
        }

        $entry = static fn(string $key, string $class, string $item = ''): array => [
            'key' => $key, 'class' => $class, 'reason' => $result, 'item' => $item,
        ];

        if ($verb === 'depart') {
            $dest = strtolower((string) ($args['dest'] ?? $args['body'] ?? ''));
            // The `GET /agent/{id}.recent` feed carries no args — recover the
            // destination from the engine's own reason string when it names one.
            if ($dest === '' && preg_match('/\b(deimos|phobos|mars|venus|luna|moon)\b/', $why, $b) === 1) {
                $dest = $b[1];
            }
            if ($dest === '' || $dest === 'earth') {
                return null;
            }
            if (str_contains($why, 'landing gear')) {
                return $entry("depart:{$dest}", 'needs_part');
            }
            if (str_contains($why, 'thrust/(mass') || str_contains($why, 'thrust-to-weight') || str_contains($why, 'ion_thruster (orbital drive)')) {
                return $entry("depart:{$dest}", 'capability');
            }
            foreach (['acid_skin', 'heat_shield'] as $item) {
                if (str_contains($why, $item)) {
                    return $entry("depart:{$dest}", 'needs_item', $item);
                }
            }

            return null;
        }

        // "<thing> must be one of: a/b/c" — a bad enum argument. Key by the arg
        // the engine named, so the ladder can pick a valid value.
        if (preg_match('/(\w+) must be one of/', $why, $m) === 1) {
            return $entry("{$verb}:{$m[1]}", 'needs_enum');
        }
        if (str_contains($why, 'unknown part')) {
            $part = strtolower((string) ($args['part'] ?? ''));

            return $part !== '' ? $entry("build:{$part}", 'needs_enum') : null;
        }

        return null;
    }
}
