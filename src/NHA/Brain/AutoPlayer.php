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
     * Refined inputs a tower / vehicle needs, each mapped to the amount to keep
     * in reserve. A research `combine` may consume one of these — but only the
     * surplus above its reserve; if spending it would leave the agent below the
     * reserve the combine is refused and it goes back to buying / building.
     * (`aluminium+carbon → composite` is exempt, via {@see self::PRODUCTION_COMBINES}.)
     *
     * @var array<string, int>
     *
     * @since 3.1.16
     */
    private const BUILD_MATERIAL_RESERVE = [
        'metal' => 8,   // a tower's `size`
        'composite' => 2,
        'aluminum' => 4,
        'aluminium' => 4,
        'carbon' => 4,
        'alloy' => 4,
        'steel' => 4,
        'titanium' => 4,
        'superalloy' => 4,
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

    /** Verbs that only reposition the agent — a window full of these is "going nowhere". */
    private const TRAVERSAL_VERBS = ['move', 'ride', 'land', 'launch', 'wait'];

    /**
     * Looks at the recent decision history for an infinite loop:
     *  - one exact action dominating the window,
     *  - a short 2-4 move pattern repeated three times,
     *  - the same `move` target chosen three or more times,
     *  - nothing but traversal (move / ride / land / …) for most of the window —
     *    no `chop` / `mine` / `combine` / `sell` / `construct` progress.
     * Returns a short description, or `null` when the play looks varied enough.
     *
     * @param list<array{verb: string, args: array, tick: ?int}> $recent Oldest first.
     */
    public static function detectLoop(array $recent): ?string
    {
        $fingerprints = [];
        $verbs = [];
        $moveTargets = [];
        foreach ($recent as $r) {
            $verb = (string) ($r['verb'] ?? '');
            if ($verb === '') {
                continue;
            }
            $args = (array) ($r['args'] ?? []);
            ksort($args);
            $fingerprints[] = $verb . ($args === [] ? '' : ':' . json_encode($args));
            $verbs[] = $verb;
            if ($verb === 'move' && isset($args['x'], $args['y'])) {
                $moveTargets[] = $args['x'] . ',' . $args['y'];
            }
        }

        $n = count($fingerprints);
        if ($n < 6) {
            return null;
        }

        // One exact action dominates the window.
        $counts = array_count_values($fingerprints);
        arsort($counts);
        $top = (string) array_key_first($counts);
        if ($counts[$top] >= max(4, (int) ceil($n * 0.55))) {
            return 'repeating ' . explode(':', $top)[0];
        }

        // The tail is a 2-4 move pattern repeated three times over.
        for ($p = 2; $p <= 4; $p++) {
            if ($n < $p * 3) {
                continue;
            }
            $tail = array_slice($fingerprints, -$p * 3);
            $unit = array_slice($tail, 0, $p);
            if (array_slice($tail, $p, $p) === $unit && array_slice($tail, $p * 2, $p) === $unit) {
                return $p . '-move cycle: ' . implode(' → ', array_map(static fn($s): string => explode(':', $s)[0], $unit));
            }
        }

        // The same cell targeted again and again — walking to one spot on repeat.
        $targetCounts = array_count_values($moveTargets);
        arsort($targetCounts);
        if ($targetCounts !== [] && reset($targetCounts) >= 3) {
            return 'move loop to (' . (string) array_key_first($targetCounts) . ')';
        }

        // Nothing productive in the last 8 turns — only repositioning.
        $window = array_slice($verbs, -8);
        $traversal = count(array_filter($window, static fn(string $v): bool => in_array($v, self::TRAVERSAL_VERBS, true)));
        if (count($window) >= 8 && $traversal >= 6) {
            return 'no productive action for ' . $traversal . '/' . count($window) . ' turns';
        }

        return null;
    }

    /**
     * The move for when the brain's pick is a dead end — a spent research
     * `combine`, or riding the elevator in circles. Runs the shared ladder
     * ({@see AgentBrain::suggestion()}) with the entire tried + world-known
     * combine space marked exhausted.
     *
     * If `inventor_points` rose recently the ladder may offer a *fresh* (untried,
     * uninvented) pair — research is still paying, so that is allowed through.
     * Otherwise it drops to infrastructure — `finalize` loose parts, `land` when
     * there is nothing to do off the ground, `construct` when `composite` +
     * `metal` are in hand, else sell a surplus, harvest a shortage, or move
     * toward the materials a build needs. Returns `null` only when the ladder
     * has nothing either.
     *
     * @param string              $reasonLead     Prefix for the decision reason (why the brain's pick was dropped).
     * @param list<string>        $tried          Combine signatures already submitted this run.
     * @param array<string, bool> $known          `a+b => true` for world-known sets.
     * @param bool                $researchPaying Whether inventor points rose recently — if so a fresh
     *                                            pair is still worth a shot, otherwise go to infrastructure.
     *
     * @return array{verb: string, args: array<string, mixed>, reason: string}|null
     */
    private function fallbackDecision(AgentObservation $observation, string $reasonLead, array $tried, array $known, bool $researchPaying): ?array
    {
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];

        $exhausted = $known;
        foreach ($tried as $sig) {
            $exhausted[$sig] = true;
        }

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
            'reason' => "{$reasonLead} — {$suggestion['why']}",
        ];
    }

    /**
     * A deterministic action for a forced objective, used to break a detected
     * loop regardless of what the brain picked. Always returns something — the
     * point is to change the situation so the next observation is different.
     *
     * @param string              $objective One of {@see StateStore::OBJECTIVE_ROTATION}.
     * @param array<string, bool> $known     World-known + dead combine sigs.
     * @param list<string>        $tried     Sigs already submitted this run.
     * @param list<string>        $dead      Guild-rejected sigs.
     *
     * @return array{verb: string, args: array<string, mixed>, reason: string}
     */
    private function loopBreakDecision(string $objective, AgentObservation $observation, array $known, array $tried, array $dead, int $tick): array
    {
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];
        $inv = (array) ($raw['inventory'] ?? []);
        $pos = (array) ($raw['position'] ?? [0, 0]);
        $x = (int) ($pos[0] ?? 0);
        $y = (int) ($pos[1] ?? 0);
        $offGround = ($raw['in_space'] ?? false) || (int) ($raw['altitude'] ?? 0) > 0;
        $lead = "loop broken → {$objective}";

        if ($objective === 'wealth') {
            $best = null;
            $bestQty = 0;
            foreach ($inv as $res => $qty) {
                if ($res === 'credits' || ! is_numeric($qty)) {
                    continue;
                }
                if ((int) $qty >= 15 && (int) $qty > $bestQty) {
                    $best = (string) $res;
                    $bestQty = (int) $qty;
                }
            }
            if ($best !== null) {
                // Keep a working 10 even when breaking a loop with a sale.
                return ['verb' => 'sell', 'args' => ['resource' => $best, 'n' => min($bestQty - 10, 20)], 'reason' => "{$lead}: sell surplus {$best} for credits"];
            }
        }

        if ($objective === 'build') {
            if ($offGround) {
                return ['verb' => 'land', 'args' => [], 'reason' => "{$lead}: land so you can build"];
            }
            if ((int) ($inv['composite'] ?? 0) >= 2 && (int) ($inv['metal'] ?? 0) >= 8) {
                $shape = ['box', 'cylinder', 'pyramid', 'cone', 'sphere'][$tick % 5];

                return [
                    'verb' => 'construct',
                    'args' => ['shape' => $shape, 'size' => min(8, (int) $inv['metal']), 'height' => 14 * min((int) $inv['composite'], 3), 'name' => 'spire-' . ($tick % 1000)],
                    'reason' => "{$lead}: raise a {$shape}",
                ];
            }
        }

        if ($objective === 'research') {
            $raws = [];
            foreach ($inv as $res => $qty) {
                if (! in_array($res, ['credits', 'engine', 'motor', 'chip', 'frame', 'fuel'], true) && is_numeric($qty) && $qty > 0) {
                    $raws[] = (string) $res;
                }
            }
            sort($raws);
            $count = count($raws);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $pair = [$raws[$i], $raws[$j]];
                    sort($pair);
                    $sig = implode('+', $pair);
                    if (! isset($known[$sig]) && ! in_array($sig, $tried, true) && ! in_array($sig, $dead, true)) {
                        return ['verb' => 'combine', 'args' => ['ingredients' => [$pair[0] => 1, $pair[1] => 1]], 'reason' => "{$lead}: try {$sig}"];
                    }
                }
            }
        }

        // Before wandering: if standing on a deposit, harvest it — that is a
        // productive turn (raw → credits, or a build input) and it breaks the
        // "nothing productive" window the loop detector keys on. Skipped for
        // `explore`, whose whole point is to relocate.
        if ($objective !== 'explore' && ! $offGround) {
            foreach ((array) ($raw['nearby_deposits'] ?? []) as $deposit) {
                $deposit = (array) $deposit;
                $res = (string) ($deposit['resource'] ?? '');
                if ($res === '' || (int) ($deposit['dist'] ?? 9) !== 0) {
                    continue;
                }
                $verb = $res === 'wood'
                    ? 'chop'
                    : (in_array($res, ['herb', 'lichen', 'fungus', 'algae'], true) ? 'gather' : 'mine');

                return ['verb' => $verb, 'args' => ['n' => min((int) ($deposit['amount'] ?? 10), 15)], 'reason' => "{$lead}: harvest the {$res} you are standing on"];
            }
        }

        // explore — and the fallthrough for every objective with nothing else to
        // do: a long step in a direction that rotates over time, far enough to
        // clear whatever the agent was circling so the next observation is fresh.
        $dirs = [[28, 0], [0, 28], [-28, 0], [0, -28], [20, 20], [-20, -20], [20, -20], [-20, 20]];
        $d = $dirs[intdiv(max(0, $tick), 6) % count($dirs)];

        return [
            'verb' => 'move',
            'args' => ['x' => max(0, min(219, $x + $d[0])), 'y' => max(0, min(219, $y + $d[1]))],
            'reason' => "{$lead}: walk to fresh ground",
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
     * The turn flow (lease → outcome poll → observe → loop guard → decide →
     * combine/ride guardrails → submit) is diagrammed in `docs/PLAYBOOK.md`;
     * keep that in sync with changes here.
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

                    if ($lastCombineSig !== '') {
                        // A combine that landed is now a world-known set — merge it
                        // straight into the known list so it is refused from here
                        // on, without waiting for the next GET /rules.
                        if ($status === 'applied' && is_array($this->knownCombines)) {
                            $this->knownCombines[$lastCombineSig] = true;
                        }

                        // The Guild rejected it: this tag-set makes NOTHING and
                        // never will. Record it as proven-dead so it is never
                        // submitted again by this agent, not even once.
                        if ($status === 'rejected') {
                            $this->state->recordDeadCombine($agent_id, $lastCombineSig);
                        }
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

            // World-known recipes plus the sets the Guild has rejected for this
            // agent — both mint nothing, so treat them the same everywhere.
            $dead = $this->state->getDeadCombines($agent_id);
            $known = (array) ($pre['known'] ?? []);
            foreach ($dead as $sig) {
                $known[$sig] = true;
            }
            $tried = $this->state->getTriedCombineSignatures($agent_id);
            $researchPaying = $this->state->noteInventorPoints($agent_id, (int) ($observation->get('inventor_points') ?? 0));

            $recent = $this->state->getRecentDecisions($agent_id, 12);

            // Loop guard: if the last several turns are one action on repeat, a
            // short repeating cycle, or nothing but repositioning, force a
            // *different kind* of objective this turn (rotating explore → wealth
            // → build → research) and act on it deterministically. A cooldown
            // after each break stops it thrashing every turn on its own moves.
            $loop = $this->state->loopBreakCooldownActive($agent_id, $tick) ? null : self::detectLoop($recent);
            $loopObjective = $loop !== null ? $this->state->bumpForcedObjective($agent_id, $tick) : null;

            $context = ($last ?? []);
            if (($pre['outcome'] ?? null) !== null) {
                $context['outcome'] = $pre['outcome'];
            }
            $context['recent'] = $recent;
            $context['known_combines'] = array_keys($known);
            $context['tried_combines'] = $tried;
            $context['dead_combines'] = $dead;
            if ($loop !== null) {
                $context['loop'] = $loop;
                $context['forced_objective'] = $loopObjective;
            }

            return $this->brain->decide($observation, $context ?: null)->then(function (?array $decision) use ($agent_id, $token, $tick, $observation, $known, $tried, $dead, $researchPaying, $recent, $loop, $loopObjective) {
                if ($decision === null && $loopObjective === null) {
                    return "💤 Agent #{$agent_id}: brain chose to wait (tick {$tick}).";
                }

                // Stuck in a loop — override with a deterministic move for the
                // rotated objective so the situation actually changes.
                if ($loopObjective !== null) {
                    $decision = $this->loopBreakDecision($loopObjective, $observation, $known, $tried, $dead, $tick);
                }

                $verb = (string) ($decision['verb'] ?? '');
                $recentVerbs = array_map(static fn($r): string => (string) ($r['verb'] ?? ''), array_slice($recent, -4));

                // Deterministic guardrail: never submit a `combine` set the world
                // has already invented, that the Guild has rejected for this
                // agent, that this agent already tried this run, or that would
                // dip a tower material below its reserve (research spends only
                // the surplus). A production recipe (PRODUCTION_COMBINES, i.e.
                // aluminium+carbon → composite) is exempt UNLESS it is dead.
                // When one is refused, fall through to the ladder rather than idle.
                if ($verb === 'combine') {
                    $sig = self::combineSignature((array) ($decision['args'] ?? []));
                    $ingredients = (array) ($decision['args']['ingredients'] ?? []);
                    $isDead = $sig !== '' && in_array($sig, $dead, true);
                    $isProduction = isset(self::PRODUCTION_COMBINES[$sig]);

                    $dipsReserve = null;
                    if (! $isProduction) {
                        $held = (array) $observation->getInventory();
                        foreach ($ingredients as $res => $qty) {
                            $reserve = self::BUILD_MATERIAL_RESERVE[strtolower((string) $res)] ?? null;
                            if ($reserve !== null && (int) ($held[$res] ?? 0) < $reserve + max(1, (int) $qty)) {
                                $dipsReserve = (string) $res;
                                break;
                            }
                        }
                    }

                    $spent = $sig !== '' && ($isDead || (
                        ! $isProduction
                        && ($dipsReserve !== null || isset($known[$sig]) || in_array($sig, $tried, true))
                    ));
                    if ($spent) {
                        $lead = match (true) {
                            $isDead => "combine `{$sig}` was rejected by the Guild",
                            $dipsReserve !== null => "combine `{$sig}` would dip below the {$dipsReserve} reserve",
                            default => "research set `{$sig}` spent",
                        };
                        $decision = $this->fallbackDecision($observation, $lead, $tried, $known, $researchPaying);
                        if ($decision === null) {
                            return "🔁 Agent #{$agent_id}: no research left and no infrastructure move available — skipped `{$sig}` (tick {$tick}).";
                        }
                    }
                }

                // Anti-bounce: the brain likes to ride the elevator up, find
                // nothing to do off the ground, ride back down, and repeat. If it
                // picks `ride` right after riding, take the ladder's move instead.
                if ($verb === 'ride' && in_array('ride', $recentVerbs, true)) {
                    $alt = $this->fallbackDecision($observation, 'riding the elevator in circles', $tried, $known, $researchPaying);
                    if ($alt !== null) {
                        $decision = $alt;
                    }
                }

                if (($decision['verb'] ?? '') === 'combine') {
                    $this->state->recordCombineSignature($agent_id, self::combineSignature((array) ($decision['args'] ?? [])));
                }

                return $this->nha->intentWithToken($agent_id, $token, $decision['verb'], $decision['args'])
                    ->then(function ($queued) use ($agent_id, $decision, $tick, $loop, $loopObjective) {
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
                        $head = $loopObjective !== null
                            ? "♻️ Agent #{$agent_id} loop ({$loop}) → forced **{$loopObjective}**:"
                            : "🤖 Agent #{$agent_id} →";

                        return "{$head} **{$decision['verb']}**{$args}{$ref}{$reason}";
                    });
            });
        }));
    }
}
