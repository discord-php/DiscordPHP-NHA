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

use NHA\NHA;
use NHA\Parts\AgentObservation;
use NHA\StateStore;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Runs one observe → decide → act cycle for an agent: fetch the world, ask the
 * {@see AgentBrain}, and queue the chosen intent via {@see NHA::intentWithToken()}.
 *
 * A queued intent is only that — queued. This class records the `queued_intent`
 * id (via {@see StateStore::recordDecision()}) so the outcome can be polled
 * later; it never claims the action succeeded.
 *
 * Implements the agent loop described in the NHA agent guide.
 *
 * @link https://nha.recluse.lol/AGENTS.md Agent API reference (observe → decide → act loop)
 *
 * @since 3.0.0
 */
final class AutoPlayer
{
    /**
     * `a+b => true` for every combine set the world has already invented, from
     * `GET /rules`. Fetched once on the first turn and reused — the codex only
     * grows, and a stale entry never causes a wrong "already known" call.
     *
     * @var array<string, bool>|null
     */
    private ?array $knownCombines = null;

    /**
     * @param NHA        $nha   The NHA client used to observe and submit intents.
     * @param AgentBrain $brain Turns an observation into a `{verb, args, reason}` decision.
     * @param StateStore $state Durable store; also attached to `$nha` here so a standalone
     *                          player still records position on every observe.
     */
    public function __construct(
        private readonly NHA $nha,
        private readonly AgentBrain $brain,
        private readonly StateStore $state,
    ) {
        // Make the observe() → position write self-sufficient even when this
        // player is used without a Commands layer wiring the store.
        $this->nha->setStateStore($state);
    }

    /**
     * The set of already-invented `combine` signatures (`"herb+wood"`, …), so
     * the brain does not waste turns re-submitting a set that mints nothing.
     * Resolves to `[]` when the codex cannot be read.
     *
     * @return PromiseInterface<array<string, bool>>
     */
    private function knownCombines(): PromiseInterface
    {
        if ($this->knownCombines !== null) {
            return resolve($this->knownCombines);
        }

        return $this->nha->world->getRules()->then(
            function ($rules): array {
                $sigs = [];
                foreach ((array) ($rules['dynamic'] ?? []) as $entry) {
                    $entry = (array) $entry;
                    $sig = (string) ($entry['sig'] ?? '');
                    if ($sig === '') {
                        continue;
                    }
                    $tokens = array_map('trim', explode(',', $sig));
                    sort($tokens);
                    $sigs[implode('+', $tokens)] = true;
                }

                return $this->knownCombines = $sigs;
            },
            fn(): array => $this->knownCombines = [],
        );
    }

    /**
     * Executes a single turn.
     *
     * @param int    $agent_id
     * @param string $token    The agent's NHA action token. Empty falls back to
     *                         {@see NHA::getAgentToken()}.
     *
     * @return PromiseInterface<string> A human-readable status line.
     */
    /**
     * Executes one autoplay turn.
     *
     * @param int         $agent_id
     * @param string      $token         The agent's action token (empty → the ambient token).
     * @param string|null $lease         A per-process id for the driving loop. When set, the turn
     *                                   is skipped unless this process holds the autoplay lease
     *                                   (see {@see StateStore::acquireAutoplayLease()}), so
     *                                   `bot.php`'s loop and the headless runner never double-submit.
     *                                   Pass null for a one-off (`!nha think`), which is never gated.
     * @param int|null    $leaseInterval The driving loop's turn interval in seconds, used to size
     *                                   the lease TTL so it survives the gap between turns.
     */
    public function step(int $agent_id, string $token = '', ?string $lease = null, ?int $leaseInterval = null): PromiseInterface
    {
        if ($lease !== null && ! $this->state->acquireAutoplayLease($lease, $leaseInterval)) {
            return resolve(sprintf(
                '⏸️ Autoplay turn skipped — another driver (`%s`) holds the lease.',
                $this->state->autoplayLeaseHolder() ?? '?',
            ));
        }

        if ($token === '') {
            $token = $this->nha->getAgentToken();
        }

        // Fetch the outcome of the intent we queued last turn so the brain gets
        // real feedback ("combine iron+wood -> rejected: already known") instead
        // of blindly retrying it. Best-effort: a failed lookup just omits it.
        $last = $this->state->getLastDecision($agent_id);
        $lastQueued = ($last !== null && ! empty($last['queued_intent'])) ? (int) $last['queued_intent'] : null;
        $outcome = $lastQueued !== null
            ? $this->nha->intents->getIntentStatus($lastQueued)->then(
                function ($s) use ($agent_id, $lastQueued): array {
                    $status = (string) ($s->status ?? '?');

                    // Settled or aged out: forget the id so the next turn does
                    // not keep polling GET /intent/{id} for an answer that will
                    // never change.
                    if (in_array($status, ['applied', 'rejected', 'gone'], true)) {
                        $this->state->clearQueuedIntent($agent_id, $lastQueued);
                    }

                    return ['status' => $status, 'result' => (string) ($s->result ?? '')];
                },
                // A transient lookup failure: keep the id and retry next turn.
                static fn(): ?array => null,
            )
            : resolve(null);

        return all(['outcome' => $outcome, 'known' => $this->knownCombines()])->then(fn(array $pre) => $this->nha->observe($agent_id)->then(function (AgentObservation $observation) use ($agent_id, $token, $last, $pre) {
            $tick = (int) ($observation->get('tick') ?? 0);
            $downedUntil = (int) ($observation->get('downed_until') ?? 0);

            if ($downedUntil > $tick) {
                return resolve("🩹 Agent #{$agent_id} is downed until tick {$downedUntil} — skipping.");
            }

            $context = ($last ?? []);
            if (($pre['outcome'] ?? null) !== null) {
                $context['outcome'] = $pre['outcome'];
            }
            $context['recent'] = $this->state->getRecentDecisions($agent_id, 10);
            $context['known_combines'] = array_keys($pre['known'] ?? []);

            return $this->brain->decide($observation, $context ?: null)->then(function (?array $decision) use ($agent_id, $token, $tick) {
                if ($decision === null) {
                    return "💤 Agent #{$agent_id}: brain chose to wait (tick {$tick}).";
                }

                return $this->nha->intentWithToken($agent_id, $token, $decision['verb'], $decision['args'])
                    ->then(function ($queued) use ($agent_id, $decision, $tick) {
                        $queued = (array) $queued;
                        $queuedId = $queued['queued_intent'] ?? null;

                        $this->state->recordDecision($agent_id, [
                            'verb' => $decision['verb'],
                            'args' => $decision['args'],
                            'reason' => $decision['reason'],
                            'queued_intent' => $queuedId,
                            'tick' => $tick,
                        ]);

                        $ref = $queuedId !== null ? " (queued #{$queuedId})" : '';
                        $args = $decision['args'] === [] ? '' : ' ' . json_encode($decision['args'], JSON_UNESCAPED_SLASHES);
                        $reason = $decision['reason'] !== '' ? "\n> {$decision['reason']}" : '';

                        return "🤖 Agent #{$agent_id} → **{$decision['verb']}**{$args}{$ref}{$reason}";
                    });
            });
        }));
    }
}
