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
 * lightly reorders the deterministic ladder {@see AgentBrain::suggestion()}, but
 * the survive / defend / arm rungs and the anti-patterns always apply.
 *
 *  - `homestead`   — dig in: stockpile, build towers, hold ground. The default.
 *  - `aggressive`  — a fight is on or a soft target is near, and you are armed:
 *                    press it, keep ammo topped, take bounties.
 *  - `capitalist`  — sitting on a fat credit pile with nothing to build: work
 *                    the depot / market / contracts, buy low and sell high.
 *  - `expansionist`— you are in space, on a body, or a transit window is open
 *                    and you can fly: ride up, invest, extract, colonise.
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
     * one unless a switch is clearly warranted (hysteresis). `aggressive` is the
     * one stance that can pre-empt the dwell timer — a fight will not wait.
     *
     * @param array<string,mixed> $raw          The normalised observation.
     * @param string              $current      The stance in force (its `value`).
     * @param int                 $lastSwitchAt World tick the stance last changed.
     */
    public static function pick(array $raw, string $current, int $lastSwitchAt): self
    {
        $now = (int) ($raw['tick'] ?? 0);
        $currentStance = self::tryFrom($current) ?? self::Homestead;
        $want = self::rank($raw);

        if ($want === $currentStance) {
            return $currentStance;
        }
        // Combat is urgent; everything else waits out the dwell timer.
        if ($want === self::Aggressive || ($now - $lastSwitchAt) >= self::MIN_DWELL_TICKS) {
            return $want;
        }

        return $currentStance;
    }

    /** The stance the situation argues for, ignoring hysteresis. */
    private static function rank(array $raw): self
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);
        $tick = (int) ($raw['tick'] ?? 0);
        $armed = ($has('kinetic_gun') > 0 && $has('slug') > 0) || ($has('energy_weapon') > 0 && $has('energy_cell') > 0);

        // 1. A live fight, and the means to fight back.
        foreach ((array) ($raw['alerts'] ?? []) as $a) {
            $a = (array) $a;
            if (in_array((string) ($a['kind'] ?? ''), ['attacked', 'robbed', 'hit'], true) && $tick - (int) ($a['tick'] ?? 0) <= 30 && $armed) {
                return self::Aggressive;
            }
        }
        // A soft target within reach while armed.
        if ($armed) {
            $myHp = (float) ($raw['hp'] ?? 100);
            foreach ((array) ($raw['nearby_agents'] ?? []) as $ag) {
                $ag = (array) $ag;
                if ((int) ($ag['dist'] ?? 99) <= 15 && (float) ($ag['hp'] ?? 100) < $myHp * 0.6) {
                    return self::Aggressive;
                }
            }
        }

        // 2. Off Earth, or able and cleared to leave it.
        $expansion = (array) ($raw['expansion'] ?? []);
        if (($raw['in_space'] ?? false) || ($expansion['at_body'] ?? null) !== null) {
            return self::Expansionist;
        }
        $windowOpen = false;
        foreach ((array) ($expansion['windows'] ?? []) as $w) {
            $windowOpen = $windowOpen || (is_array($w) && ($w['open'] ?? false));
        }
        if ($windowOpen && $has('ion_thruster') > 0 && ($has('cryo_fuel') > 0 || $has('helium3') > 0)) {
            return self::Expansionist;
        }

        // 3. A fat credit pile and nothing to build with it.
        $credits = $has('credits');
        $canBuildSoon = $has('composite') >= 2 && $has('metal') >= 8;
        if ($credits >= AgentBrain::CREDIT_FLOOR * 6 && ! $canBuildSoon) {
            return self::Capitalist;
        }

        // 4. Dig in.
        return self::Homestead;
    }

    /** The stance-specific block spliced into the system prompt. */
    public function briefing(): string
    {
        return match ($this) {
            self::Homestead => 'STANCE: HOMESTEAD — dig in. Stockpile each raw to a working reserve, then `construct` '
                . 'TALL varied towers and claim a `monument` title. Keep a weapon + ammo + a medicine on hand. '
                . 'Do NOT fly, raid, or day-trade; hold your ground and out-build rivals.',
            self::Aggressive => 'STANCE: AGGRESSIVE — you are armed and a target or a fight is in reach. Keep ammo '
                . 'topped (`buy slug`/`energy_cell`), close to weapon range, and `attack` the weakest hostile or an '
                . 'open bounty. Break off and `heal` below ~35% HP. Do not chase a fight you cannot win.',
            self::Capitalist => 'STANCE: CAPITALIST — credits are the game now. `sell` surplus raws high, `fulfill` '
                . 'contracts whose `want` you cover, post `order`s to buy low / sell high, and only `construct` when '
                . 'a tower is basically free. Bank the pile, then convert it to builder points later.',
            self::Expansionist => 'STANCE: EXPANSIONIST — get off Earth and stay productive there. `move` to an '
                . '`elevator` base and `ride` up; in orbit `dock` an asteroid and `mine` iridium/nickel; `invest` in '
                . 'an open Station module; on a body `construct shape=extractor` and fund the `colony`/`terraform`. '
                . 'Craft `heat_shield` / `acid_skin` / `hydrogen` on Earth BEFORE you `depart`.',
        };
    }
}
