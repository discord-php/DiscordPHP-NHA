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
use React\Promise\PromiseInterface;

/**
 * Turns one {@see AgentObservation} into a single game action by asking an LLM
 * (via {@see OllamaClient}) and parsing a strict JSON reply.
 *
 * This is deliberately a consumer-application concern: it assembles the prompt
 * (strategy in {@see Playbook}, the situation digest in {@see summarize()}) and
 * validates the model's choice, but it does NOT submit intents or hold game
 * transport — {@see AutoPlayer} wires it to {@see \NHA\NHA}.
 *
 * @link https://nha.recluse.lol/AGENTS.md
 * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
 *
 * @since 0.1.0
 */
final class AgentBrain
{
    /**
     * The verbs the brain may pick. Kept as an alias so existing
     * `AgentBrain::VERBS` callers keep working; the catalogue lives in
     * {@see Playbook::VERBS} alongside the strategy that references it.
     *
     * @var array<string, string>
     */
    public const VERBS = Playbook::VERBS;

    /**
     * @param OllamaClient $ollama The LLM client prompted for each decision.
     */
    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * Asks the model for the next action.
     *
     * @param AgentObservation                                                  $observation  This tick's perception.
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn's decision, if known —
     *                                                                                        shown to the model so it does not loop on a failed verb.
     *
     * @return PromiseInterface<array{verb: string, args: array<string,mixed>, reason: string}|null>
     *                                                                                               `null` when the model chose `wait` or returned nothing usable.
     */
    public function decide(AgentObservation $observation, ?array $lastDecision = null): PromiseInterface
    {
        $messages = [
            ['role' => 'system', 'content' => Playbook::systemPrompt()],
            ['role' => 'user', 'content' => $this->summarize($observation, $lastDecision)],
        ];

        return $this->ollama->chat($messages)->then(static fn(string $content) => self::parseDecision($content));
    }

    /**
     * Parses a model reply into a validated decision, or `null`.
     *
     * Tolerates ```json fences and leading prose; requires a known `verb`.
     *
     * @return array{verb: string, args: array<string,mixed>, reason: string}|null
     */
    public static function parseDecision(string $content): ?array
    {
        $json = trim($content);

        // Strip a Markdown code fence if the model added one despite format=json.
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;
        }

        // Fall back to the first {...} block if there is surrounding prose.
        if (! str_starts_with(ltrim($json), '{') && preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $verb = is_string($data['verb'] ?? null) ? strtolower(trim($data['verb'])) : '';
        if ($verb === '' || $verb === 'wait' || $verb === 'noop' || ! isset(self::VERBS[$verb])) {
            return null;
        }

        $args = $data['args'] ?? [];
        $args = is_array($args) ? $args : (array) $args;

        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

        return ['verb' => $verb, 'args' => $args, 'reason' => $reason];
    }

    /**
     * Compact, deterministic digest of an observation for the model's user turn.
     * Surfaces every field the {@see Playbook} decision ladder needs — score,
     * holdings, economy/social boards, threats, the expansion board — while
     * staying terse.
     *
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn, if known.
     */
    public function summarize(AgentObservation $observation, ?array $lastDecision = null): string
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
                $tail = 'It APPLIED' . ($result !== '' ? ": \"{$result}\"" : '') . '.'
                    . ($harvested
                        ? ' You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : " Move on — do not repeat {$lastVerb} unless it is still clearly the right call.");
            } else {
                // Still pending / unknown — the pre-outcome guidance.
                $tail = 'It is queued (result not in yet). '
                    . ($harvested
                        ? 'You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : "Do not repeat {$lastVerb} unless it is still clearly the right call (e.g. still moving toward a target).");
            }

            $lines[] = sprintf(
                'Last turn: you chose %s%s%s. %s',
                $lastVerb,
                $lastArgs === [] ? '' : ' ' . json_encode($lastArgs, JSON_UNESCAPED_SLASHES),
                ($lastDecision['reason'] ?? '') !== '' ? " (\"{$lastDecision['reason']}\")" : '',
                $tail,
            );
        }

        // Rolling history + a deterministic suggestion. A small local model
        // loops badly on the 7-rung ladder alone; showing it what it already
        // did and one concrete recommended move keeps it productive.
        $recent = is_array($lastDecision['recent'] ?? null) ? $lastDecision['recent'] : [];
        $tried = [];
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
            if ($tried !== []) {
                $lines[] = 'combine sets already submitted this session (do NOT resubmit — a repeat mints nothing): '
                    . implode(', ', array_keys($tried)) . '.';
            }
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

        if ($suggestion = $this->suggestion($raw, $tried, array_fill_keys($knownCombines, true))) {
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

    /**
     * A deterministic "what would the ladder do" pick, surfaced to anchor a weak
     * model. Mirrors {@see Playbook}'s priorities: finish parts → gamble one
     * novel combine → build for reliable points → sell a glut → harvest only
     * when short → reposition. Returns `null` when nothing is obviously right
     * (the model is then on its own).
     *
     * @param array<string,mixed> $raw        The normalised observation.
     * @param array<string,bool>  $tried      `a+b => true` for combine sets submitted THIS session.
     * @param array<string,bool>  $worldKnown `a+b => true` for sets the whole world has already invented.
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private function suggestion(array $raw, array $tried, array $worldKnown = []): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $points = (int) ($raw['inventor_points'] ?? 0);
        $tick = (int) ($raw['tick'] ?? 0);
        $onGround = ! ($raw['in_space'] ?? false) && (int) ($raw['altitude'] ?? 0) === 0;

        // Raw resources on hand (drop currency + obvious craft outputs).
        $skip = ['credits' => 1, 'engine' => 1, 'motor' => 1, 'chip' => 1, 'frame' => 1, 'fuel' => 1];
        $raws = [];
        foreach ($inv as $k => $qty) {
            if (! isset($skip[$k]) && is_numeric($qty) && $qty > 0) {
                $raws[(string) $k] = (int) $qty;
            }
        }
        arsort($raws);

        // 1. Assemble anything already crafted.
        if (count((array) ($raw['loose_parts'] ?? [])) > 0) {
            return ['verb' => 'finalize', 'args' => [], 'why' => 'you have loose parts — assemble them into a vehicle'];
        }

        // 2. One speculative combine: a pair of raws not submitted this session
        //    and not already invented world-wide, while it is still a cheap
        //    gamble (< 2 session tries, or points are already moving).
        if (count($raws) >= 2 && (count($tried) < 2 || $points > 0)) {
            $names = array_keys($raws);
            for ($i = 0; $i < count($names); $i++) {
                for ($j = $i + 1; $j < count($names); $j++) {
                    $pair = [$names[$i], $names[$j]];
                    sort($pair);
                    $sig = implode('+', $pair);
                    if (! isset($tried[$sig]) && ! isset($worldKnown[$sig])) {
                        return [
                            'verb' => 'combine',
                            'args' => ['ingredients' => [$pair[0] => 1, $pair[1] => 1]],
                            'why' => "{$pair[0]}+{$pair[1]} is an untried, uninvented tag set — one shot at inventor points",
                        ];
                    }
                }
            }
        }

        $biggest = $raws === [] ? null : array_key_first($raws);
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);

        // 3. Build for RELIABLE points — but a `construct` tower costs metal
        //    (= size) plus `composite` (= ceil(height/14)), and `composite` is
        //    aluminium+carbon, not something most ground agents hold. Only
        //    suggest it when the exact material is on hand.
        if ($onGround && $has('composite') >= 2 && $has('metal') >= 8) {
            $shape = ['box', 'cylinder', 'pyramid', 'cone', 'sphere'][$tick % 5];
            $size = min(8, $has('metal'));
            $height = 14 * min($has('composite'), 3);
            return [
                'verb' => 'construct',
                'args' => ['shape' => $shape, 'size' => $size, 'height' => $height, 'name' => 'spire-' . ($tick % 1000)],
                'why' => "you hold the composite + metal a tall {$shape} needs — builder points score every time",
            ];
        }

        // 4. Turn a genuine glut into credits (the depot buys raws from anywhere).
        if ($biggest !== null && $raws[$biggest] >= 30) {
            return ['verb' => 'sell', 'args' => ['resource' => $biggest, 'n' => 20], 'why' => "you are sitting on {$raws[$biggest]} {$biggest} with nothing to craft — sell 20 for credits"];
        }

        // 5. Harvest only a resource you are actually short on and standing on.
        foreach ((array) ($raw['nearby_deposits'] ?? []) as $d) {
            $d = (array) $d;
            $res = (string) ($d['resource'] ?? '');
            if ($res === '' || (int) ($d['dist'] ?? 9) !== 0) {
                continue;
            }
            if (($raws[$res] ?? 0) < 15) {
                $verb = $res === 'wood' ? 'chop' : (in_array($res, ['herb', 'lichen', 'fungus', 'algae'], true) ? 'gather' : 'mine');
                $n = min((int) ($d['amount'] ?? 10), 15);
                return ['verb' => $verb, 'args' => ['n' => $n], 'why' => "you hold only " . ($raws[$res] ?? 0) . " {$res} and are standing on a deposit"];
            }
        }

        // 6. On the ground with stock but nothing to do here — head for a finished
        //    elevator to `ride` to space, where funding the station pays points now.
        if ($onGround && $biggest !== null && $raws[$biggest] >= 15) {
            foreach ((array) ($raw['elevators'] ?? []) as $e) {
                $e = (array) $e;
                if (isset($e['x'], $e['y'])) {
                    return ['verb' => 'move', 'args' => ['x' => (int) $e['x'], 'y' => (int) $e['y']], 'why' => 'stocked up but idle here — walk to the elevator base to ride to space and build the station'];
                }
            }
        }

        // 7. Nothing here — head toward the nearest deposit of something scarce.
        foreach ((array) ($raw['nearby_deposits'] ?? []) as $d) {
            $d = (array) $d;
            $res = (string) ($d['resource'] ?? '');
            if ($res !== '' && ($raws[$res] ?? 0) < 10 && isset($d['x'], $d['y'])) {
                return ['verb' => 'move', 'args' => ['x' => (int) $d['x'], 'y' => (int) $d['y']], 'why' => "you are short on {$res} — walk to that deposit"];
            }
        }

        return null;
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
