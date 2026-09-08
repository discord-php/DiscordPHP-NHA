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
     * (`{stance, tick}`). Defaults to `homestead` at tick 0.
     *
     * @return array{stance: string, tick: int}
     *
     * @since 3.1.23
     */
    public function getStance(int $agent_id): array
    {
        $e = $this->data['agent_stance'][(string) $agent_id] ?? null;

        return [
            'stance' => is_array($e) ? (string) ($e['stance'] ?? 'homestead') : 'homestead',
            'tick' => is_array($e) ? (int) ($e['tick'] ?? 0) : 0,
        ];
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
}
