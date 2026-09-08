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
 * {@see \NHA\StateStore} slice: the brain's decision record — the last decision
 * per agent (verb, args, rationale, `queued_intent` id) plus a rolling 24-turn
 * history so {@see \NHA\Brain\AutoPlayer::detectLoop()} and the prompt can see
 * repetition beyond the last turn.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait DecisionLogTrait
{
    /** How many past decisions the rolling history keeps. */
    private const DECISION_LOG_CAP = 24;

    /**
     * Records the brain's most recent decision for an agent (verb, args, the
     * one-line rationale and the `queued_intent` id it produced), so a later
     * command can show "what did the bot last do, and did it land?".
     *
     * @param int                  $agent_id
     * @param array<string, mixed> $decision Expects keys: verb, args, reason, queued_intent, tick.
     */
    public function recordDecision(int $agent_id, array $decision): void
    {
        $entry = [
            'verb' => (string) ($decision['verb'] ?? ''),
            'args' => (array) ($decision['args'] ?? []),
            'reason' => (string) ($decision['reason'] ?? ''),
            'queued_intent' => isset($decision['queued_intent']) ? (int) $decision['queued_intent'] : null,
            'tick' => isset($decision['tick']) ? (int) $decision['tick'] : null,
            'alt' => isset($decision['alt']) ? (int) $decision['alt'] : null,
            'at' => time(),
        ];

        $this->data['agent_decisions'][(string) $agent_id] = $entry;

        // Also keep a rolling history so the brain (and the loop detector in
        // AutoPlayer) can see repetition beyond just the last turn.
        $log = (array) ($this->data['agent_decision_log'][(string) $agent_id] ?? []);
        $log[] = ['verb' => $entry['verb'], 'args' => $entry['args'], 'tick' => $entry['tick'], 'alt' => $entry['alt']];
        $this->data['agent_decision_log'][(string) $agent_id] = array_slice($log, -self::DECISION_LOG_CAP);

        $this->save();
    }

    /**
     * Drops the stored `queued_intent` id from an agent's last decision once its
     * outcome is settled (`applied` / `rejected`) or it has aged out (`gone`),
     * so the autoplay loop stops issuing `GET /intent/{id}` for it every turn.
     *
     * @param int      $agent_id
     * @param int|null $only     When given, only clears the id if it still matches —
     *                           a no-op if {@see recordDecision()} has since stored a
     *                           newer intent, avoiding a lost update.
     *
     * @since 3.1.5
     */
    public function clearQueuedIntent(int $agent_id, ?int $only = null): void
    {
        $key = (string) $agent_id;
        $entry = $this->data['agent_decisions'][$key] ?? null;

        if (! is_array($entry) || ! isset($entry['queued_intent'])) {
            return;
        }
        if ($only !== null && (int) $entry['queued_intent'] !== $only) {
            return;
        }

        $this->data['agent_decisions'][$key]['queued_intent'] = null;
        $this->save();
    }

    /**
     * Gets the brain's last recorded decision for an agent, if any.
     *
     * @return array{verb: string, args: array, reason: string, queued_intent: ?int, tick: ?int, at: int}|null
     */
    public function getLastDecision(int $agent_id): ?array
    {
        $entry = $this->data['agent_decisions'][(string) $agent_id] ?? null;
        if (! is_array($entry) || ! isset($entry['verb'])) {
            return null;
        }

        return [
            'verb' => (string) $entry['verb'],
            'args' => (array) ($entry['args'] ?? []),
            'reason' => (string) ($entry['reason'] ?? ''),
            'queued_intent' => isset($entry['queued_intent']) ? (int) $entry['queued_intent'] : null,
            'tick' => isset($entry['tick']) ? (int) $entry['tick'] : null,
            'at' => (int) ($entry['at'] ?? 0),
        ];
    }

    /**
     * The agent's last few decisions, oldest first — a rolling window so the
     * brain can detect a loop or an already-tried `combine` pair. Each entry is
     * `{verb, args, tick}`; older/other fields are not kept here.
     *
     * @return list<array{verb: string, args: array, tick: ?int}>
     */
    public function getRecentDecisions(int $agent_id, int $limit = 10): array
    {
        $log = (array) ($this->data['agent_decision_log'][(string) $agent_id] ?? []);
        $out = [];
        foreach (array_slice($log, -max(1, $limit)) as $e) {
            if (! is_array($e) || ! isset($e['verb'])) {
                continue;
            }
            $out[] = [
                'verb' => (string) $e['verb'],
                'args' => (array) ($e['args'] ?? []),
                'tick' => isset($e['tick']) ? (int) $e['tick'] : null,
                'alt' => isset($e['alt']) ? (int) $e['alt'] : null,
            ];
        }

        return $out;
    }
}
