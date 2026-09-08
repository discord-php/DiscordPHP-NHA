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

use NHA\Parts\AgentObservation;

/**
 * Builds the model's *user* turn — a compact, deterministic digest of one
 * {@see AgentObservation} plus the carry-over context ({@see AutoPlayer} feeds
 * last turn's decision, its outcome, the recent-history window, the combine
 * bookkeeping and any forced loop-break objective).
 *
 * The *system* turn (strategy) is {@see Playbook::systemPrompt()}; the two are
 * assembled in {@see AgentBrain::decide()}. Split out of {@see AgentBrain} so the
 * ~300 lines of string assembly here stay clear of the decision/parse logic.
 *
 * Pure formatting — no game transport, no side effects. The one collaborator is
 * {@see Ladder::suggestion()}, whose pick is surfaced as the "SUGGESTED next
 * action" line to anchor a weak model.
 *
 * @since 3.1.30
 */
final class PromptBuilder
{
    /**
     * Compact, deterministic digest of an observation for the model's user turn.
     * Surfaces every field the {@see Playbook} decision ladder needs — score,
     * holdings, economy/social boards, threats, the expansion board — while
     * staying terse.
     *
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn, if known.
     */
    public static function build(AgentObservation $observation, ?array $lastDecision = null): string
    {
        // The live payload nests `stdClass`; normalise the whole thing to arrays
        // once so every field access below is uniform.
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];
        $get = static fn(string $key, $default = null) => $raw[$key] ?? $default;
        $arr = static fn(string $key): array => (array) ($raw[$key] ?? []);
        $count = static fn(string $key): int => count((array) ($raw[$key] ?? []));

        $pos = $observation->getPosition();
        $posText = $pos ? "({$pos['x']}, {$pos['y']})" : 'unknown';
        $hp = $observation->getHp();
        $hpMax = $observation->getMaxHp();
        $tick = $get('tick');
        $downedUntil = (int) ($get('downed_until') ?? 0);
        $inventory = $arr('inventory');

        $lines = [];
        $lines[] = sprintf('You are NHA agent #%d at tick %s.', $observation->agentId, $tick ?? '?');
        $lines[] = sprintf(
            'Position: %s. HP: %s/%s.%s Altitude: %s. In space: %s.',
            $posText,
            $hp === null ? '?' : (string) $hp,
            (string) $hpMax,
            $downedUntil > (int) ($tick ?? 0) ? " DOWNED until tick {$downedUntil}." : '',
            (string) ($get('altitude') ?? 0),
            $get('in_space') ? 'yes' : 'no',
        );
        $lines[] = sprintf(
            'Score: inventor_points %s, credits %s.',
            (string) ($get('inventor_points') ?? 0),
            (string) ($inventory['credits'] ?? 0),
        );

        $expansion = $get('expansion');
        if (is_array($expansion)) {
            $lines[] = sprintf(
                'Era: %s (%s).',
                (string) ($expansion['era'] ?? '?'),
                (string) ($expansion['location'] ?? '?'),
            );
            $atBody = $expansion['at_body'] ?? null;
            $windows = (array) ($expansion['windows'] ?? []);
            $open = [];
            foreach ($windows as $body => $w) {
                $open[] = is_array($w) && ($w['open'] ?? false)
                    ? "{$body} OPEN"
                    : "{$body} in " . (is_array($w) ? (string) ($w['opens_in'] ?? '?') : '?');
            }
            $lines[] = 'Expansion: at_body=' . ($atBody ?? 'none')
                . ($open === [] ? '' : '; transit windows: ' . implode(', ', $open))
                . (isset($expansion['how']) ? "\n  how: " . mb_substr((string) $expansion['how'], 0, 400) : '');
        }

        if ($inventory) {
            $lines[] = 'Inventory: ' . self::pairs($inventory, 14) . '.';
        }

        // Holdings that gate finalize / deploy / space / combat decisions.
        $holdings = [];
        if ($lp = $count('loose_parts')) {
            $holdings[] = "loose_parts x{$lp} (finalize-able)";
        }
        if ($veh = $arr('vehicles')) {
            $holdings[] = 'vehicles: ' . implode(', ', array_map(
                static fn($v): string => is_array($v) ? (string) ($v['name'] ?? 'vehicle') : 'vehicle',
                array_slice($veh, 0, 4),
            ));
        }
        if ($w = array_filter($arr('weapons'))) {
            $holdings[] = 'weapons: ' . self::pairs($w, 4);
        }
        if ($m = array_filter($arr('medicines'))) {
            $holdings[] = 'medicines: ' . self::pairs($m, 4);
        }
        foreach (['buff', 'toxin'] as $k) {
            if (is_array($get($k))) {
                $holdings[] = $k . ' active';
            }
        }
        if ($holdings) {
            $lines[] = 'Holdings: ' . implode('; ', $holdings) . '.';
        }

        $lines[] = 'Nearby deposits: ' . self::rows(
            $arr('nearby_deposits'),
            6,
            static fn(array $d): string => sprintf('%s@(%s,%s) x%s', $d['resource'] ?? '?', $d['x'] ?? '?', $d['y'] ?? '?', $d['amount'] ?? '?'),
        );

        $lines[] = 'Nearby agents: ' . self::rows(
            $arr('nearby_agents'),
            8,
            static fn(array $a): string => sprintf(
                '#%s %s@(%s,%s) hp%s d%s%s',
                $a['id'] ?? '?',
                $a['name'] ?? '?',
                $a['x'] ?? '?',
                $a['y'] ?? '?',
                $a['hp'] ?? '?',
                $a['dist'] ?? '?',
                ($a['wanted'] ?? false) ? ' WANTED' : '',
            ),
        );

        $lines[] = 'Nearby plants: ' . ($count('nearby_plants') ?: '0')
            . '. Structures: ' . ($count('nearby_structures') ?: '0')
            . '. Loot piles: ' . ($count('loot') ?: '0')
            . '. Artifacts: ' . ($count('artifacts') ?: '0')
            . '. Asteroids: ' . ($count('asteroids') ?: '0') . '.';

        if ($elevators = $arr('elevators')) {
            $e = (array) $elevators[0];
            $lines[] = sprintf(
                'Nearest elevator: (%s,%s) height %s, dist %s%s.',
                $e['x'] ?? '?',
                $e['y'] ?? '?',
                $e['height'] ?? '?',
                $e['dist'] ?? '?',
                count($elevators) > 1 ? ' (+' . (count($elevators) - 1) . ' more)' : '',
            );
        }

        $econ = array_filter([
            $count('contracts') ? 'open contracts ' . $count('contracts') : null,
            $count('trade_offers') ? 'trade offers ' . $count('trade_offers') : null,
            $count('bounties') ? 'bounties ' . $count('bounties') : null,
            ($ot = (int) ($get('orders_total') ?? 0)) ? "your open orders {$ot}" : null,
        ]);
        if ($econ) {
            $lines[] = 'Economy/social: ' . implode(', ', $econ) . '.';
        }

        // Threat awareness.
        $threat = array_filter([
            $count('alerts') ? $count('alerts') . ' recent alert(s) where you were the victim' : null,
            $get('last_robbed_by') ? 'last robbed by #' . $get('last_robbed_by') : null,
        ]);
        if ($threat) {
            $lines[] = 'Threats: ' . implode('; ', $threat) . '.';
        }

        if ($messages = array_slice($arr('messages'), 0, 3)) {
            $chat = array_map(
                static fn($m): string => is_array($m)
                    ? sprintf('[%s] %s', $m['sender_name'] ?? $m['from'] ?? '?', mb_substr((string) ($m['text'] ?? ''), 0, 120))
                    : '',
                $messages,
            );
            $lines[] = 'Recent chat: ' . implode(' | ', array_filter($chat));
        }

        if ($notices = $arr('system_notices')) {
            $first = is_array($notices[0] ?? null) ? ($notices[0]['text'] ?? '') : ($notices[0] ?? '');
            $lines[] = 'System notice: ' . mb_substr((string) $first, 0, 400);
        }

        if ($lastDecision !== null && ($lastDecision['verb'] ?? '') !== '') {
            $lastVerb = (string) $lastDecision['verb'];
            $lastArgs = $lastDecision['args'] ?? [];
            $harvested = in_array($lastVerb, ['mine', 'chop', 'gather'], true);

            // Outcome of last turn's queued intent, if AutoPlayer polled it.
            $outcome = is_array($lastDecision['outcome'] ?? null) ? $lastDecision['outcome'] : null;
            $status = $outcome !== null ? (string) ($outcome['status'] ?? '') : '';
            $result = $outcome !== null ? trim((string) ($outcome['result'] ?? '')) : '';

            if ($status === 'rejected') {
                $tail = 'It was REJECTED' . ($result !== '' ? ": \"{$result}\"" : '')
                    . ". Do NOT try that again — pick a DIFFERENT verb or different args.";
            } elseif ($status === 'applied') {
                $craftTail = $lastVerb === 'combine'
                    ? ' A combine only SCORES if it invented something new; if inventor_points did not rise, that set is'
                        . ' spent — never submit it again.'
                    : " Move on — do not repeat {$lastVerb} unless it is still clearly the right call.";
                $tail = 'It APPLIED' . ($result !== '' ? ": \"{$result}\"" : '') . '.'
                    . ($harvested
                        ? ' You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : $craftTail);
            } else {
                // Still pending / unknown — the pre-outcome guidance.
                $tail = 'It is queued (result not in yet). '
                    . ($harvested
                        ? 'You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : "Do not repeat {$lastVerb} unless it is still clearly the right call (e.g. still moving toward a target).");
            }

            // Only the verb, args and the server's own outcome are echoed back —
            // never the previous turn's free-text `reason`. A model paraphrase
            // like "I have 9 wood" that landed in one turn's reason would
            // otherwise be replayed verbatim every subsequent turn and read as
            // fact, even after the real inventory (below) has moved on.
            $lines[] = sprintf(
                'Last turn: you chose %s%s. %s',
                $lastVerb,
                $lastArgs === [] ? '' : ' ' . json_encode($lastArgs, JSON_UNESCAPED_SLASHES),
                $tail,
            );
        }

        // Loop guard fired: the loop runner has detected repetition and forced a
        // new objective for this turn. Tell the model plainly.
        $forcedObjective = (string) ($lastDecision['forced_objective'] ?? '');
        if ($forcedObjective !== '') {
            $lines[] = sprintf(
                'LOOP DETECTED (%s). You are stuck. This turn your objective is **%s** — do that and nothing resembling the repeated action.',
                (string) ($lastDecision['loop'] ?? 'repetition'),
                $forcedObjective,
            );
        }

        // Rolling history + a deterministic suggestion. A small local model
        // loops badly on the 7-rung ladder alone; showing it what it already
        // did and one concrete recommended move keeps it productive.
        $recent = is_array($lastDecision['recent'] ?? null) ? $lastDecision['recent'] : [];
        $tried = [];

        // The full set of combine signatures already submitted this run (survives
        // the 8-turn recent window), fed in by AutoPlayer from durable state.
        foreach (is_array($lastDecision['tried_combines'] ?? null) ? $lastDecision['tried_combines'] : [] as $sig) {
            if (is_string($sig) && $sig !== '') {
                $tried[$sig] = true;
            }
        }

        if ($recent !== []) {
            $hist = [];
            foreach ($recent as $r) {
                $v = (string) ($r['verb'] ?? '');
                if ($v === '') {
                    continue;
                }
                $a = (array) ($r['args'] ?? []);
                if ($v === 'combine') {
                    $ing = array_keys((array) ($a['ingredients'] ?? []));
                    sort($ing);
                    if ($ing !== []) {
                        $tried[implode('+', $ing)] = true;
                    }
                }
                $hist[] = $v . ($a === [] ? '' : self::compactArgs($a));
            }
            if ($hist !== []) {
                $lines[] = 'Recent turns (oldest→newest): ' . implode(' → ', array_slice($hist, -8));
            }
        }

        if ($tried !== []) {
            $lines[] = 'combine sets already submitted this session (do NOT resubmit — a repeat mints nothing): '
                . implode(', ', array_slice(array_keys($tried), -40)) . '.';
        }

        $dead = array_values(array_filter(
            is_array($lastDecision['dead_combines'] ?? null) ? $lastDecision['dead_combines'] : [],
            'is_string',
        ));
        if ($dead !== []) {
            $lines[] = 'combine sets the Inventors\' Guild has REJECTED — proven to make nothing, NEVER pick these again: '
                . implode(', ', array_slice($dead, -40)) . '.';
        }

        // Combine sets the whole world has ALREADY invented (from /rules) that
        // you could make right now with what you hold — these mint no points, so
        // skip them; anything else is potentially novel.
        $knownCombines = array_values(array_filter(
            is_array($lastDecision['known_combines'] ?? null) ? $lastDecision['known_combines'] : [],
            'is_string',
        ));
        $invNames = array_keys(array_filter(
            (array) ($raw['inventory'] ?? []),
            static fn($qty, $k): bool => $k !== 'credits' && is_numeric($qty) && $qty > 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        $makeableKnown = array_values(array_filter(
            $knownCombines,
            static fn(string $sig): bool => array_diff(explode('+', $sig), $invNames) === [],
        ));
        if ($makeableKnown !== []) {
            $lines[] = 'Already-invented combine sets you could make now (mint nothing — do NOT pick these): '
                . implode(', ', array_slice($makeableKnown, 0, 20)) . '.';
        }

        if ($invNames !== []) {
            $lines[] = 'You may ONLY combine/build from what you hold (qty ≥ 1): ' . implode(', ', $invNames)
                . '. Anything else — iron, chip, composite, … at qty 0 — will be rejected.';
        }
        if (($raw['inventory']['composite_material'] ?? 0) > 0 && ($raw['inventory']['composite'] ?? 0) === 0) {
            $lines[] = 'NOTE: you hold `composite_material`, NOT `composite`. A `construct` tower needs `composite` '
                . '(aluminium + carbon) — you cannot build one yet, so do not keep trying.';
        }

        if ($suggestion = Ladder::suggestion($raw, $tried, array_fill_keys($knownCombines, true))) {
            $lines[] = sprintf(
                'SUGGESTED next action: %s%s — %s. Do this unless you clearly see something better.',
                $suggestion['verb'],
                $suggestion['args'] === [] ? '' : ' ' . json_encode($suggestion['args'], JSON_UNESCAPED_SLASHES),
                $suggestion['why'],
            );
        }

        $lines[] = 'Choose one action. Reply with JSON only.';

        return implode("\n", $lines);
    }

    /**
     * Compact `{"k":v,...}` rendering for the recent-turns line — short enough to
     * list eight of them without bloating the prompt.
     *
     * @param array<string,mixed> $args
     */
    private static function compactArgs(array $args): string
    {
        $flat = [];
        foreach ($args as $k => $v) {
            $flat[] = $k . '=' . (is_array($v) ? implode('/', array_map('strval', array_keys($v))) : (string) $v);
        }

        return '{' . implode(',', $flat) . '}';
    }

    /** @param array<string,mixed> $map */
    private static function pairs(array $map, int $limit): string
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (count($out) >= $limit) {
                $out[] = '…';
                break;
            }
            $out[] = "{$k}:" . (is_scalar($v) ? (string) $v : json_encode($v));
        }

        return implode(', ', $out);
    }

    /**
     * @param array<int,mixed>        $rows
     * @param callable(array): string $format
     */
    private static function rows(array $rows, int $limit, callable $format): string
    {
        if ($rows === []) {
            return 'none';
        }

        $shown = array_slice($rows, 0, $limit);
        $parts = array_map(
            static fn($row): string => $format(is_array($row) ? $row : (array) $row),
            $shown,
        );
        $extra = count($rows) - count($shown);

        return implode('; ', $parts) . ($extra > 0 ? " (+{$extra} more)" : '');
    }
}
