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

/**
 * The strategic stance an agent is playing right now. It is a soft steer, not a
 * script: it re-flavours the system prompt {@see Playbook::systemPrompt()} and
 * lightly reorders the deterministic ladder {@see Ladder::suggestion()}, but
 * the survive / defend / arm rungs and the anti-patterns always apply.
 *
 * There is exactly one goal — the **Solar Accord** (Mars terraformed, Venus
 * held, a Moon base) — and stances exist only to serve it:
 *
 *  - `expansionist` — the mission stance, and the answer for every non-combat
 *                     turn: gear a ship on Earth, fly, colonise, terraform.
 *                     Arming, stockpiling and banking a surplus are tactics the
 *                     expansionist ladder already does in service of the flight.
 *  - `aggressive`   — you are being attacked and can fight back. Survival is a
 *                     precondition for the mission, so this pre-empts it; then
 *                     it hands straight back to `expansionist`.
 *  - `homestead` / `capitalist` — legacy. "Dig in, do not fly" and "credits are
 *                     the game" are not mission strategies, so {@see rank()}
 *                     never chooses them; the cases remain only for a stored
 *                     value mid-dwell and the ladder's contract tactic.
 *
 * @since 3.1.23
 */
enum Stance: string
{
    case Homestead = 'homestead';
    case Aggressive = 'aggressive';
    case Capitalist = 'capitalist';
    case Expansionist = 'expansionist';

    /** Do not flip stance more often than this (world ticks) unless combat forces it. */
    public const MIN_DWELL_TICKS = 40;

    /**
     * Picks the stance for this turn from the observation, holding the current
     * one unless a switch is clearly warranted (hysteresis). `aggressive` (a
     * fight) and `expansionist` (progress toward the Accord) both pre-empt the
     * dwell timer — neither the fight nor the mission waits.
     *
     * @param array<string,mixed> $raw          The normalised observation.
     * @param string              $current      The stance in force (its `value`).
     * @param int                 $lastSwitchAt World tick the stance last changed.
     */
    public static function pick(array $raw, string $current, int $lastSwitchAt): self
    {
        $now = (int) ($raw['tick'] ?? 0);
        $currentStance = self::tryFrom($current) ?? self::Expansionist;
        $want = self::rank($raw);

        if ($want === $currentStance) {
            return $currentStance;
        }
        if ($want === self::Aggressive || $want === self::Expansionist || ($now - $lastSwitchAt) >= self::MIN_DWELL_TICKS) {
            return $want;
        }

        return $currentStance;
    }

    /**
     * The stance the situation argues for, ignoring hysteresis. Only two
     * answers: defend a live fight, or — for everything else — drive the
     * mission. `homestead` / `capitalist` are never returned: holding ground
     * and day-trading do not move the agent toward the Solar Accord, and the
     * expansionist ladder already arms, stockpiles and banks a glut as tactics
     * in service of the flight.
     */
    private static function rank(array $raw): self
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);
        $tick = (int) ($raw['tick'] ?? 0);
        $armed = ($has('kinetic_gun') > 0 && $has('slug') > 0) || ($has('energy_weapon') > 0 && $has('energy_cell') > 0);

        // A live fight, and the means to fight back — survival first, then the
        // mission resumes. (Picking a fight with a passer-by is NOT a stance:
        // it does not further the Accord and only invites a `wanted` tag.)
        foreach ((array) ($raw['alerts'] ?? []) as $a) {
            $a = (array) $a;
            if (in_array((string) ($a['kind'] ?? ''), ['attacked', 'robbed', 'hit'], true) && $tick - (int) ($a['tick'] ?? 0) <= 30 && $armed) {
                return self::Aggressive;
            }
        }

        // Everything else is the mission.
        return self::Expansionist;
    }

    /** The stance-specific block spliced into the system prompt. */
    public function briefing(): string
    {
        return match ($this) {
            self::Homestead => 'STANCE: HOMESTEAD (bootstrap for the mission) — you are not geared for the Accord yet. '
                . 'Buy a weapon + ammo + a medicine, stockpile the metal / composite / credits a ship needs, then '
                . 'gear that ship. Towers are only a credit faucet while you save — the goal is the Solar Accord, so '
                . 'switch to gearing the moment you can afford to.',
            self::Aggressive => 'STANCE: AGGRESSIVE (defend, then resume) — you are under attack and armed. Keep ammo '
                . 'topped (`buy slug`/`energy_cell`), stay at weapon range, and `attack` the one who hit you. Break off '
                . 'and `heal` below ~35% HP. Do NOT hunt passers-by or chase a bounty — clear the threat and get back '
                . 'to the mission.',
            self::Capitalist => 'STANCE: CAPITALIST (fund the mission) — you are sitting on credits the Accord needs '
                . 'spent. `fulfill` a contract whose `want` you already cover, or `sell` a glut past its cap, then pour '
                . 'the cash into ship parts, `heat_shield` / `acid_skin` inputs, or an open colony / terraform / Station '
                . 'board. Do not day-trade for its own sake.',
            self::Expansionist => 'STANCE: EXPANSIONIST — drive for the Solar Accord (Mars terraformed, Venus held, a '
                . 'Moon base). On a body: `land_body`/`land_moon`, then `construct{shape:colony|extractor|terraform}` '
                . 'and fund the board — this is the win. In Earth orbit with a fuelled ion-thruster ship and an open '
                . 'window: `depart` (a moon first — a Forward Base cheapens every later route). On the ground: gear a '
                . 'ship (`build` a spread of parts → `finalize`), craft `heat_shield` / `acid_skin` / `hydrogen`, then '
                . '`ride`/`launch` up. When you CANNOT progress the flight this turn, RESEARCH: `combine` fresh '
                . 'uninvented tag sets from a raw surplus — the drive recipe is undocumented and inventing it is the '
                . 'way through (and it pays inventor points). `invest` spare credits in any open board. Do NOT grind '
                . 'towers — harvest and research instead.',
        };
    }
}
