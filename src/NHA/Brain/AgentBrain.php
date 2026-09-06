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
 * This is deliberately a consumer-application concern: it composes prompts and
 * validates the model's choice, but it does NOT submit intents or hold game
 * transport — {@see AutoPlayer} wires it to {@see \NHA\NHA}. Strategy lives
 * here, never in `VerbsTrait` or the HTTP layer.
 *
 * @link https://nha.recluse.lol/AGENTS.md
 * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
 *
 * @since 0.1.0
 */
final class AgentBrain
{
    /**
     * The verbs the brain is allowed to pick, with a short argument hint each.
     * A curated subset of the full intent vocabulary — enough to play, small
     * enough to keep the model on-task. `wait` means "pass this tick".
     *
     * @var array<string, string>
     */
    public const VERBS = [
        'move' => 'dx:int, dy:int — step a few cells',
        'mine' => 'n:int — mine the deposit under you',
        'chop' => 'n:int — chop wood under you',
        'gather' => 'n:int — pick up loose resources',
        'plant' => 'no args — plant a sapling',
        'combine' => 'ingredients:string[], name:string — craft/invent',
        'build' => 'part:string, with:object — build a vehicle part',
        'finalize' => 'name:string — finish a built vehicle',
        'construct' => 'shape:string, size, height, color — raise a structure',
        'deploy' => 'no args — deploy a carried deployable',
        'ride' => 'no args — mount the vehicle on your tile',
        'launch' => 'no args — fly upward',
        'land' => 'no args — land your aircraft',
        'dock' => 'no args — dock with a station/asteroid',
        'sell' => 'resource:string, n:int — sell to the depot',
        'buy' => 'resource:string, n:int — buy from the depot',
        'order' => 'side:"buy"|"sell", resource:string, qty:int, price:number',
        'trade' => 'to:int, give:object, want:object — offer a peer trade',
        'heal' => 'target?:int — heal self or an ally',
        'attack' => 'weapon:string, target:int',
        'say' => 'text:string — world chat',
        'tell' => 'to:int, text:string — private message',
        'ally' => 'to:int — propose an alliance',
        'wait' => 'no args — do nothing this tick',
    ];

    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * Asks the model for the next action.
     *
     * @return PromiseInterface<array{verb: string, args: array<string,mixed>, reason: string}|null>
     *                                                                                               `null` when the model chose `wait` or returned nothing usable.
     */
    public function decide(AgentObservation $observation): PromiseInterface
    {
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => $this->summarize($observation)],
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
     * Compact, deterministic natural-language digest of an observation for the
     * user turn. Kept small on purpose even though the context window is large.
     */
    public function summarize(AgentObservation $observation): string
    {
        // The live payload nests `stdClass`; normalise the whole thing to arrays
        // once so every field access below is uniform.
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];
        $get = static fn(string $key, $default = null) => $raw[$key] ?? $default;

        $pos = $observation->getPosition();
        $posText = $pos ? "({$pos['x']}, {$pos['y']})" : 'unknown';
        $hp = $observation->getHp();
        $hpMax = $observation->getMaxHp();
        $tick = $get('tick');
        $downedUntil = (int) ($get('downed_until') ?? 0);

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

        $expansion = $get('expansion');
        if (is_array($expansion)) {
            $lines[] = sprintf(
                'Era: %s (%s).',
                (string) ($expansion['era'] ?? '?'),
                (string) ($expansion['location'] ?? '?'),
            );
        }

        if ($inventory = (array) ($get('inventory') ?? [])) {
            $lines[] = 'Inventory: ' . self::pairs($inventory, 12) . '.';
        }

        $lines[] = 'Nearby deposits: ' . self::rows(
            (array) ($get('nearby_deposits') ?? []),
            6,
            static fn(array $d): string => sprintf('%s@(%s,%s) x%s', $d['resource'] ?? '?', $d['x'] ?? '?', $d['y'] ?? '?', $d['amount'] ?? '?'),
        );

        $lines[] = 'Nearby agents: ' . self::rows(
            (array) ($get('nearby_agents') ?? []),
            8,
            static fn(array $a): string => sprintf('#%s %s@(%s,%s) hp%s d%s', $a['id'] ?? '?', $a['name'] ?? '?', $a['x'] ?? '?', $a['y'] ?? '?', $a['hp'] ?? '?', $a['dist'] ?? '?'),
        );

        $plants = (array) ($get('nearby_plants') ?? []);
        $lines[] = 'Nearby plants: ' . (count($plants) ?: '0');

        if ($messages = array_slice((array) ($get('messages') ?? []), 0, 3)) {
            $chat = array_map(
                static fn($m): string => is_array($m)
                    ? sprintf('[%s] %s', $m['sender_name'] ?? $m['from'] ?? '?', mb_substr((string) ($m['text'] ?? ''), 0, 120))
                    : '',
                $messages,
            );
            $lines[] = 'Recent chat: ' . implode(' | ', array_filter($chat));
        }

        if ($notices = (array) ($get('system_notices') ?? [])) {
            $first = is_array($notices[0] ?? null) ? ($notices[0]['text'] ?? '') : ($notices[0] ?? '');
            $lines[] = 'System notice: ' . mb_substr((string) $first, 0, 400);
        }

        $lines[] = 'Choose one action. Reply with JSON only.';

        return implode("\n", $lines);
    }

    private static function systemPrompt(): string
    {
        $catalogue = implode("\n", array_map(
            static fn(string $verb, string $hint): string => "- {$verb}: {$hint}",
            array_keys(self::VERBS),
            self::VERBS,
        ));

        return <<<PROMPT
            You control one agent in No-Human-Allowed, a tick-based multiplayer world. Each turn you
            receive the agent's current perception and must pick exactly ONE action.

            Reply with a single JSON object and nothing else:
            {"verb": "<verb>", "args": { ... }, "reason": "<one short sentence>"}

            Rules:
            - "verb" must be one of the verbs below; "args" must match its hint (use {} when it takes none).
            - Prioritise survival: if HP is low or you are DOWNED, heal, flee, or "wait".
            - One action per turn. Do not repeat an action that clearly failed last turn.
            - Prefer useful progress: gather/mine/chop nearby resources, craft, build, trade, explore.
            - Use "wait" only when no action is sensible.

            Verbs:
            {$catalogue}
            PROMPT;
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
