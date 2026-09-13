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
 * {@see \NHA\StateStore} slice: the autoplay loop's strategic memory —
 * the forced-objective rotation and its cooldown (armed when
 * {@see \NHA\Brain\AutoPlayer::detectLoop()} catches the agent looping), the
 * persisted {@see \NHA\Brain\Stance}, and the inventor-points trend that tells
 * the fallback whether research is still paying.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait LoopStrategyStateTrait
{
    /** A forced objective sticks for this many world ticks after a loop break. */
    private const FORCED_OBJECTIVE_TTL_TICKS = 45;

    /** After a loop break, do not break again for this many ticks — let it play out. */
    private const LOOP_BREAK_COOLDOWN_TICKS = 24;

    /**
     * Objectives cycled through, in order, each time the agent is caught
     * looping. `expand` (progress the Solar Accord mission — reach a body, build
     * a colony/extractor/terraform, or head for the elevator) is tried first.
     */
    public const OBJECTIVE_ROTATION = ['expand', 'wealth', 'build', 'research'];

    /** Research counts as "paying" for this long after the last inventor-point gain. */
    private const RESEARCH_PAYING_WINDOW = 900;

    /**
     * Advances the agent's forced-objective cursor one step through
     * {@see self::OBJECTIVE_ROTATION} and returns the new objective. Called by
     * {@see \NHA\Brain\AutoPlayer} when it detects the agent is stuck in a loop,
     * so each successive loop break tries a *different* kind of goal.
     *
     * @since 3.1.12
     */
    public function bumpForcedObjective(int $agent_id, int $tick): string
    {
        $key = (string) $agent_id;
        $prev = $this->data['agent_forced_objective'][$key] ?? null;
        $idx = (is_array($prev) ? (int) ($prev['idx'] ?? -1) : -1);
        $idx = ($idx + 1) % count(self::OBJECTIVE_ROTATION);
        $objective = self::OBJECTIVE_ROTATION[$idx];

        $this->data['agent_forced_objective'][$key] = ['idx' => $idx, 'objective' => $objective, 'tick' => $tick];
        $this->save();

        return $objective;
    }

    /**
     * The objective {@see bumpForcedObjective()} WOULD return next, without
     * advancing the cursor or arming the cooldown — so the loop guard can name
     * the objective in the brain prompt and only commit it once the turn
     * actually applies it.
     *
     * @since 3.1.21
     */
    public function peekNextForcedObjective(int $agent_id): string
    {
        $prev = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        $idx = (is_array($prev) ? (int) ($prev['idx'] ?? -1) : -1);

        return self::OBJECTIVE_ROTATION[($idx + 1) % count(self::OBJECTIVE_ROTATION)];
    }

    /**
     * The objective forced by the most recent loop break, or `null` once it has
     * aged out ({@see self::FORCED_OBJECTIVE_TTL_TICKS} ticks) — after which the
     * agent is back on the normal ladder.
     *
     * @since 3.1.12
     */
    public function getForcedObjective(int $agent_id, int $tick): ?string
    {
        $entry = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        if ($tick > 0 && ($tick - (int) ($entry['tick'] ?? 0)) > self::FORCED_OBJECTIVE_TTL_TICKS) {
            return null;
        }

        return ((string) ($entry['objective'] ?? '')) ?: null;
    }

    /**
     * Whether a loop break happened too recently to break again — the forced
     * objective (and the brain turns after it) need a few ticks to actually
     * change the situation before the loop detector is allowed to fire once more.
     * Without this the loop-break moves themselves keep the "no productive
     * action" window full and it thrashes every turn.
     *
     * @since 3.1.14
     */
    public function loopBreakCooldownActive(int $agent_id, int $tick): bool
    {
        $entry = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        if (! is_array($entry) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) ($entry['tick'] ?? 0)) < self::LOOP_BREAK_COOLDOWN_TICKS;
    }

    /**
     * The agent's persisted strategic stance and the tick it last changed
     * (`{stance, tick}`). Defaults to `expansionist` (the mission stance) at tick 0.
     *
     * @return array{stance: string, tick: int}
     *
     * @since 3.1.23
     */
    public function getStance(int $agent_id): array
    {
        $e = $this->data['agent_stance'][(string) $agent_id] ?? null;

        return [
            'stance' => is_array($e) ? (string) ($e['stance'] ?? 'expansionist') : 'expansionist',
            'tick' => is_array($e) ? (int) ($e['tick'] ?? 0) : 0,
        ];
    }

    /** A rejected `depart` is not retried for this many world ticks. */
    private const DEPART_RETRY_COOLDOWN_TICKS = 12;

    /**
     * Records a rejected `depart`. Always arms a short retry cooldown so an
     * observe/intent window race does not spam the same failing `depart` every
     * tick. When `$permanent` (a thrust-to-weight / capability rejection rather
     * than a closed window), the destination is also parked in the unreachable
     * set for the rest of the run — the engine only reports a TWR shortfall on
     * rejection, so this is the only way to learn the ship cannot make that hop.
     *
     * @since 3.2.34
     */
    public function recordDepartRejection(int $agent_id, string $dest, int $tick, bool $permanent): void
    {
        $key = (string) $agent_id;
        $this->data['agent_depart_block'][$key] = ['dest' => $dest, 'tick' => $tick];
        if ($permanent && $dest !== '') {
            $u = array_values(array_filter((array) ($this->data['agent_depart_unreachable'][$key] ?? []), 'is_string'));
            if (! in_array($dest, $u, true)) {
                $u[] = $dest;
            }
            $this->data['agent_depart_unreachable'][$key] = array_slice($u, -8);
        }
        $this->save();
    }

    /** True while the last rejected `depart` is still on its retry cooldown. */
    public function departRetryCooldownActive(int $agent_id, int $tick): bool
    {
        $e = $this->data['agent_depart_block'][(string) $agent_id] ?? null;
        if (! is_array($e) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) ($e['tick'] ?? 0)) < self::DEPART_RETRY_COOLDOWN_TICKS;
    }

    /**
     * Destinations a `depart` was permanently (TWR / capability) rejected for
     * this run — {@see \NHA\Brain\Ladder::departTarget()} skips them.
     *
     * @return list<string>
     */
    public function departUnreachable(int $agent_id): array
    {
        return array_values(array_filter((array) ($this->data['agent_depart_unreachable'][(string) $agent_id] ?? []), 'is_string'));
    }

    /**
     * Forgets every `depart` verdict for an agent — the retry cooldown and the
     * unreachable set. Called when a new hull is `finalize`d so the fresh ship
     * is not pre-judged by the dead end it replaced.
     *
     * @since 3.2.36
     */
    public function clearDepartRejections(int $agent_id): void
    {
        $key = (string) $agent_id;
        if (! isset($this->data['agent_depart_block'][$key]) && ! isset($this->data['agent_depart_unreachable'][$key])) {
            return;
        }
        unset($this->data['agent_depart_block'][$key], $this->data['agent_depart_unreachable'][$key]);
        $this->save();
    }

    /**
     * A co-op call for help on one body is not repeated for this many ticks
     * (~1h at 2s/tick). The ask is aimed at the other LLM agents playing the
     * world, who read world chat — so it has to be rare enough not to be
     * noise, and repeatable enough that an agent coming online later still
     * hears it.
     */
    private const COLONY_CALL_COOLDOWN_TICKS = 1800;

    /**
     * Records that this agent has broadcast a co-op call for help finishing
     * `$body`'s colony.
     *
     * @since 3.5.0
     */
    public function recordColonyCall(int $agent_id, string $body, int $tick): void
    {
        $this->data['agent_colony_call'][(string) $agent_id][$body] = $tick;
        $this->save();
    }

    /** True while this agent's last call for help on `$body` is still fresh. */
    public function colonyCallCooldownActive(int $agent_id, string $body, int $tick): bool
    {
        $last = $this->data['agent_colony_call'][(string) $agent_id][$body] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::COLONY_CALL_COOLDOWN_TICKS;
    }

    /** A `ride` is not auto-repeated for this many world ticks. */
    private const RIDE_COOLDOWN_TICKS = 12;

    /**
     * Records a `ride` (the no-fuel elevator toggle). {@see \NHA\Brain\Ladder}'s
     * station-keep rung (v3.2.35) rides down once altitude decays under the
     * 300 depart floor, trusting a following on-ground rung to ride straight
     * back up — a rare bounce every ~150 ticks by that design's decay
     * estimate. Live near Earth, orbital decay turned out to cross that floor
     * within a single autoplay turn, so the pair fired on almost every turn:
     * ride down, ride up, ride down, forever, with zero turns actually spent
     * holding (topping fuel/shield/acid_skin) or ever sitting in the depart
     * band long enough for an opening window to find it there. This cooldown
     * forces a few real hold turns between bounces.
     *
     * @since 3.4.10
     */
    public function recordRide(int $agent_id, int $tick): void
    {
        $this->data['agent_last_ride'][(string) $agent_id] = $tick;
        $this->save();
    }

    /** True while the last recorded `ride` is still on its cooldown. */
    public function rideCooldownActive(int $agent_id, int $tick): bool
    {
        $last = $this->data['agent_last_ride'][(string) $agent_id] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::RIDE_COOLDOWN_TICKS;
    }

    /**
     * "This agent has funded its full colony share on `<body>` and is on the
     * way home." A latch, because the observation's `expansion.at_body` /
     * `location` glitch to empty for the odd tick — without it the return
     * state machine goes dormant on those ticks and the agent drifts back into
     * mine / ride churn. Cleared once it is actually home ({@see clearGoingHome()}).
     *
     * @since 3.4.8
     */
    public function setGoingHome(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        if (($this->data['agent_going_home'][$key] ?? null) === $body) {
            return;
        }
        $this->data['agent_going_home'][$key] = $body;
        $this->save();
    }

    /** The body an agent is heading home FROM, or `null`. @since 3.4.8 */
    public function goingHome(int $agent_id): ?string
    {
        $b = $this->data['agent_going_home'][(string) $agent_id] ?? null;

        return is_string($b) && $b !== '' ? $b : null;
    }

    /** Drops the heading-home latch (the agent is back at Earth / in transit to it). @since 3.4.8 */
    public function clearGoingHome(int $agent_id): void
    {
        $key = (string) $agent_id;
        if (! isset($this->data['agent_going_home'][$key])) {
            return;
        }
        unset($this->data['agent_going_home'][$key]);
        $this->save();
    }

    /**
     * Bodies this agent has already funded its full colony share on (or whose
     * colony is complete) — so `depart` stops re-offering the SAME body every
     * cycle and the mission actually spreads across deimos / phobos / mars /
     * venus instead of camping on the first one reached. Pure state; the
     * skip-list semantics live in the caller ({@see \NHA\Brain\Ladder::departTarget()}).
     *
     * @since 3.4.9
     */
    public function recordColonyDone(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        $done = array_values(array_filter((array) ($this->data['agent_colony_done'][$key] ?? []), 'is_string'));
        if (in_array($body, $done, true)) {
            return;
        }
        $done[] = $body;
        $this->data['agent_colony_done'][$key] = $done;
        $this->save();
    }

    /** @return list<string> @since 3.4.9 */
    public function colonyDoneBodies(int $agent_id): array
    {
        return array_values(array_filter((array) ($this->data['agent_colony_done'][(string) $agent_id] ?? []), 'is_string'));
    }

    /** Forgets a body is colony-done (e.g. a new module opened up there). @since 3.4.9 */
    public function clearColonyDone(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        $done = array_values(array_filter((array) ($this->data['agent_colony_done'][$key] ?? []), static fn($b): bool => $b !== $body));
        if ($done === (array) ($this->data['agent_colony_done'][$key] ?? [])) {
            return;
        }
        $this->data['agent_colony_done'][$key] = $done;
        $this->save();
    }

    /**
     * Records the agent's stance. `$tick` is only stamped when the stance
     * actually changes, so it marks the last *switch* for the dwell timer.
     *
     * @since 3.1.23
     */
    public function setStance(int $agent_id, string $stance, int $tick): void
    {
        $key = (string) $agent_id;
        $prev = $this->getStance($agent_id);
        if ($prev['stance'] === $stance) {
            return;
        }

        $this->data['agent_stance'][$key] = ['stance' => $stance, 'tick' => $tick];
        $this->save();
    }

    /**
     * Records the agent's current lifetime `inventor_points` and reports whether
     * research is paying *right now* — the score rose this turn, or rose within
     * the last {@see self::RESEARCH_PAYING_WINDOW} seconds.
     *
     * Inventor points never drop (a rejected invention only refunds ingredients),
     * so an absolute `> 0` test stays true forever once an agent has invented
     * anything. The autoplay fallback needs the recent trend instead: keep
     * speculating while discoveries are still landing, fall to infrastructure
     * once they dry up. The first sighting only sets the baseline and returns
     * `false` (no trend yet).
     *
     * @since 3.1.9
     */
    public function noteInventorPoints(int $agent_id, int $points): bool
    {
        $key = (string) $agent_id;
        $prev = $this->data['agent_inventor_points'][$key] ?? null;
        $now = time();

        if (! is_array($prev)) {
            $this->data['agent_inventor_points'][$key] = ['value' => $points, 'rose_at' => 0];
            $this->save();

            return false;
        }

        $roseNow = $points > (int) ($prev['value'] ?? 0);
        $roseAt = $roseNow ? $now : (int) ($prev['rose_at'] ?? 0);

        if ($roseNow || $points !== (int) ($prev['value'] ?? 0)) {
            $this->data['agent_inventor_points'][$key] = ['value' => $points, 'rose_at' => $roseAt];
            $this->save();
        }

        return $roseNow || ($roseAt > 0 && ($now - $roseAt) < self::RESEARCH_PAYING_WINDOW);
    }

    /**
     * Remembers how much fuel the ship actually needs for a transfer, solved
     * from the engine's Δv rejection by
     * {@see \NHA\Brain\Ladder::fuelTargetFromRejection()}.
     *
     * Stored rather than recomputed every turn because the derivation needs
     * the fuel load AT THE MOMENT OF THE REJECTION: buying more fuel changes
     * `L` and would skew the mass that falls out of the curve. Capture once,
     * then spend against it.
     *
     * @since 3.5.5
     */
    public function recordFuelGoal(int $agent_id, int $units): void
    {
        $this->data['agent_fuel_goal'][(string) $agent_id] = $units;
        $this->save();
    }

    /** The stored Δv-derived fuel goal, or 0 when none has been learned yet. */
    public function fuelGoal(int $agent_id): int
    {
        return (int) ($this->data['agent_fuel_goal'][(string) $agent_id] ?? 0);
    }
}
