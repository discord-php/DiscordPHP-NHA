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
            $lastArgs = $lastDecision['args'] ?? [];
            $lines[] = sprintf(
                'Last turn: you chose %s%s%s — check this observation for whether it worked, and do NOT repeat it if it did nothing.',
                (string) $lastDecision['verb'],
                $lastArgs === [] ? '' : ' ' . json_encode($lastArgs, JSON_UNESCAPED_SLASHES),
                ($lastDecision['reason'] ?? '') !== '' ? " (\"{$lastDecision['reason']}\")" : '',
            );
        }

        $lines[] = 'Choose one action. Reply with JSON only.';

        return implode("\n", $lines);
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
