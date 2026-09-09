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
 * Verified NHA world constants, transcribed from the game's PUBLIC SOURCE.
 *
 * Repo: https://github.com/Recluse/nha-mmo
 *  - `engine/vehicles.py`  → {@see PART}, {@see BUILD_COST}, {@see PART_UPGRADES},
 *                            {@see finalizeStats()} (a faithful port of
 *                            `finalize_stats`, integer-for-integer).
 *  - `engine/engine.py`    → {@see GRAVITY}, {@see TWR_DEPART}, {@see DV_NEED}.
 *  - `engine/crafting.py`  → {@see CRAFT} (the physics-pattern recipe tree).
 *
 * WHY THIS FILE EXISTS. For weeks the brain steered the agent with *guessed*
 * ship mechanics scattered as prose across {@see Playbook} and {@see Ladder}
 * ("it's SCALE", "engine-heavy", a 34-part mega-bundle) — every guess wrong,
 * every correction another probe. The mechanics are not guesswork: they are
 * ~40 lines of closed-form integer arithmetic in `vehicles.py`. This class is
 * the single transcription of that arithmetic. When a recipe or a gate needs
 * revisiting, change it HERE, check it against the cited source file, and let
 * {@see GameDataTest} prove the flyer still flies — do not re-probe the live
 * API and do not re-scatter constants into the callers.
 *
 * Transcribed 2026-09-09 (NHA world API v3 / Expansion era, Season 5).
 *
 * @since 3.2.27
 */
final class GameData
{
    /** `finalize_stats` tuning coefficients (`vehicles.py`: `K_V, K_LIFT, G`). */
    public const K_V = 90;
    public const K_LIFT = 1;
    public const K_G = 10;

    /**
     * The grand-goal lift-off gate (`engine.py`): a vehicle leaves the ground
     * only if `thrust >= GRAVITY * mass`. `launch` checks exactly this; `depart`
     * checks `thrust >= TWR_DEPART[dest] * GRAVITY * mass` on top of it.
     */
    public const GRAVITY = 4;

    /** Terminal-maneuver thrust-to-weight each destination's `depart` demands (`engine.py` `TWR_DEPART`). */
    public const TWR_DEPART = [
        'deimos' => 0.5, 'phobos' => 0.5, 'mars' => 0.7, 'venus' => 0.9, 'earth' => 0.5,
    ];

    /** Δv (km/s ×10) each destination's transfer needs — ship `fuel_cap` + fuel must clear it (`engine.py` `DV_NEED`). */
    public const DV_NEED = ['deimos' => 50, 'phobos' => 55, 'mars' => 100, 'venus' => 130];

    /** Items HELD (not on the ship) and consumed on arrival (`engine.py` `BODY_ITEMS`). */
    public const BODY_ITEMS = [
        'deimos' => [], 'phobos' => [],
        'mars' => ['heat_shield'], 'venus' => ['heat_shield', 'acid_skin'],
    ];

    /**
     * Per-part integer physics constants (`vehicles.py` `PART`). A `build{part}`
     * with no `with:` adds exactly this row to the bundle.
     *
     * @var array<string,array<string,int>>
     */
    public const PART = [
        'frame' => ['mass' => 80, 'strength' => 200],
        'panel' => ['mass' => 30, 'strength' => 60],
        'engine' => ['mass' => 150, 'power' => 200],
        'wheel' => ['mass' => 25, 'drive' => 1, 'traction' => 120],
        'propeller' => ['mass' => 40, 'thrust_pp' => 1],
        'jet' => ['mass' => 120, 'thrust' => 400],
        'wing' => ['mass' => 50, 'wing_area' => 12],
        'tail' => ['mass' => 20, 'wing_area' => 3, 'maneuver' => 5],
        'cockpit' => ['mass' => 40, 'control' => 1, 'maneuver' => 3],
        'fuel_tank' => ['mass' => 30, 'fuel_cap' => 200],
        'landing_gear' => ['mass' => 35, 'gear' => 1],
    ];

    /** Raw materials each `build{part}` consumes (`vehicles.py` `BUILD_COST`). */
    public const BUILD_COST = [
        'frame' => ['metal' => 5], 'panel' => ['metal' => 3], 'wheel' => ['metal' => 2],
        'engine' => ['metal' => 8, 'crystal' => 1], 'propeller' => ['metal' => 4],
        'jet' => ['metal' => 10, 'crystal' => 2], 'wing' => ['metal' => 4], 'tail' => ['metal' => 2],
        'cockpit' => ['metal' => 4, 'crystal' => 1], 'fuel_tank' => ['metal' => 3], 'landing_gear' => ['metal' => 3],
    ];

    /**
     * `part => { with-item => stat deltas }` (`vehicles.py` `PART_UPGRADES`).
     * The `with:` item is consumed 1 per part, ON TOP of {@see BUILD_COST}, and
     * applies these flat deltas. This is the ONLY bridge from the crafting tree
     * to vehicle stats — and `jet + ion_thruster` is the ONLY route to
     * `orbital_engine` (which `depart` requires).
     *
     * @var array<string,array<string,array<string,int>>>
     */
    public const PART_UPGRADES = [
        'frame' => [
            'steel' => ['strength' => 150],
            'alloy' => ['strength' => 80, 'mass' => -30],
            'composite' => ['strength' => 120, 'mass' => -40],
            'superalloy' => ['strength' => 180, 'mass' => -50],
        ],
        'wheel' => [
            'alloy' => ['traction' => 60, 'mass' => -8],
            'bearing' => ['traction' => 40],
            'rubber' => ['traction' => 70],
        ],
        'engine' => [
            'engine' => ['power' => 150],
            'motor' => ['power' => 100],
            'steel' => ['power' => 60],
        ],
        'wing' => [
            'alloy' => ['wing_area' => 6, 'mass' => -15],
            'composite' => ['wing_area' => 5, 'mass' => -25],
        ],
        'tail' => ['alloy' => ['maneuver' => 4, 'mass' => -8]],
        'propeller' => [
            'bearing' => ['thrust_pp' => 1],
            'alloy' => ['mass' => -12],
        ],
        'jet' => [
            'steel' => ['thrust' => 150, 'mass' => 20],
            'ion_thruster' => ['thrust' => 300, 'mass' => -40],
        ],
        'cockpit' => [
            'chip' => ['maneuver' => 5, 'control' => 1],
            'glass' => ['maneuver' => 2],
            'lens' => ['control' => 1],
            'casing' => ['mass' => -10],
        ],
        'fuel_tank' => [
            'steel' => ['fuel_cap' => 120],
            'casing' => ['fuel_cap' => 100, 'mass' => -8],
        ],
        'panel' => [
            'plastic' => ['mass' => -12],
            'casing' => ['strength' => 30, 'mass' => -15],
        ],
    ];

    /**
     * The physics-pattern crafting tree (`crafting.py` `HINTS` / `RULES`), as
     * plain ingredient prose. `combine` matches on aggregated PROPERTY TAGS, not
     * these exact names — any mixture carrying the tags works — but these are the
     * cheapest known set. Everything the flyer needs is here.
     *
     * @var array<string,string>
     */
    public const CRAFT = [
        'wire' => 'a ductile conductor metal (copper / aluminum), drawn',
        'chip' => 'silicon (semiconductor) + wire/copper (conductor)',
        'composite' => 'a light metal (aluminium) + carbon',
        'bearing' => 'a metal + oil (lubricant)',
        'steel' => 'iron + carbon, smelted with heat',
        'alloy' => '2 metals melted with heat (no electrolyte)',
        'motor' => 'a magnet/iron + a conductor + a battery',
        'battery' => '2 distinct metals + electrolyte (water + salt/sulfur)',
        'ion_thruster' => 'a fusion fuel (helium3 / iridium) + a motor (power) + a chip/silicon (semiconductor)',
        'heat_shield' => 'a superalloy (heat-proof) + a composite (shaped, hard)',
        'acid_skin' => 'acid (sulfur / cloud_acid) + rubber (grip, elastic)',
        'hydrogen' => 'water electrolysed by a motor/engine (a power source, no metal)',
    ];

    /**
     * The reference FLYER — the lightest bundle {@see finalizeStats()} scores as
     * `flies` + `orbital_engine` + launch-capable. Keep {@see Ladder::SHIP_BUNDLE_TARGET}
     * in step with this; {@see GameDataTest} asserts this exact bundle flies.
     *
     * `part => [count, with-item|null]`
     *
     * @var array<string,array{0:int,1:string|null}>
     */
    public const FLYER = [
        'frame' => [1, 'composite'],
        'cockpit' => [1, 'chip'],
        'jet' => [1, 'ion_thruster'],
        'engine' => [3, 'engine'],
        'propeller' => [2, 'bearing'],
        'wing' => [3, 'composite'],
        'tail' => [1, null],
        'fuel_tank' => [2, null],
        'landing_gear' => [1, null],
    ];

    /**
     * Integer square root — `math.isqrt`: the largest int `r` with `r*r <= $n`.
     * `finalize_stats` uses it for both speeds, so the port must match it exactly
     * (a float `sqrt()` cast would round the wrong way on perfect-square − 1).
     */
    public static function isqrt(int $n): int
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('isqrt of a negative number');
        }
        if ($n < 2) {
            return $n;
        }
        $r = (int) sqrt($n);
        while ($r * $r > $n) {
            --$r;
        }
        while (($r + 1) * ($r + 1) <= $n) {
            ++$r;
        }

        return $r;
    }

    /**
     * The stat row a single `build{part, with}` contributes: the {@see PART}
     * base with each {@see PART_UPGRADES} delta for the fitted items applied.
     *
     * @param list<string> $withItems the crafted items fitted via `with:` (usually 0 or 1)
     *
     * @return array<string,int>
     */
    public static function partStats(string $part, array $withItems = []): array
    {
        $st = self::PART[$part] ?? throw new \InvalidArgumentException("unknown part '{$part}'");
        foreach ($withItems as $u) {
            foreach (self::PART_UPGRADES[$part][$u] ?? [] as $k => $dv) {
                $st[$k] = ($st[$k] ?? 0) + $dv;
            }
        }

        return $st;
    }

    /**
     * Port of `finalize_stats` (`engine/vehicles.py`). Feed it the list of
     * per-part stat rows a `finalize` would sum (build each with
     * {@see partStats()}); it returns the same verdict the engine will:
     * `mass, drag, power, drive_force, thrust, wing_area, controllable,
     * fuel_cap, gear, v_ground, v_air, drives, flies`.
     *
     * @param list<array<string,int>> $statsList
     *
     * @return array{mass:int,drag:int,power:int,drive_force:int,thrust:int,wing_area:int,lift_coef:int,controllable:bool,fuel_cap:int,gear:int,v_ground:int,v_air:int,drives:bool,flies:bool}
     */
    public static function finalizeStats(array $statsList): array
    {
        $s = static fn(string $k): int => (int) array_sum(array_map(static fn(array $d): int => $d[$k] ?? 0, $statsList));

        $mass = $s('mass');
        $power = $s('power');
        $traction = $s('traction');
        $nWheels = count(array_filter($statsList, static fn(array $d): bool => ($d['drive'] ?? 0) !== 0));
        $wingArea = $s('wing_area');
        $control = $s('control');

        $drag = max(1, intdiv($mass, 20));
        $drive = min($power, $traction);
        $thrust = $s('thrust') + array_sum(array_map(static fn(array $d): int => $d['thrust_pp'] ?? 0, $statsList)) * $power;
        $liftCoef = $wingArea * self::K_LIFT;

        $vGround = $drive > 0 ? self::isqrt(intdiv(self::K_V * $drive, $drag)) : 0;
        $vAir = $thrust > 0 ? self::isqrt(intdiv(self::K_V * $thrust, $drag)) : 0;

        $drives = $control > 0 && $nWheels >= 1 && $drive > 0;
        $flies = $control > 0 && $liftCoef * $vAir * $vAir >= self::K_G * $mass;

        return [
            'mass' => $mass, 'drag' => $drag, 'power' => $power, 'drive_force' => $drive,
            'thrust' => $thrust, 'wing_area' => $wingArea, 'lift_coef' => $liftCoef,
            'controllable' => $control > 0, 'fuel_cap' => $s('fuel_cap'), 'gear' => $s('gear'),
            'v_ground' => $vGround, 'v_air' => $vAir, 'drives' => $drives, 'flies' => $flies,
        ];
    }

    /**
     * Roll up a `part => [count, with-item|null]` recipe (e.g. {@see FLYER})
     * into a {@see finalizeStats()} verdict, plus the two things the stats alone
     * do not carry: `orbital_engine` (any part fitted with `ion_thruster`) and
     * the launch / per-destination `depart` thrust gates.
     *
     * @param array<string,array{0:int,1:string|null}> $recipe
     *
     * @return array<string,mixed> the finalizeStats row + `orbital_engine:bool`, `can_launch:bool`, `depart:array<string,bool>`
     */
    public static function assess(array $recipe): array
    {
        $rows = [];
        $orbital = false;
        foreach ($recipe as $part => [$count, $with]) {
            $withItems = $with !== null ? [$with] : [];
            if ($with === 'ion_thruster' && isset(self::PART_UPGRADES[$part]['ion_thruster'])) {
                $orbital = true;
            }
            for ($i = 0; $i < $count; ++$i) {
                $rows[] = self::partStats($part, $withItems);
            }
        }

        $stats = self::finalizeStats($rows);
        $mass = $stats['mass'];
        $thrust = $stats['thrust'];

        $depart = [];
        foreach (self::TWR_DEPART as $dest => $twr) {
            $depart[$dest] = $stats['flies']
                && $stats['controllable']
                && $orbital
                && $thrust >= $twr * self::GRAVITY * $mass;
        }

        return $stats + [
            'orbital_engine' => $orbital,
            'can_launch' => $thrust >= self::GRAVITY * $mass,
            'depart' => $depart,
        ];
    }
}
