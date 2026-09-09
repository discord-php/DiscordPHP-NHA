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
        // Flight kit + drive-chain items — a loop-break / research `combine`
        // must never eat these (a forced `combine advanced_motor+coal` /
        // `algae+ion_thruster` throws away a hard-won propulsion part).
        'ion_thruster' => 1,
        'heat_shield' => 1,
        'acid_skin' => 1,
        'cryo_fuel' => 1,
        'helium3' => 1,
        'hydrogen' => 1,
        'landing_gear' => 1,
        'fuel_tank' => 1,
        'motor' => 2,
        'rocket_engine' => 1,
        'advanced_motor' => 1,
        'engine' => 2,
        'steel' => 3,
    ];

    /**
     * `build{part:X}` values the engine has already answered with
     * `"unknown part X"`. The gear-up rotation skips these and
     * {@see self::step()} rewrites a model `build` that names one.
     */
    private const DEAD_BUILD_PARTS = ['thruster', 'ion_thruster', 'chassis', 'hull', 'rotor', 'airframe', 'wheels', 'body', 'motor', 'turbine'];

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
     * How much of a build material to keep untouchable: the static
     * {@see BUILD_MATERIAL_RESERVE} floor, raised to whatever the live ship
     * bill still needs ({@see Ladder::shipMaterialPlan()}). So a research /
     * loop-break `combine` will not eat aluminium the agent is about to turn
     * into `composite`, or metal earmarked for the next part — and the reserve
     * drops back once those parts are built.
     *
     * @param array<string,mixed> $raw the observation array
     */
    private function reserveFor(string $res, array $raw): int
    {
        $key = strtolower($res);
        $base = self::BUILD_MATERIAL_RESERVE[$key] ?? 0;
        $bill = Ladder::shipMaterialPlan($raw);

        return max($base, (int) ($bill[$key] ?? 0));
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

    /**
     * A real idle decision. NHA has no `wait` verb (it rejects as
     * `"unknown verb"`); {@see Ladder::noop()} returns the designed no-op
     * (`deposit` of one unit already held). Shaped as a decision array with a
     * `reason` for {@see StateStore::recordDecision()}.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{verb: string, args: array<string,mixed>, reason: string}
     */
    private static function idle(array $raw, string $reason): array
    {
        $n = Ladder::noop($raw, $reason);

        return ['verb' => $n['verb'], 'args' => $n['args'], 'reason' => $reason];
    }

    /** Normalises a comma-separated ingredient list to the sorted `a+b` signature. */
    private static function signatureFromList(string $csv): string
    {
        $tokens = array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), 'strlen')));
        sort($tokens);

        return implode('+', $tokens);
    }

    /**
     * Whether a rejected-intent message reads as "you didn't have the
     * ingredients" rather than "this set makes nothing" — the former is
     * transient and must not blacklist the recipe.
     */
    private static function looksLikeStockShortage(string $result): bool
    {
        $result = strtolower($result);
        foreach (['not enough', 'insufficient', "don't have", 'do not have', 'need ', 'needs ', 'requires ', 'missing', 'out of stock', 'too few'] as $needle) {
            if (str_contains($result, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Verbs that only reposition the agent — a window full of these is "going nowhere". */
    private const TRAVERSAL_VERBS = ['move', 'ride', 'land', 'launch', 'wait'];

    /**
     * Verbs that actually move the score / codex forward — a build, an assembly,
     * a research combine, passive-income deploy, a co-op invest, a contract. A
     * window with none of these is churn no matter how "busy" it looks: `mine`
     * and `sell` are work, but `mine → sell → mine → sell` forever is not
     * progress. {@see detectLoop()} flags a long stretch with zero of these.
     */
    private const ADVANCING_VERBS = [
        'construct', 'finalize', 'combine', 'build', 'deploy', 'invest', 'fulfill',
        'plant', 'ally', 'accept_ally', 'attack', 'heal', 'distress',
    ];

    /**
     * Verbs that move the agent between the ground and orbit / another body — an
     * "elevator trip". Each one has a real cost (fuel, a wasted turn, leaving
     * behind local work), so the agent should not take two in quick succession.
     *
     * @see self::TRANSIT_DWELL_TICKS
     */
    private const TRANSIT_VERBS = ['ride', 'launch', 'land', 'land_moon', 'land_body', 'depart', 'dock'];

    /**
     * Minimum turns to spend working a location after arriving before the brain
     * is allowed to leave it again (via `launch` / `ride` up / `depart`). Coming
     * home (`land`) is always allowed — that ends a bounce, it does not start one.
     */
    private const TRANSIT_DWELL_TICKS = 8;

    /**
     * Looks at the recent decision history for an infinite loop:
     *  - a `land` / `launch` run that is NOT changing altitude (stuck, e.g. on
     *    a structure `land` cannot get past),
     *  - one exact action dominating the window,
     *  - a short 2-4 move pattern repeated three times,
     *  - the same `move` target chosen three or more times,
     *  - nothing but traversal for most of the window — no `chop` / `mine` /
     *    `combine` / `sell` / `construct` progress.
     *
     * `land` / `launch` are excluded from the last four checks (a real descent
     * repeats them for many turns) but caught by the first, which uses the
     * recorded altitude to tell a stuck agent from one that is still moving.
     *
     * Returns a short description, or `null` when the play looks varied enough.
     *
     * @param list<array{verb: string, args: array, tick: ?int, alt: ?int}> $recent Oldest first.
     */
    public static function detectLoop(array $recent): ?string
    {
        // Stuck climb / descent: the last 4+ decisions are all `land` (or all
        // `launch`) and the altitude has barely moved across them.
        $tailVerbs = array_map(static fn($r): string => (string) ($r['verb'] ?? ''), array_slice($recent, -5));
        foreach (['land', 'launch'] as $climbVerb) {
            $run = array_filter(array_slice($recent, -5), static fn($r): bool => (string) ($r['verb'] ?? '') === $climbVerb);
            if (count($run) >= 4) {
                $alts = array_values(array_filter(array_map(static fn($r) => $r['alt'] ?? null, $run), 'is_int'));
                if (count($alts) >= 2 && abs($alts[0] - end($alts)) <= 3) {
                    return "stuck {$climbVerb} at altitude " . end($alts);
                }
                if ($alts === [] && count(array_unique($tailVerbs)) === 1) {
                    return "stuck {$climbVerb} (no altitude change)";
                }
            }
        }

        $fingerprints = [];
        $verbs = [];
        $moveTargets = [];
        foreach ($recent as $r) {
            $verb = (string) ($r['verb'] ?? '');
            // Excluded from the pattern checks below — a genuine descent repeats
            // `land` for many turns; the altitude check above handles a stuck one.
            if ($verb === '' || $verb === 'land' || $verb === 'launch') {
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

        // Churn: a long run of only gathering / trading / repositioning with
        // nothing that advances the score. Two "productive" verbs alternating
        // forever (`mine ↔ sell`, `buy ↔ sell`) slip past every check above —
        // each keeps the window "productive" and neither exact fingerprint
        // dominates — but the agent is going nowhere.
        //
        // `combine` is normally an advancing verb, but a window whose ONLY
        // advancing action is one repeated `combine` set is still churn — the
        // classic case being "buy carbon → combine aluminium+carbon → sell,
        // forever" with no `construct`. So a single distinct combine set does
        // not count; two or more (genuine research) does.
        $tail = array_slice($recent, -12);
        if (count($tail) >= 10) {
            $advancing = 0;
            $combineSigs = [];
            $windowVerbs = [];
            foreach ($tail as $r) {
                $v = (string) ($r['verb'] ?? '');
                if ($v === '' || $v === 'land' || $v === 'launch') {
                    continue;
                }
                $windowVerbs[] = $v;
                if ($v === 'combine') {
                    $ing = array_keys((array) (($r['args'] ?? [])['ingredients'] ?? []));
                    sort($ing);
                    $combineSigs[implode('+', $ing)] = true;
                } elseif (in_array($v, self::ADVANCING_VERBS, true)) {
                    $advancing++;
                }
            }
            if (count($combineSigs) >= 2) {
                $advancing += count($combineSigs);
            }

            if ($advancing === 0 && count($windowVerbs) >= 10) {
                $trade = count(array_filter($windowVerbs, static fn(string $v): bool => $v === 'buy' || $v === 'sell'));
                $distinct = array_values(array_unique($windowVerbs));
                if ($trade >= 4 && in_array('buy', $windowVerbs, true) && in_array('sell', $windowVerbs, true)) {
                    return 'buy/sell churn — trading in circles, nothing built (' . count($windowVerbs) . ' turns)';
                }
                if (count($distinct) <= 5) {
                    return count($distinct) <= 2
                        ? 'churn: ' . implode('/', $distinct) . ' on repeat, no progress'
                        : 'no advancing action for ' . count($windowVerbs) . ' turns';
                }
            }
        }

        // Two verbs (args stripped) own the whole window and neither is a real
        // step toward a ship / the Accord — `construct ↔ move` spire-spam, a
        // `combine ↔ move` fixation, etc. The churn check above misses these
        // because `construct`/`combine` count as "advancing".
        if ($n >= 8) {
            $vcounts = array_count_values($verbs);
            arsort($vcounts);
            $top2 = array_sum(array_slice(array_values($vcounts), 0, 2));
            $forward = ['finalize', 'depart', 'deploy', 'invest', 'build', 'land_moon', 'land_body', 'dock'];
            if ($top2 >= (int) ceil($n * 0.85) && ! array_intersect($verbs, $forward)) {
                return 'spinning on ' . implode('/', array_slice(array_keys($vcounts), 0, 2)) . ' — no step toward the goal';
            }
        }

        return null;
    }

    /**
     * The move for when the brain's pick is a dead end — a spent research
     * `combine`, or riding the elevator in circles. Runs the shared ladder
     * ({@see Ladder::suggestion()}) with the entire tried + world-known
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
    private function fallbackDecision(AgentObservation $observation, string $reasonLead, array $tried, array $known, bool $researchPaying, string $stance = 'homestead'): ?array
    {
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];

        $exhausted = $known;
        foreach ($tried as $sig) {
            $exhausted[$sig] = true;
        }

        $suggestion = Ladder::suggestion($raw, $exhausted, $exhausted, $researchPaying, $stance);
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
        $altitude = (int) ($raw['altitude'] ?? 0);
        $offGround = ($raw['in_space'] ?? false) || $altitude > 0;
        $lead = "loop broken → {$objective}";

        // Aloft but barely off the ground and `land` is not helping — the agent
        // is stuck on a structure it built. Step off the cell so `land` (or the
        // ground rungs) can work next turn. This wins over every objective.
        if ($offGround && $altitude <= 5) {
            return [
                'verb' => 'move',
                'args' => ['x' => max(0, min(219, $x + [3, -3, 0, 0][$tick % 4])), 'y' => max(0, min(219, $y + [0, 0, 3, -3][$tick % 4]))],
                'reason' => "{$lead}: step off the structure so you can land",
            ];
        }

        if ($objective === 'expand') {
            // Reuse the expansionist ladder — it encodes the whole flight chain
            // (land + build on a body, depart from Earth orbit, head to the
            // elevator). Take its pick unless it has nothing but `wait`.
            $pick = Ladder::suggestion($raw, $tried, $known, false, Stance::Expansionist->value);
            if ($pick !== null && ($pick['verb'] ?? '') !== 'wait') {
                return ['verb' => (string) $pick['verb'], 'args' => (array) ($pick['args'] ?? []), 'reason' => "{$lead}: {$pick['why']}"];
            }
            // else fall through to a relocation step (toward fresh ground / the elevator).
        }

        if ($objective === 'wealth') {
            $best = null;
            $bestQty = 0;
            foreach ($inv as $res => $qty) {
                if ($res === 'credits' || ! is_numeric($qty) || ! in_array((string) $res, Ladder::DEPOT_TRADEABLE, true)) {
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
            $raw = json_decode(json_encode($observation->jsonSerialize()), true);
            $raw = is_array($raw) ? $raw : [];
            if (Ladder::cellOccupied($raw)) {
                $step = Ladder::stepToClearGround($raw);

                return ['verb' => $step['verb'], 'args' => $step['args'], 'reason' => "{$lead}: {$step['why']}"];
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
                if (in_array($res, ['credits', 'engine', 'motor', 'chip', 'frame', 'fuel'], true) || ! is_numeric($qty) || $qty <= 0) {
                    continue;
                }
                // Skip a build material that is only at (or below) its reserve —
                // spending it on research would just be blocked by the guardrail.
                // The reserve rises to cover whatever the live ship bill needs.
                $reserve = $this->reserveFor((string) $res, $raw);
                if ($reserve > 0 && (int) $qty <= $reserve) {
                    continue;
                }
                $raws[] = (string) $res;
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

                        // A rejected combine is only "proven dead" when the set
                        // genuinely mints nothing — NOT when the agent simply
                        // lacked the ingredients that turn. Blacklisting on a
                        // stock shortage permanently kills a valid recipe (this
                        // is what wedged the composite build: one short
                        // `aluminum+carbon` got recorded dead forever). Production
                        // recipes are known-good and never recorded.
                        if ($status === 'rejected'
                            && ! isset(self::PRODUCTION_COMBINES[$lastCombineSig])
                            && ! self::looksLikeStockShortage((string) ($s->result ?? ''))
                        ) {
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

            $rawObs = json_decode(json_encode($observation->jsonSerialize()), true);
            $rawObs = is_array($rawObs) ? $rawObs : [];

            // Pick and persist the strategic stance for this turn (hysteresis in
            // Stance::pick keeps it from flip-flopping).
            $stancePrev = $this->state->getStance($agent_id);
            $stance = Stance::pick($rawObs, $stancePrev['stance'], $stancePrev['tick'])->value;
            $this->state->setStance($agent_id, $stance, $tick);

            // Combat overrides everything — defend before consulting the brain
            // or the loop guard. Heal, shoot back, or break contact.
            if (($defence = Ladder::defensiveAction($rawObs)) !== null) {
                $altNow = (int) ($observation->get('altitude') ?? 0);

                return $this->nha->intentWithToken($agent_id, $token, $defence['verb'], $defence['args'])
                    ->then(function ($queued) use ($agent_id, $defence, $tick, $altNow) {
                        $queuedId = ((array) $queued)['queued_intent'] ?? null;
                        $this->state->recordDecision($agent_id, [
                            'verb' => $defence['verb'],
                            'args' => $defence['args'],
                            'reason' => $defence['reason'],
                            'queued_intent' => $queuedId,
                            'tick' => $tick,
                            'alt' => $altNow,
                        ]);
                        $args = $defence['args'] === [] ? '' : ' ' . json_encode($defence['args'], JSON_UNESCAPED_SLASHES);
                        $ref = $queuedId !== null ? " (queued #{$queuedId})" : '';

                        return "🛡️ Agent #{$agent_id} → **{$defence['verb']}**{$args}{$ref}\n> {$defence['reason']}";
                    });
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
            // A "stuck land/launch" always breaks (a hard wedged state);
            // everything else respects the post-break cooldown.
            $loopRaw = self::detectLoop($recent);
            $loop = ($loopRaw !== null && (str_starts_with($loopRaw, 'stuck ') || ! $this->state->loopBreakCooldownActive($agent_id, $tick)))
                ? $loopRaw
                : null;
            // Only PEEK the objective for the prompt; the cursor is advanced (and
            // the cooldown armed) inside the decide-then, once we actually apply
            // it — so a failed brain call does not burn a rotation.
            $loopObjective = $loop !== null ? $this->state->peekNextForcedObjective($agent_id) : null;

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

            $altNow = (int) ($observation->get('altitude') ?? 0);

            return $this->brain->decide($observation, $context ?: null, $stance)->then(function (?array $decision) use ($agent_id, $token, $tick, $altNow, $observation, $rawObs, $known, $tried, $dead, $researchPaying, $recent, $loop, $loopObjective, $stance) {
                if ($decision === null && $loopObjective === null) {
                    // Record the pass so a wait-streak is visible to detectLoop.
                    $this->state->recordDecision($agent_id, ['verb' => 'wait', 'args' => [], 'reason' => '', 'queued_intent' => null, 'tick' => $tick, 'alt' => $altNow]);

                    return "💤 Agent #{$agent_id}: brain chose to wait (tick {$tick}).";
                }

                // Stuck in a loop — commit the objective rotation now (we know
                // the brain call succeeded) and override with a deterministic
                // move for it so the situation actually changes.
                if ($loopObjective !== null) {
                    $loopObjective = $this->state->bumpForcedObjective($agent_id, $tick);
                    $decision = $this->loopBreakDecision($loopObjective, $observation, $known, $tried, $dead, $tick);
                }

                $verb = (string) ($decision['verb'] ?? '');

                // Deterministic guardrail: never submit a `combine` set the world
                // has already invented, that the Guild has rejected for this
                // agent, that this agent already tried this run, or that would
                // dip a tower material below its reserve (research spends only
                // the surplus). A production recipe (PRODUCTION_COMBINES, i.e.
                // aluminium+carbon → composite) is fully exempt — it is known-good
                // and re-craftable, and must not be blocked by a stale dead entry.
                // When one is refused, fall through to the ladder rather than idle.
                if ($verb === 'combine') {
                    $sig = self::combineSignature((array) ($decision['args'] ?? []));
                    $ingredients = (array) ($decision['args']['ingredients'] ?? []);
                    $isProduction = isset(self::PRODUCTION_COMBINES[$sig]);
                    $isDead = $sig !== '' && ! $isProduction && in_array($sig, $dead, true);

                    $dipsReserve = null;
                    if (! $isProduction) {
                        $held = (array) $observation->getInventory();
                        foreach ($ingredients as $res => $qty) {
                            // Reserve = the static floor, raised to what the live
                            // ship bill still needs; 0 means unprotected.
                            $reserve = $this->reserveFor((string) $res, $rawObs);
                            if ($reserve > 0 && (int) ($held[$res] ?? 0) < $reserve + max(1, (int) $qty)) {
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
                        // Record it as tried even though it never went out, so the
                        // brain stops re-picking a set it cannot submit (e.g. the
                        // "combine composite + ice" fixation against the reserve).
                        if (! $isProduction) {
                            $this->state->recordCombineSignature($agent_id, $sig);
                        }

                        $lead = match (true) {
                            $isDead => "combine `{$sig}` was rejected by the Guild",
                            $dipsReserve !== null => "combine `{$sig}` would dip below the {$dipsReserve} reserve",
                            default => "research set `{$sig}` spent",
                        };
                        $decision = $this->fallbackDecision($observation, $lead, $tried, $known, $researchPaying, $stance);
                        if ($decision === null) {
                            // Record the skip so a skip-streak is visible to detectLoop.
                            $this->state->recordDecision($agent_id, ['verb' => 'wait', 'args' => [], 'reason' => 'nothing to do', 'queued_intent' => null, 'tick' => $tick, 'alt' => $altNow]);

                            return "🔁 Agent #{$agent_id}: no research left and no infrastructure move available — skipped `{$sig}` (tick {$tick}).";
                        }
                    }
                }

                // Stay on the mission. An expansionist agent on the ground
                // without a fuelled ion-thruster ship should be GEARING one, not
                // riding to an empty orbit (`ride`/`launch`/`depart`) and not
                // raising another vanity spire (`construct` box/cylinder/sphere/
                // cone/pyramid). Swap either for the gear-up suggestion.
                // Colony/terraform/extractor/monument `construct`s are the
                // mission and pass through.
                $vanityTower = $verb === 'construct'
                    && in_array((string) ($decision['args']['shape'] ?? ''), ['box', 'cylinder', 'sphere', 'cone', 'pyramid'], true);
                if (($vanityTower || in_array($verb, ['ride', 'launch', 'depart'], true))
                    && $loopObjective === null
                    && $stance === Stance::Expansionist->value
                    && ! (bool) ($observation->get('in_space') ?? false)
                    && (int) ($observation->get('altitude') ?? 0) === 0
                ) {
                    $held = (array) $observation->getInventory();
                    $fuelled = ($held['hydrogen'] ?? 0) > 0 || ($held['cryo_fuel'] ?? 0) > 0 || ($held['helium3'] ?? 0) > 0;
                    // A loose `ion_thruster` resource is not a ship — only a
                    // `finalize`d orbital vehicle clears the flight verbs.
                    $ready = $fuelled && Ladder::hasOrbitalShip($rawObs);
                    if (! $ready) {
                        $gear = $this->fallbackDecision($observation, 'stay on the mission — gear the ship, do not ' . ($vanityTower ? 'raise another spire' : 'ride to an empty orbit'), $tried, $known, $researchPaying, $stance);
                        if ($gear !== null && ($gear['verb'] ?? '') !== $verb) {
                            $decision = $gear;
                            $verb = (string) ($decision['verb'] ?? '');
                        }
                    }
                }

                // A `construct` on a cell that already holds a structure is
                // rejected every time ("a structure already stands on this
                // cell"). Step to clear ground first.
                if ($verb === 'construct'
                    && in_array((string) ($decision['args']['shape'] ?? ''), ['box', 'cylinder', 'sphere', 'cone', 'pyramid', 'road', 'city', 'monument', 'ziggurat'], true)
                    && Ladder::cellOccupied($rawObs)
                ) {
                    $step = Ladder::stepToClearGround($rawObs);
                    $decision = ['verb' => $step['verb'], 'args' => $step['args'], 'reason' => $step['why']];
                    $verb = 'move';
                }

                // In orbit with a fuelled ship and no window open, the model
                // often picks `land` — dropping to Earth just to climb back.
                // Hold: substitute the expansionist ladder's pick (depart if a
                // window opened, dock an asteroid, else wait in orbit).
                if ($verb === 'land'
                    && $loopObjective === null
                    && $stance === Stance::Expansionist->value
                    && (int) ($observation->get('altitude') ?? 0) >= 300
                    && ($rawObs['expansion']['at_body'] ?? null) === null
                    && Ladder::hasOrbitalShip($rawObs)
                ) {
                    $hold = Ladder::suggestion($rawObs, $tried, $known, false, $stance);
                    if ($hold !== null && ($hold['verb'] ?? '') !== 'land') {
                        $decision = $hold;
                        $verb = (string) ($decision['verb'] ?? '');
                    } else {
                        $decision = self::idle($rawObs, 'in orbit with a ship — holding for a transfer window');
                        $verb = (string) $decision['verb'];
                    }
                }

                // A `land` / `land_body` / `land_moon` with no controllable
                // vehicle is rejected every tick ("no controllable vehicle to
                // land with") — the shipless elevator bounce. Substitute a real
                // way down (ride the elevator, or wait out orbital decay).
                if (in_array($verb, ['land', 'land_body', 'land_moon'], true) && ! Ladder::hasAnyVehicle($rawObs)) {
                    $down = Ladder::descentWithoutShip($rawObs);
                    $decision = $down !== null
                        ? ['verb' => $down['verb'], 'args' => $down['args'], 'reason' => $down['why']]
                        : self::idle($rawObs, 'no vehicle to land with — waiting out orbital decay');
                    $verb = (string) ($decision['verb'] ?? '');
                }

                // The model keeps asking for `build{part:"thruster"}` /
                // `"chassis"` — parts the engine has already rejected as
                // "unknown part". Swap a known-dead part for the ladder's
                // rotated archetype so the search actually advances.
                if ($verb === 'build'
                    && in_array((string) ($decision['args']['part'] ?? ''), self::DEAD_BUILD_PARTS, true)
                ) {
                    $tickNow = (int) ($observation->get('tick') ?? 0);
                    $part = Ladder::SHIP_PART_ARCHETYPES[$tickNow % count(Ladder::SHIP_PART_ARCHETYPES)];
                    $decision = ['verb' => 'build', 'args' => ['part' => $part], 'reason' => "\"{$decision['args']['part']}\" is a rejected part — trying {$part} instead"];
                }

                // Cap runaway `build engine` / any over-target part: the model,
                // told "engine-heavy", once stacked 82 engines into one bundle.
                // The flyer recipe is small and specific — if a part is already
                // at its SHIP_BUNDLE_TARGET count, swap to the first part still
                // under target so the flyer actually completes.
                if ($verb === 'build') {
                    $bpart = (string) ($decision['args']['part'] ?? '');
                    $lp = Ladder::looseParts($rawObs);
                    $cnt = static fn(string $q): int => count(array_filter($lp, static fn(string $z): bool => $z === $q));
                    if ($bpart !== '' && isset(Ladder::SHIP_BUNDLE_TARGET[$bpart]) && $cnt($bpart) >= Ladder::SHIP_BUNDLE_TARGET[$bpart]) {
                        foreach (Ladder::SHIP_BUNDLE_TARGET as $p => $want) {
                            if ($cnt($p) < $want) {
                                $up = Ladder::SHIP_PART_UPGRADE[$p] ?? null;
                                $a = ['part' => $p];
                                if ($up !== null && (int) (((array) $observation->getInventory())[$up] ?? 0) > 0) {
                                    $a['with'] = [$up => 1];
                                }
                                $decision = ['verb' => 'build', 'args' => $a, 'reason' => "enough {$bpart} — build a {$p} toward the rest of the flyer"];
                                break;
                            }
                        }
                    }
                }

                // `deploy` when there is nothing to deploy — an inert hull, or
                // the drives=true vehicles are already out roaming (the observe
                // feed does not always flag `deployed`). Either way a repeated
                // `deploy` just spins; after 2 of them in the recent window,
                // fall back to gearing / earning.
                if ($verb === 'deploy') {
                    $recentDeploys = count(array_filter(
                        array_slice($recent, -5),
                        static fn($r): bool => (string) ($r['verb'] ?? '') === 'deploy',
                    ));
                    // A `drives`/`flies` vehicle already in hand + a repeated
                    // `deploy` = the observe feed isn't flagging it as out, but
                    // it is (it's auto-mining). Stop after one repeat.
                    $inertOnly = ! Ladder::hasAnyVehicle($rawObs) && Ladder::hasDeadHull($rawObs);
                    $alreadyHasMiner = Ladder::hasAnyVehicle($rawObs) && $recentDeploys >= 1;
                    if ($inertOnly || $alreadyHasMiner || $recentDeploys >= 2) {
                        $gear = $this->fallbackDecision($observation, 'nothing left to deploy — build / earn instead', $tried, $known, $researchPaying, $stance);
                        if ($gear !== null && ($gear['verb'] ?? '') !== 'deploy') {
                            $decision = $gear;
                            $verb = (string) ($decision['verb'] ?? '');
                        } else {
                            $decision = self::idle($rawObs, 'nothing to deploy this turn');
                            $verb = (string) $decision['verb'];
                        }
                    }
                }

                // Don't `finalize` a stub. Hold `finalize` until the bundle is a
                // finished flyer (cockpit + engines + propellers + wings + jet);
                // until then, work the ship craft chain / build the next part.
                if ($verb === 'finalize' && ! Ladder::hasOrbitalShip($rawObs)) {
                    $held0 = Ladder::looseParts($rawObs);
                    if (! Ladder::flyerReady($held0)) {
                        $held = (array) $observation->getInventory();
                        $cnt = static fn(string $q): int => count(array_filter($held0, static fn(string $z): bool => $z === $q));
                        if ($craft = Ladder::shipCraftStep($held)) {
                            $decision = ['verb' => $craft['verb'], 'args' => $craft['args'], 'reason' => $craft['why']];
                        } elseif ((int) ($held['metal'] ?? 0) < 10 && (int) ($held['credits'] ?? 0) >= 60) {
                            $decision = ['verb' => 'buy', 'args' => ['resource' => 'metal', 'n' => 20], 'reason' => 'flyer parts cost metal — stock it before finalizing a stub'];
                        } else {
                            $part = 'engine';
                            foreach (Ladder::SHIP_BUNDLE_TARGET as $p => $want) {
                                if ($cnt($p) < $want) {
                                    $part = $p;
                                    break;
                                }
                            }
                            $up = Ladder::SHIP_PART_UPGRADE[$part] ?? null;
                            $a = ['part' => $part];
                            if ($up !== null && (int) ($held[$up] ?? 0) > 0) {
                                $a['with'] = [$up => 1];
                            }
                            $decision = ['verb' => 'build', 'args' => $a, 'reason' => "flyer incomplete — build the {$part} first"];
                        }
                        $verb = (string) $decision['verb'];
                    }
                }

                // `build` is a forward verb the loop guard will not flag, so
                // repeating the SAME `part` can spin quietly (rotating through
                // different parts is fine — that is the search). If the last
                // three turns were `build` with this exact part, pause a tick.
                if ($verb === 'build') {
                    $thisPart = (string) ($decision['args']['part'] ?? '');
                    $lastThree = array_slice($recent, -3);
                    $sameRun = count($lastThree) === 3 && array_reduce(
                        $lastThree,
                        static fn(bool $c, $r): bool => $c
                            && (string) ($r['verb'] ?? '') === 'build'
                            && (string) (((array) ($r['args'] ?? []))['part'] ?? '') === $thisPart,
                        true,
                    );
                    if ($sameRun && $thisPart !== '') {
                        $tickNow = (int) ($observation->get('tick') ?? 0);
                        $next = Ladder::SHIP_PART_ARCHETYPES[($tickNow + 1) % count(Ladder::SHIP_PART_ARCHETYPES)];
                        $next = $next === $thisPart ? Ladder::SHIP_PART_ARCHETYPES[($tickNow + 2) % count(Ladder::SHIP_PART_ARCHETYPES)] : $next;
                        $decision = ['verb' => 'build', 'args' => ['part' => $next], 'reason' => "\"{$thisPart}\" tried three times — rotating to {$next}"];
                    }
                }

                // Anti-elevator: leaving the current location — `launch`, `ride`
                // up, or `depart` — is expensive, and the brain likes to ride up,
                // find nothing to do, come back, and repeat. If the agent changed
                // location within the last TRANSIT_DWELL_TICKS turns, make it work
                // the current spot instead; only let it leave once the ladder has
                // no local move left (genuinely exhausted). `land` is exempt —
                // coming home ends a bounce rather than starting one. A forced
                // loop-break objective (which may legitimately want to explore)
                // is left alone.
                if (($verb === 'launch' || $verb === 'depart' || $verb === 'ride') && $loopObjective === null) {
                    $lastTransitTick = null;
                    foreach (array_reverse($recent) as $r) {
                        if (in_array((string) ($r['verb'] ?? ''), self::TRANSIT_VERBS, true)) {
                            $lastTransitTick = (int) ($r['tick'] ?? 0);
                            break;
                        }
                    }
                    if ($lastTransitTick !== null && ($tick - $lastTransitTick) <= self::TRANSIT_DWELL_TICKS) {
                        // The ladder prefers local work and only falls back to a
                        // transit verb (`land`) when the current spot is truly
                        // exhausted. Take its pick unless it just agrees with the
                        // brain (same verb) or has nothing at all.
                        $stay = $this->fallbackDecision($observation, 'just changed location — work this spot before riding the elevator again', $tried, $known, $researchPaying, $stance);
                        if ($stay !== null && (string) ($stay['verb'] ?? '') !== $verb) {
                            $decision = $stay;
                        }
                    }
                }

                // NHA has no `wait` verb — the engine rejects it as "unknown
                // verb". Anything (ladder, model, an override above) that still
                // asked to idle is rewritten to the real no-op.
                if (($decision['verb'] ?? '') === 'wait') {
                    $decision = self::idle($rawObs, (string) ($decision['reason'] ?? 'idle turn'));
                }

                if (($decision['verb'] ?? '') === 'combine') {
                    $this->state->recordCombineSignature($agent_id, self::combineSignature((array) ($decision['args'] ?? [])));
                }

                return $this->nha->intentWithToken($agent_id, $token, $decision['verb'], $decision['args'])
                    ->then(function ($queued) use ($agent_id, $decision, $tick, $altNow, $loop, $loopObjective, $stance) {
                        $queued = (array) $queued;
                        $queuedId = $queued['queued_intent'] ?? null;

                        $this->state->recordDecision($agent_id, [
                            'verb' => $decision['verb'],
                            'args' => $decision['args'],
                            'reason' => $decision['reason'],
                            'queued_intent' => $queuedId,
                            'tick' => $tick,
                            'alt' => $altNow,
                        ]);

                        $ref = $queuedId !== null ? " (queued #{$queuedId})" : '';
                        $args = $decision['args'] === [] ? '' : ' ' . json_encode($decision['args'], JSON_UNESCAPED_SLASHES);
                        $reason = $decision['reason'] !== '' ? "\n> {$decision['reason']}" : '';
                        $head = $loopObjective !== null
                            ? "♻️ Agent #{$agent_id} loop ({$loop}) → forced **{$loopObjective}**:"
                            : "🤖 [{$stance}] Agent #{$agent_id} →";

                        return "{$head} **{$decision['verb']}**{$args}{$ref}{$reason}";
                    });
            });
        }));
    }
}
