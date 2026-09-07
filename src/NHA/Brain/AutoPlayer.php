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
    /** Re-pull `GET /rules` at most this often (seconds) so sets invented mid-run enter the known list. */
    private const RULES_TTL = 90;

    /**
     * Known recipes that stay worth repeating — infrastructure intermediates,
     * not research. These are never blocked by the "already tried / world-known"
     * guardrail, because you re-craft them every time you want to build.
     *
     * @var array<string, true>
     *
     * @since 3.1.7
     */
    private const PRODUCTION_COMBINES = [
        'aluminium+carbon' => true, // → composite, the `construct` gate
        'aluminum+carbon' => true,  // US spelling of the same
    ];

    /**
     * `a+b => true` for every combine set the world has already invented, from
     * `GET /rules`, plus any set this loop has since seen a `combine` APPLY for.
     * Refreshed every {@see self::RULES_TTL}s — the codex only grows, so a stale
     * entry is never wrong, but a missing fresh one makes the brain re-try a set
     * that now mints nothing.
     *
     * @var array<string, bool>|null
     */
    private ?array $knownCombines = null;

    /** `microtime(true)` of the last successful `GET /rules`, for the TTL above. */
    private float $knownFetchedAt = 0.0;

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
        if ($this->knownCombines !== null && (microtime(true) - $this->knownFetchedAt) < self::RULES_TTL) {
            return resolve($this->knownCombines);
        }

        return $this->nha->world->getRules()->then(
            function ($rules): array {
                // Keep anything already merged from an APPLY this run — the codex
                // only grows, so a union can never be wrong.
                $sigs = $this->knownCombines ?? [];
                foreach ((array) ($rules['dynamic'] ?? []) as $entry) {
                    $entry = (array) $entry;
                    $sig = self::signatureFromList((string) ($entry['sig'] ?? ''));
                    if ($sig !== '') {
                        $sigs[$sig] = true;
                    }
                }

                $this->knownFetchedAt = microtime(true);

                return $this->knownCombines = $sigs;
            },
            fn(): array => $this->knownCombines ?? ($this->knownCombines = []),
        );
    }

    /**
     * The canonical signature for a `combine` — its ingredient keys, trimmed,
     * de-duplicated and sorted, joined with `+` (`{iron:1, wood:2}` → `iron+wood`).
     * The world resolves a combine on the SET of tags, so amounts and order do
     * not matter. Returns `''` when there are no ingredients.
     *
     * @param array<string, mixed> $args
     */
    public static function combineSignature(array $args): string
    {
        $ingredients = array_keys((array) ($args['ingredients'] ?? []));

        return self::signatureFromList(implode(',', $ingredients));
    }

    /** Normalises a comma-separated ingredient list to the sorted `a+b` signature. */
    private static function signatureFromList(string $csv): string
    {
        $tokens = array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), 'strlen')));
        sort($tokens);

        return implode('+', $tokens);
    }

    /**
     * The move for when the brain insisted on a spent research `combine`: run the
     * shared ladder ({@see AgentBrain::suggestion()}) with the entire tried +
     * world-known combine space marked exhausted.
     *
     * If `inventor_points` are already moving the ladder may offer a *fresh*
     * (untried, uninvented) pair — research is still paying, so that is allowed
     * through. Once points have stalled at 0 the ladder's speculation rung gates
     * itself off and it drops to infrastructure — `finalize` loose parts,
     * `construct` a tower when `composite` + `metal` are in hand, otherwise sell
     * a glut, harvest a shortage, or move toward the materials a build needs.
     * Returns `null` only when the ladder has nothing either, so the caller can
     * pass the tick instead of forcing a bad action.
     *
     * @param string              $spentSig The set that was refused, for the decision reason.
     * @param list<string>        $tried    Combine signatures already submitted this run.
     * @param array<string, bool> $known    `a+b => true` for world-known sets.
     *
     * @return array{verb: string, args: array<string, mixed>, reason: string}|null
     */
    private function fallbackDecision(AgentObservation $observation, string $spentSig, array $tried, array $known): ?array
    {
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];

        $exhausted = $known;
        foreach ($tried as $sig) {
            $exhausted[$sig] = true;
        }

        // Speculate on a fresh pair only while inventor points are actually
        // rising; once they have stalled at 0, research is not paying — skip the
        // combine rung and go straight to infrastructure.
        $researchPaying = (int) ($raw['inventor_points'] ?? 0) > 0;

        $suggestion = AgentBrain::suggestion($raw, $exhausted, $exhausted, $researchPaying);
        if ($suggestion === null) {
            return null;
        }

        // A combine may still come back — but only a genuinely fresh pair (the
        // ladder allows one while points are rising). Never re-surface a spent
        // set unless it is a production recipe.
        if (($suggestion['verb'] ?? '') === 'combine') {
            $sig = self::combineSignature((array) ($suggestion['args'] ?? []));
            if ($sig === '' || (isset($exhausted[$sig]) && ! isset(self::PRODUCTION_COMBINES[$sig]))) {
                return null;
            }
        }

        return [
            'verb' => (string) $suggestion['verb'],
            'args' => (array) ($suggestion['args'] ?? []),
            'reason' => "research set `{$spentSig}` spent — {$suggestion['why']}",
        ];
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
        $lastCombineSig = ($last !== null && ($last['verb'] ?? '') === 'combine')
            ? self::combineSignature((array) ($last['args'] ?? []))
            : '';
        $outcome = $lastQueued !== null
            ? $this->nha->intents->getIntentStatus($lastQueued)->then(
                function ($s) use ($agent_id, $lastQueued, $lastCombineSig): array {
                    $status = (string) ($s->status ?? '?');

                    // Settled or aged out: forget the id so the next turn does
                    // not keep polling GET /intent/{id} for an answer that will
                    // never change.
                    if (in_array($status, ['applied', 'rejected', 'gone'], true)) {
                        $this->state->clearQueuedIntent($agent_id, $lastQueued);
                    }

                    // A combine that landed is now a world-known set — merge it
                    // straight into the known list so it is refused from here on,
                    // without waiting for the next GET /rules.
                    if ($status === 'applied' && $lastCombineSig !== '' && is_array($this->knownCombines)) {
                        $this->knownCombines[$lastCombineSig] = true;
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

            $known = (array) ($pre['known'] ?? []);
            $tried = $this->state->getTriedCombineSignatures($agent_id);

            $context = ($last ?? []);
            if (($pre['outcome'] ?? null) !== null) {
                $context['outcome'] = $pre['outcome'];
            }
            $context['recent'] = $this->state->getRecentDecisions($agent_id, 10);
            $context['known_combines'] = array_keys($known);
            $context['tried_combines'] = $tried;

            return $this->brain->decide($observation, $context ?: null)->then(function (?array $decision) use ($agent_id, $token, $tick, $observation, $known, $tried) {
                if ($decision === null) {
                    return "💤 Agent #{$agent_id}: brain chose to wait (tick {$tick}).";
                }

                // Deterministic guardrail: never submit a research `combine` set
                // the world has already invented or that this agent already tried
                // this run — a repeat mints nothing and the model loops here
                // badly. Production recipes (see PRODUCTION_COMBINES) are exempt.
                // When one is refused, fall through to the infrastructure ladder
                // (finalize → construct → wealth → harvest) rather than idle.
                if (($decision['verb'] ?? '') === 'combine') {
                    $sig = self::combineSignature((array) ($decision['args'] ?? []));
                    $spent = $sig !== ''
                        && ! isset(self::PRODUCTION_COMBINES[$sig])
                        && (isset($known[$sig]) || in_array($sig, $tried, true));
                    if ($spent) {
                        $decision = $this->fallbackDecision($observation, $sig, $tried, $known);
                        if ($decision === null) {
                            return "🔁 Agent #{$agent_id}: no research left and no infrastructure move available — skipped `{$sig}` (tick {$tick}).";
                        }
                    }
                }

                if (($decision['verb'] ?? '') === 'combine') {
                    $this->state->recordCombineSignature($agent_id, self::combineSignature((array) ($decision['args'] ?? [])));
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
