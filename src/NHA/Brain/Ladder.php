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
 * The deterministic decision ladder — "what would a sensible agent do here?"
 * computed straight from an observation, no LLM. Two entry points:
 *
 *  - {@see suggestion()} — the full ladder (defend → finish parts → arm → stance
 *    nudge → one speculative combine → build → stockpile → sell a glut →
 *    reposition). {@see PromptBuilder} shows its pick to the model as the
 *    "SUGGESTED next action" anchor, and {@see AutoPlayer} falls back to it
 *    whenever the model's pick is refused by a guardrail.
 *  - {@see defensiveAction()} — just rung 0 (combat), which {@see AutoPlayer}
 *    applies as a hard override before it ever consults the model.
 *
 * Split out of {@see AgentBrain} (which is now only the LLM round-trip) so the
 * fallback logic has one home and its callers stop reaching into that class's
 * statics. The rungs are diagrammed in `docs/PLAYBOOK.md`; keep it in sync.
 *
 * @since 3.1.34
 */
final class Ladder
{
    /**
     * Economy targets for the ladder.
     *
     * - `CREDIT_FLOOR` — keep at least this many credits; only `sell` to climb
     *   back to it, or to fund a project (buy-to-build / invest).
     * - `RESOURCE_TARGET` — stockpile each raw up to here (harvest / walk toward
     *   deposits until reached); never `sell` below it outside a credit emergency.
     * - `HOARD_CAP` — the one non-credit `sell` trigger: shed the excess above
     *   this so a "never sell" rule cannot deadlock into mining forever.
     */
    public const CREDIT_FLOOR = 300;
    public const RESOURCE_TARGET = 30;
    public const HOARD_CAP = 80;

    /**
     * Research fires when at least two raws sit this deep — the stockpile
     * target plus a small margin, so a `combine` never digs into the reserve
     * ("as resources allow"). The mission is blocked on an undocumented
     * mechanic, so inventing new items via `combine` — and the inventor points
     * it pays — is a real way forward, not a luxury.
     */
    public const RESEARCH_SURPLUS = self::RESOURCE_TARGET + 10;

    /**
     * Raw materials the Earth depot actually trades (probed from `GET /depot`).
     * `sell` of anything outside this set is rejected ("depot doesn't trade X")
     * — `brine` and the off-world body resources are the common offenders, and
     * the biggest hoard is often exactly one of them, wedging the sell rung.
     */
    public const DEPOT_TRADEABLE = [
        'ice', 'oil', 'ore', 'coal', 'herb', 'iron', 'salt', 'slug', 'wood', 'algae',
        'metal', 'water', 'carbon', 'copper', 'fungus', 'lichen', 'nickel', 'sulfur',
        'crystal', 'extract', 'iridium', 'silicon', 'aluminum', 'titanium', 'gunpowder',
        'superalloy', 'energy_cell',
    ];

    /**
     * Whether a structure already occupies the agent's own cell — every
     * `construct` there is rejected ("a structure already stands on this cell").
     * Checks `nearby_structures` for one at distance 0 / the exact position.
     *
     * @param array<string,mixed> $raw
     */
    public static function cellOccupied(array $raw): bool
    {
        $pos = (array) ($raw['position'] ?? [0, 0]);
        $x = (int) ($pos[0] ?? 0);
        $y = (int) ($pos[1] ?? 0);
        foreach ((array) ($raw['nearby_structures'] ?? []) as $s) {
            $s = (array) $s;
            if ((int) ($s['dist'] ?? 9) === 0 || ((int) ($s['x'] ?? -1) === $x && (int) ($s['y'] ?? -1) === $y)) {
                return true;
            }
        }

        return false;
    }

    /** A short step to clear ground for a `construct`, biased by tick so it does not oscillate. */
    public static function stepToClearGround(array $raw): array
    {
        $pos = (array) ($raw['position'] ?? [0, 0]);
        $x = (int) ($pos[0] ?? 0);
        $y = (int) ($pos[1] ?? 0);
        [$dx, $dy] = [[4, 0], [0, 4], [-4, 0], [0, -4]][(int) ($raw['tick'] ?? 0) % 4];

        return ['verb' => 'move', 'args' => ['x' => max(0, min(219, $x + $dx)), 'y' => max(0, min(219, $y + $dy))], 'why' => 'this cell is built on — step to clear ground before constructing'];
    }

    /** The biggest hoard the depot will actually buy, or `null`. `$raws` is sorted desc. */
    private static function sellableBiggest(array $raws): ?string
    {
        foreach (array_keys($raws) as $res) {
            if (in_array((string) $res, self::DEPOT_TRADEABLE, true)) {
                return (string) $res;
            }
        }

        return null;
    }

    /**
     * A deterministic "what would the ladder do" pick, surfaced to anchor a weak
     * model. Mirrors {@see Playbook}'s priorities: finish parts → gamble one
     * novel combine → build for reliable points → sell a glut → harvest only
     * when short → reposition. Returns `null` when nothing is obviously right
     * (the model is then on its own).
     *
     * Also reused by {@see AutoPlayer} as the infrastructure fallback when a
     * research `combine` is refused — pass the whole tried+known combine space
     * as both `$tried` and `$worldKnown` and the ladder skips its
     * speculative-combine rung and drops straight to build / wealth / harvest.
     *
     * @param array<string,mixed> $raw              The normalised observation.
     * @param array<string,bool>  $tried            `a+b => true` for combine sets submitted THIS session.
     * @param array<string,bool>  $worldKnown       `a+b => true` for sets the whole world has already invented.
     * @param bool                $allowSpeculation When false, the speculative-combine rung is skipped entirely —
     *                                              used by the infrastructure fallback, where research is finished.
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function suggestion(array $raw, array $tried, array $worldKnown = [], bool $allowSpeculation = true, string $stance = 'homestead'): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $tick = (int) ($raw['tick'] ?? 0);
        $onGround = ! ($raw['in_space'] ?? false) && (int) ($raw['altitude'] ?? 0) === 0;

        // Raw resources on hand (drop currency, craft outputs, combat kit).
        $skip = array_fill_keys([
            'credits', 'engine', 'motor', 'chip', 'frame', 'fuel',
            'slug', 'energy_cell', 'kinetic_gun', 'energy_weapon', 'bomb',
            'medkit', 'stimpack', 'salve', 'antidote', 'tincture',
        ], 1);
        $raws = [];
        foreach ($inv as $k => $qty) {
            if (! isset($skip[$k]) && is_numeric($qty) && $qty > 0) {
                $raws[(string) $k] = (int) $qty;
            }
        }
        arsort($raws);

        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);
        $credits = $has('credits');
        $x = (int) (((array) ($raw['position'] ?? [0, 0]))[0] ?? 0);
        $y = (int) (((array) ($raw['position'] ?? [0, 0]))[1] ?? 0);
        $hp = (float) ($raw['hp'] ?? $raw['health'] ?? 100);
        $hpMax = (float) ($raw['hp_max'] ?? $raw['max_hp'] ?? 100) ?: 100;

        // 0. DEFEND. A recent "attacked" alert, a known robber, or a hostile
        //    close by while hurt = combat. Heal if badly hurt and able, shoot
        //    back if armed and in range, otherwise break contact.
        if ($combat = self::combatMove($raw, $inv, $hp, $hpMax, $x, $y, $tick)) {
            return $combat;
        }

        // 1. Assemble the airframe once the full mega-bundle is on hand. Probed
        //    live: a ~34-part spread with 12 engines, wheels, wings ≤ engines,
        //    a tail and a cockpit finalises `drives=true`; a lighter one, or one
        //    missing the tail/cockpit, is an inert hull.
        if (self::megaBundleReady(self::looseParts($raw)) && ! self::hasOrbitalShip($raw)) {
            return ['verb' => 'finalize', 'args' => [], 'why' => 'full mega-airframe on hand — finalise it (drives=true) and deploy'];
        }

        // 1b. Passive income: a finished vehicle that is not out working yet →
        //     `deploy` it to roam and mine autonomously. An expansionist keeps
        //     an orbital-engine ship to FLY, not to deploy for mining. Skip an
        //     inert hull — `deploy` rejects "no vehicle that drives or flies".
        foreach ((array) ($raw['vehicles'] ?? []) as $vehicle) {
            $vehicle = (array) $vehicle;
            $isOrbital = ! empty($vehicle['orbital_engine']) || ! empty($vehicle['ion_thruster']);
            $canWork = ! empty($vehicle['drives']) || ! empty($vehicle['flies']);
            $out = ! empty($vehicle['deployed']) || ! empty($vehicle['roaming']) || ! empty($vehicle['out'])
                || ! empty($vehicle['autonomous']);
            if ($canWork && ! $out && ! ($stance === Stance::Expansionist->value && $isOrbital)) {
                return ['verb' => 'deploy', 'args' => [], 'why' => 'you have a finished vehicle — deploy it for passive mining income'];
            }
        }

        // 1c. ARM. Out of combat but with no way to survive the next ambush —
        //     a medicine, a weapon and some ammo come before research / wealth.
        if ($arm = self::armMove($inv, $credits)) {
            return $arm;
        }

        // 1d. STANCE steer. A few deterministic nudges toward the current stance
        //     before the generic ladder. The prompt carries the rest.
        if ($stanced = self::stanceMove($stance, $raw, $inv, $raws, $credits, $x, $y, $tried, $worldKnown, $allowSpeculation)) {
            return $stanced;
        }

        // 2. One speculative combine on a material surplus — research toward the
        //    goal, not a grind: it only spends raws that sit a margin above the
        //    stockpile target, and never a set already tried or invented.
        if ($allowSpeculation && ($research = self::speculativeCombine($raws, $tried, $worldKnown))) {
            return $research;
        }

        // 2b. Off the ground with no orbital work to do (no asteroid to dock and
        //     mine) — descend. `construct` and most harvesting need solid ground;
        //     bouncing on the elevator or idling in orbit scores nothing.
        //     EXCEPTION: an expansionist holding a fuelled orbital ship should
        //     HOLD in orbit for a transfer window, not land — dropping down
        //     just means climbing back up.
        $offGround = ($raw['in_space'] ?? false) || (int) ($raw['altitude'] ?? 0) > 0;
        $holdingForWindow = $stance === Stance::Expansionist->value
            && (int) ($raw['altitude'] ?? 0) >= 300
            && ($raw['expansion']['at_body'] ?? null) === null
            && self::hasOrbitalShip($raw);
        if ($offGround && (array) ($raw['asteroids'] ?? []) === [] && ! $holdingForWindow) {
            // `land` needs a controllable vehicle; with none it is rejected
            // every tick. Ride/decay down instead.
            if (! self::hasAnyVehicle($raw)) {
                return self::descentWithoutShip($raw)
                    ?? self::noop($raw, 'off the ground with no vehicle — wait out the decay');
            }

            return ['verb' => 'land', 'args' => [], 'why' => 'nothing to do off the ground — land and build where the materials are'];
        }

        // 3. Build for RELIABLE points — a `construct` tower costs metal (= size)
        //    plus `composite` (= ceil(height/14)); `composite` is aluminium+carbon.
        //    Skipped while the expansionist stance is gearing a ship on the
        //    ground: the mission is the Accord, not a field of spires. A ship
        //    already in hand (ion_thruster + fuel) means gearing is done, so a
        //    tower to fund the trip is fine again.
        // An expansionist with no finalized ship is still gearing — the mission
        // is the Accord, not a field of vanity spires. Only once a real ship
        // (not a loose `ion_thruster` resource) is in hold does a tower to fund
        // the trip become fair game again.
        // An expansionist with no ship is still gearing — the mission is the
        // Accord, not a field of vanity spires. (A grounded expansionist that
        // cannot yet build a working ship keeps *experimenting* with parts;
        // grinding builder points is not a substitute for the goal.)
        $gearingShip = $stance === Stance::Expansionist->value && $onGround
            && ! self::hasOrbitalShip($raw);
        if ($onGround && ! $gearingShip && $has('composite') >= 2 && $has('metal') >= 8) {
            if (self::cellOccupied($raw)) {
                return self::stepToClearGround($raw);
            }
            $shape = ['box', 'cylinder', 'pyramid', 'cone', 'sphere'][$tick % 5];
            $size = min(8, $has('metal'));
            $height = 14 * min($has('composite'), 3);

            return [
                'verb' => 'construct',
                'args' => ['shape' => $shape, 'size' => $size, 'height' => $height, 'name' => 'spire-' . ($tick % 1000)],
                'why' => "you hold the composite + metal a tall {$shape} needs — builder points score every time",
            ];
        }

        // 3a-mission. A shipless expansionist HARVESTS to feed research: raise a
        //   couple of raws past the research bar so `speculativeCombine` keeps
        //   finding a fresh pair to gamble. Mine in place, else walk to the
        //   nearest deposit of the raw furthest below the bar.
        if ($gearingShip) {
            $bar = self::RESEARCH_SURPLUS + 5;
            $onIt = null;
            $walkTo = null;
            $walkHeld = $bar;
            foreach ((array) ($raw['nearby_deposits'] ?? []) as $d) {
                $d = (array) $d;
                $res = (string) ($d['resource'] ?? '');
                if ($res === '' || ! in_array($res, self::DEPOT_TRADEABLE, true) && ! in_array($res, ['wood', 'herb', 'lichen', 'fungus', 'algae'], true)) {
                    continue;
                }
                $held = $raws[$res] ?? 0;
                if ($held >= $bar) {
                    continue;
                }
                if ((int) ($d['dist'] ?? 9) === 0) {
                    $onIt ??= [$res, $held, (int) ($d['amount'] ?? 10)];
                } elseif ($held < $walkHeld && isset($d['x'], $d['y'])) {
                    $walkTo = [(int) $d['x'], (int) $d['y'], $res];
                    $walkHeld = $held;
                }
            }
            if ($onIt !== null) {
                [$res, $held, $amt] = $onIt;
                $verb = $res === 'wood' ? 'chop' : (in_array($res, ['herb', 'lichen', 'fungus', 'algae'], true) ? 'gather' : 'mine');

                return ['verb' => $verb, 'args' => ['n' => min($amt, $bar - $held, 15)], 'why' => "harvesting {$res} ({$held}/{$bar}) to feed the next research combine"];
            }
            if ($walkTo !== null) {
                return ['verb' => 'move', 'args' => ['x' => $walkTo[0], 'y' => $walkTo[1]], 'why' => "walking to a {$walkTo[2]} deposit — stocking raws for research"];
            }
        }

        // 3a. STOCKPILE what is under your feet before spending credits: standing
        //     on a deposit of a raw held below target → harvest it up to target.
        foreach ((array) ($raw['nearby_deposits'] ?? []) as $d) {
            $d = (array) $d;
            $res = (string) ($d['resource'] ?? '');
            if ($res === '' || (int) ($d['dist'] ?? 9) !== 0) {
                continue;
            }
            $held = $raws[$res] ?? 0;
            if ($held < self::RESOURCE_TARGET) {
                $verb = $res === 'wood' ? 'chop' : (in_array($res, ['herb', 'lichen', 'fungus', 'algae'], true) ? 'gather' : 'mine');
                $n = min((int) ($d['amount'] ?? 10), self::RESOURCE_TARGET - $held, 15);
                if ($n >= 1) {
                    return ['verb' => $verb, 'args' => ['n' => $n], 'why' => "stockpiling {$res} ({$held}/" . self::RESOURCE_TARGET . ') — standing on a deposit'];
                }
            }
        }

        // 3b. Spend the credit pile toward a tower: buy the metal, then buy
        //     aluminium + carbon and combine them into `composite`. Skipped for
        //     a shipless expansionist — its credits and raws go to the mission
        //     (research / the flight kit), not a field of spires.
        if ($onGround && $credits >= self::CREDIT_FLOOR && ! $gearingShip) {
            if ($has('metal') < 8 && $credits >= 60) {
                return ['verb' => 'buy', 'args' => ['resource' => 'metal', 'n' => 8], 'why' => 'banking metal for a tower — credits are only useful spent'];
            }
            if ($has('composite') < 2 && $has('metal') >= 8) {
                if ($has('aluminum') < 2) {
                    return ['verb' => 'buy', 'args' => ['resource' => 'aluminum', 'n' => 3], 'why' => 'buying aluminium for composite (aluminium + carbon)'];
                }
                if ($has('carbon') < 2) {
                    return ['verb' => 'buy', 'args' => ['resource' => 'carbon', 'n' => 3], 'why' => 'buying carbon for composite (aluminium + carbon)'];
                }

                return ['verb' => 'combine', 'args' => ['ingredients' => ['aluminum' => 1, 'carbon' => 1]], 'why' => 'combining aluminium + carbon into composite for a tower'];
            }
        }

        // 3c. Fund a Station module / colony that is still open — a credit sink
        //     that pays co-op points. No-op while everything is already complete.
        foreach ((array) ($raw['space_station']['modules'] ?? []) as $module) {
            $module = (array) $module;
            if (($raw['in_space'] ?? false) && empty($module['complete']) && $credits >= 200 && isset($module['module'])) {
                return ['verb' => 'invest', 'args' => ['module' => (string) $module['module'], 'credits' => min(200, intdiv($credits, 3))], 'why' => "funding the {$module['module']} module — co-op points from spare credits"];
            }
        }

        // 4. SELL — only to keep credits working, or to shed an absurd hoard.
        //    A healthy agent keeps its raws for building; it does not dump them
        //    for cash it does not need. Never dips below the stockpile target
        //    except in a genuine credit emergency (then keep a token 10). Only
        //    sell what the depot will actually buy — the biggest hoard is often
        //    `brine` (untradeable), which otherwise wedges this rung.
        $sellRes = self::sellableBiggest($raws);
        if ($sellRes !== null) {
            $needCredits = $credits < self::CREDIT_FLOOR;
            $overHoardCap = $raws[$sellRes] >= self::HOARD_CAP;
            if ($needCredits || $overHoardCap) {
                $keep = ($needCredits && $raws[$sellRes] <= self::RESOURCE_TARGET) ? 10 : self::RESOURCE_TARGET;
                $n = min($raws[$sellRes] - $keep, 20);
                if ($n >= 1) {
                    return [
                        'verb' => 'sell',
                        'args' => ['resource' => $sellRes, 'n' => $n],
                        'why' => $needCredits
                            ? "credits {$credits} below the " . self::CREDIT_FLOOR . " floor — sell {$n} {$sellRes}"
                            : "hoarding {$raws[$sellRes]} {$sellRes} (cap " . self::HOARD_CAP . ") — sell {$n} of the excess",
                    ];
                }
            }
        }

        // 5. Nothing here to harvest — walk to the nearest deposit of whatever
        //    you are furthest below target on, to top the stockpile up.
        $wantRes = null;
        $wantXy = null;
        $wantHeld = self::RESOURCE_TARGET;
        foreach ((array) ($raw['nearby_deposits'] ?? []) as $d) {
            $d = (array) $d;
            $res = (string) ($d['resource'] ?? '');
            $held = $raws[$res] ?? 0;
            if ($res !== '' && $held < $wantHeld && isset($d['x'], $d['y'])) {
                $wantRes = $res;
                $wantHeld = $held;
                $wantXy = [(int) $d['x'], (int) $d['y']];
            }
        }
        if ($wantXy !== null) {
            return ['verb' => 'move', 'args' => ['x' => $wantXy[0], 'y' => $wantXy[1]], 'why' => "stockpile low on {$wantRes} ({$wantHeld}/" . self::RESOURCE_TARGET . ') — walk to that deposit'];
        }

        return null;
    }

    /**
     * The defensive move when the agent is in (or just came out of) a fight:
     * heal if badly hurt and able, shoot back if armed and the attacker is in
     * range, otherwise break contact. Returns `null` when there is no threat.
     *
     * Public so {@see AutoPlayer} can apply it as a hard override — combat
     * defence never waits on the LLM.
     *
     * @param array<string,mixed> $raw The normalised observation.
     *
     * @return array{verb: string, args: array<string,mixed>, reason: string}|null
     */
    public static function defensiveAction(array $raw): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $pos = (array) ($raw['position'] ?? [0, 0]);
        $move = self::combatMove(
            $raw,
            $inv,
            (float) ($raw['hp'] ?? $raw['health'] ?? 100),
            (float) ($raw['hp_max'] ?? $raw['max_hp'] ?? 100) ?: 100,
            (int) ($pos[0] ?? 0),
            (int) ($pos[1] ?? 0),
            (int) ($raw['tick'] ?? 0),
        );

        return $move === null ? null : ['verb' => $move['verb'], 'args' => $move['args'], 'reason' => $move['why']];
    }

    /**
     * Whether the agent holds a `finalize`d ship that can fly to orbit — a
     * vehicle with an orbital engine (and, where the field is present, one that
     * still `flies`). Used so gear-up / depart logic recognises a ship whose
     * `ion_thruster` was consumed into it.
     *
     * @param array<string,mixed> $raw
     */
    public static function hasOrbitalShip(array $raw): bool
    {
        foreach ((array) ($raw['vehicles'] ?? []) as $v) {
            $v = (array) $v;
            $engine = ! empty($v['orbital_engine']) || ! empty($v['ion_thruster']) || ($v['orbital_engine'] ?? null) === 1;
            if ($engine && ($v['flies'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the agent has ANY finalized vehicle in hold — orbital or not. A
     * loose `ion_thruster` *resource* in the inventory is NOT a vehicle: `land`,
     * `land_body` and `land_moon` all reject with "no controllable vehicle to
     * land with" until a `finalize` has actually produced one.
     *
     * @param array<string,mixed> $raw
     */
    public static function hasAnyVehicle(array $raw): bool
    {
        foreach ((array) ($raw['vehicles'] ?? []) as $v) {
            $v = (array) $v;
            if (! empty($v['drives']) || ! empty($v['flies'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `finalize`d vehicle that came out inert — no drive, no flight, no
     * orbital engine, zero fuel capacity (what a single stray `landing_gear`
     * assembles into). It cannot `deploy`, `land` or fly; the fix is to build
     * more parts and `finalize` again, not to keep poking this one.
     *
     * @param array<string,mixed> $raw
     */
    public static function hasDeadHull(array $raw): bool
    {
        return self::inertVehicleCount($raw) > 0;
    }

    /**
     * How many `finalize`d-but-inert hulls the agent has piled up (no drive, no
     * flight, no orbital engine, zero fuel capacity). Past a couple of these the
     * `finalize` recipe is clearly still missing its drive part — stop spending
     * loose parts on more junk and let the part search catch up.
     *
     * @param array<string,mixed> $raw
     */
    public static function inertVehicleCount(array $raw): int
    {
        $n = 0;
        foreach ((array) ($raw['vehicles'] ?? []) as $v) {
            $v = (array) $v;
            $useless = empty($v['drives']) && empty($v['flies']) && empty($v['orbital_engine']);
            if ($useless && (int) ($v['fuel_cap'] ?? 0) === 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The `build` part archetypes already sitting in the loose-part bundle, so
     * the gear-up rung builds VARIETY (a `finalize` scores on distinct parts +
     * materials) instead of five of the same.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<string>
     */
    public static function looseParts(array $raw): array
    {
        return array_values(array_filter(array_map(
            static fn($p): string => is_array($p) ? (string) ($p['part'] ?? $p['name'] ?? '') : (string) $p,
            (array) ($raw['loose_parts'] ?? []),
        )));
    }

    /**
     * The DIRECTED drive-research chain — the concrete craft path a working
     * ship needs, reverse-engineered from the agents that have flying vehicles
     * (`codex-inventor`'s "steel-engine" / "penta-engine" ships) and the live
     * `/rules` dynamic recipes:
     *
     *   iron + magnet + wire        → motor
     *   engine + motor              → rocket_engine   (engine + composite also works)
     *   engine + magnet + motor     → advanced_motor
     *   metal + salt + silicon      → battery         (feeds a richer motor line)
     *
     * Then the airframe is built ENGINE-HEAVY: `build{part:engine, with:{<the
     * best propulsion item held>}}` several times before `finalize`. This takes
     * priority over the random {@see speculativeCombine} — it is the actual
     * blocker, not a gamble.
     *
     * @param array<string,mixed> $inv
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function driveChainStep(array $inv): ?array
    {
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);
        $c = static fn(array $ing, string $why): array => ['verb' => 'combine', 'args' => ['ingredients' => $ing], 'why' => $why];

        // steel — the flagship engine upgrade ("steel-engine"). Need ~1 per
        // engine part, so keep a deep stack (iron + carbon). Buy carbon if the
        // smelt is stalled on it and there are credits.
        if ($has('steel') < 6 && $has('iron') > 0) {
            if ($has('carbon') > 0) {
                return $c(['iron' => 1, 'carbon' => 1], 'drive chain — smelt steel (iron + carbon) for steel-engines');
            }
            if ((int) ($inv['credits'] ?? 0) >= 60) {
                return ['verb' => 'buy', 'args' => ['resource' => 'carbon', 'n' => 6], 'why' => 'drive chain — buy carbon to smelt steel for engines'];
            }
        }
        // motor — the fallback engine upgrade + a rocket_engine input.
        if ($has('motor') < 4 && $has('iron') > 0 && $has('magnet') > 0 && $has('wire') > 0) {
            return $c(['iron' => 1, 'magnet' => 1, 'wire' => 1], 'drive chain — combine a motor (iron + magnet + wire)');
        }
        // rocket_engine — an orbital-drive loose part (engine + motor / composite).
        if ($has('rocket_engine') < 2 && $has('engine') > 0 && $has('motor') > 0) {
            return $c(['engine' => 1, 'motor' => 1], 'drive chain — combine a rocket_engine (engine + motor)');
        }
        // advanced_motor — a stronger drive loose part (engine + magnet + motor).
        if ($has('advanced_motor') < 2 && $has('engine') > 0 && $has('magnet') > 0 && $has('motor') > 1) {
            return $c(['engine' => 1, 'magnet' => 1, 'motor' => 1], 'drive chain — combine an advanced_motor (engine + magnet + motor)');
        }

        return null;
    }

    /**
     * The best `with:` item on hand for `build{part:engine}`. The engine part's
     * accepted upgrades are `engine` / `motor` / `steel` (per the engine's own
     * reject text); `codex-inventor`'s flagship flyer is "steel-engine", so
     * `steel` is preferred, then `motor`. `rocket_engine` / `advanced_motor`
     * are NOT engine upgrades — they are fed as their own loose parts.
     */
    public static function bestDriveUpgrade(array $inv): ?string
    {
        foreach (['steel', 'motor'] as $item) {
            if ((int) ($inv[$item] ?? 0) > 0) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The first novel `combine` the agent can afford from its surplus: two raws
     * that each sit at least {@see RESEARCH_SURPLUS} deep, whose sorted `a+b`
     * signature is neither in `$tried` (this run) nor `$worldKnown` (already
     * invented). Returns `null` when there is no surplus pair left to gamble.
     *
     * @param array<string,int>  $raws       Raw resources on hand, sorted desc.
     * @param array<string,bool> $tried      `a+b => true` for sets submitted this run.
     * @param array<string,bool> $worldKnown `a+b => true` for sets already invented.
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function speculativeCombine(array $raws, array $tried, array $worldKnown): ?array
    {
        $surplus = array_keys(array_filter($raws, static fn(int $q): bool => $q >= self::RESEARCH_SURPLUS));
        for ($i = 0; $i < count($surplus); $i++) {
            for ($j = $i + 1; $j < count($surplus); $j++) {
                $pair = [$surplus[$i], $surplus[$j]];
                sort($pair);
                $sig = implode('+', $pair);
                if (! isset($tried[$sig]) && ! isset($worldKnown[$sig])) {
                    return [
                        'verb' => 'combine',
                        'args' => ['ingredients' => [$pair[0] => 1, $pair[1] => 1]],
                        'why' => "{$pair[0]}+{$pair[1]} is an untried, uninvented tag set — research toward the goal",
                    ];
                }
            }
        }

        return null;
    }

    /** Valid `build` parts (probed live). Everything else → "unknown part". */
    public const SHIP_PART_ARCHETYPES = [
        'engine', 'frame', 'wing', 'wheel', 'cockpit', 'landing_gear', 'fuel_tank', 'tail', 'propeller',
    ];

    /**
     * The confirmed `drives=true` mega-airframe (a ~34-part bundle finalises
     * `drives=true` and can be `deploy`ed as an auto-miner; anything under ~28
     * parts finalises inert). `frame` first so a forced-early `finalize` still
     * has a chassis. `flies=true` needs a bigger bundle still — unsolved.
     *
     * @var array<string,int>
     */
    public const SHIP_BUNDLE_TARGET = [
        'frame' => 2, 'engine' => 12, 'wheel' => 6, 'wing' => 6,
        'fuel_tank' => 3, 'landing_gear' => 2, 'tail' => 2, 'cockpit' => 1,
    ];

    /** Minimum loose-part count before a `finalize` is worth the turn. */
    public const SHIP_BUNDLE_MIN = 28;

    /**
     * Whether a loose-part bundle matches the confirmed `drives=true` shape:
     * 30+ parts, 12+ engines, wings no more than engines, and both a `tail` and
     * a `cockpit` (the failed 58-part bundle had neither). `$parts` is the flat
     * list of part-name strings from {@see looseParts()}.
     *
     * @param list<string> $parts
     */
    public static function megaBundleReady(array $parts): bool
    {
        $n = static fn(string $p): int => count(array_filter($parts, static fn(string $q): bool => $q === $p));

        return count($parts) >= 30
            && $n('engine') >= 12
            && $n('wing') <= $n('engine')
            && $n('wheel') >= 4
            && $n('tail') >= 1
            && $n('cockpit') >= 1;
    }

    /**
     * `part => [allowed with: upgrade items]`, as the engine reports them in its
     * rejection text (e.g. `frame can't use ['ion_thruster'] (upgrade options:
     * [...])`). Used so the gear-up rung fits a legal upgrade instead of drawing
     * a rejection. Empty / absent → build the part bare.
     *
     * @var array<string, list<string>>
     */
    public const PART_UPGRADES = [
        'frame' => ['steel', 'alloy', 'composite', 'superalloy'],
        'propeller' => ['bearing', 'alloy'],
        'cockpit' => ['chip', 'glass', 'lens', 'casing'],
        'engine' => ['engine', 'motor', 'steel'],
    ];

    /**
     * A shipless way down from space: `ride` a completed elevator back to the
     * ground if standing on its base cell, walk to the nearest base cell if not,
     * otherwise `wait` and let orbital decay do it. Never returns `land` — with
     * no vehicle that verb is always rejected. Returns `null` when the agent is
     * not actually off the ground.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function descentWithoutShip(array $raw): ?array
    {
        $inSpace = (bool) ($raw['in_space'] ?? false);
        if (! $inSpace && (int) ($raw['altitude'] ?? 0) === 0) {
            return null;
        }

        $pos = (array) ($raw['position'] ?? [0, 0]);
        $x = (int) ($pos[0] ?? 0);
        $y = (int) ($pos[1] ?? 0);
        foreach ((array) ($raw['elevators'] ?? []) as $e) {
            $e = (array) $e;
            if (isset($e['x'], $e['y']) && (int) $e['x'] === $x && (int) $e['y'] === $y) {
                return ['verb' => 'ride', 'args' => [], 'why' => 'no ship up here — ride the elevator back down to the ground'];
            }
        }
        $e0 = (array) (($raw['elevators'][0]) ?? []);
        if (isset($e0['x'], $e0['y'])) {
            return ['verb' => 'move', 'args' => ['x' => (int) $e0['x'], 'y' => (int) $e0['y']], 'why' => 'no ship up here — get to an elevator base to ride down'];
        }

        return self::noop($raw, 'no ship up here — hold for orbital decay back to the ground');
    }

    /**
     * A real idle turn. NHA has no `wait` verb (`"unknown verb"`); `deposit` is
     * the designed self-scoped no-op — it touches a resource you already hold
     * and leaves the balance unchanged. Falls back to a zero-distance `move`
     * when the hold is completely empty.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}
     */
    public static function noop(array $raw, string $why = 'nothing useful to do this turn'): array
    {
        foreach ((array) ($raw['inventory'] ?? []) as $res => $qty) {
            if ($res !== 'credits' && is_numeric($qty) && $qty > 0) {
                return ['verb' => 'deposit', 'args' => ['resource' => (string) $res, 'n' => 1], 'why' => $why];
            }
        }
        $pos = (array) ($raw['position'] ?? [0, 0]);

        return ['verb' => 'move', 'args' => ['x' => (int) ($pos[0] ?? 0), 'y' => (int) ($pos[1] ?? 0)], 'why' => $why];
    }

    /**
     * The tallest completed elevator that actually reaches orbit (height ≥ 300),
     * or `null`. Riding a 120 m spire to "space" only to decay straight back is
     * the classic shipless bounce — the depart gate needs altitude 300–600.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{x: int, y: int, height: int}|null
     */
    public static function orbitElevator(array $raw): ?array
    {
        $best = null;
        foreach ((array) ($raw['elevators'] ?? []) as $e) {
            $e = (array) $e;
            $h = (int) ($e['height'] ?? 0);
            if ($h >= 300 && isset($e['x'], $e['y']) && ($best === null || $h > $best['height'])) {
                $best = ['x' => (int) $e['x'], 'y' => (int) $e['y'], 'height' => $h];
            }
        }

        return $best;
    }

    /** Best self-heal medicine on hand, strongest first, or `null`. */
    private static function bestMedicine(array $inv): ?string
    {
        foreach (['medkit', 'stimpack', 'salve', 'antidote'] as $m) {
            if ((int) ($inv[$m] ?? 0) > 0) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Rung 0: the defensive move when the agent is in (or just came out of) a
     * fight — heal if badly hurt and able, shoot back if armed and the attacker
     * is in range, otherwise break contact. Returns `null` when there is no
     * threat.
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $inv
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function combatMove(array $raw, array $inv, float $hp, float $hpMax, int $x, int $y, int $tick): ?array
    {
        $alerts = (array) ($raw['alerts'] ?? $raw['threats'] ?? []);
        $recentlyHit = false;
        $robber = $raw['last_robbed_by'] ?? null;
        foreach ($alerts as $a) {
            $a = (array) $a;
            if (in_array((string) ($a['kind'] ?? ''), ['attacked', 'robbed', 'hit'], true) && $tick - (int) ($a['tick'] ?? 0) <= 20) {
                $recentlyHit = true;
                $robber ??= $a['by'] ?? null;
            }
        }

        // Nearest other agent, and the nearest that is plausibly the aggressor.
        $nearest = null;
        $hostile = null;
        foreach ((array) ($raw['nearby_agents'] ?? []) as $ag) {
            $ag = (array) $ag;
            $d = (int) ($ag['dist'] ?? 99);
            if ($nearest === null || $d < (int) ($nearest['dist'] ?? 99)) {
                $nearest = $ag;
            }
            if (($robber !== null && (string) ($ag['id'] ?? '') === (string) $robber) || ($recentlyHit && $d <= 6)) {
                if ($hostile === null || $d < (int) ($hostile['dist'] ?? 99)) {
                    $hostile = $ag;
                }
            }
        }

        $hurt = $hpMax > 0 && $hp / $hpMax < 0.6;
        $inCombat = $recentlyHit || ($hostile !== null) || ($hurt && $nearest !== null && (int) ($nearest['dist'] ?? 99) <= 3);
        if (! $inCombat) {
            return null;
        }

        $badlyHurt = $hpMax > 0 && $hp / $hpMax < 0.35;
        $med = self::bestMedicine($inv);

        if ($badlyHurt && $med !== null) {
            return ['verb' => 'heal', 'args' => ['item' => $med], 'why' => "under attack at {$hp}/{$hpMax} HP — heal with {$med}"];
        }

        // Armed and the attacker is in range → return fire.
        $weapon = null;
        foreach (['kinetic_gun' => 'slug', 'energy_weapon' => 'energy_cell', 'bomb' => null] as $w => $ammo) {
            if ((int) ($inv[$w] ?? 0) > 0 && ($ammo === null || (int) ($inv[$ammo] ?? 0) > 0)) {
                $weapon = $w;
                break;
            }
        }
        $target = $hostile ?? $nearest;
        if ($weapon !== null && $target !== null && (int) ($target['dist'] ?? 99) <= 8 && ! $badlyHurt) {
            return ['verb' => 'attack', 'args' => ['weapon' => $weapon, 'target' => (int) ($target['id'] ?? 0)], 'why' => "fighting back with {$weapon} against #" . (int) ($target['id'] ?? 0)];
        }

        // Otherwise break contact: step directly away from the threat.
        if ($target !== null && isset($target['x'], $target['y'])) {
            $dx = $x - (int) $target['x'];
            $dy = $y - (int) $target['y'];
            $mag = max(1, abs($dx) + abs($dy));

            return [
                'verb' => 'move',
                'args' => [
                    'x' => max(0, min(219, $x + (int) round($dx / $mag * 8))),
                    'y' => max(0, min(219, $y + (int) round($dy / $mag * 8))),
                ],
                'why' => 'outgunned — break contact and put distance between you and the attacker',
            ];
        }

        $d = [[9, 0], [0, 9], [-9, 0], [0, -9]][$tick % 4];

        return ['verb' => 'move', 'args' => ['x' => max(0, min(219, $x + $d[0])), 'y' => max(0, min(219, $y + $d[1]))], 'why' => 'just took a hit — move to safer ground'];
    }

    /**
     * Rung 1c: buy a minimum survival kit when out of combat and unequipped — a
     * medicine first, then a weapon, then ammo for it. Returns `null` once the
     * kit is covered or the credits are too thin.
     *
     * @param array<string,mixed> $inv
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function armMove(array $inv, int $credits): ?array
    {
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);

        if (self::bestMedicine($inv) === null && $credits >= 60) {
            return ['verb' => 'buy', 'args' => ['resource' => 'stimpack', 'n' => 1], 'why' => 'no medicine — buy a stimpack so the next ambush is survivable'];
        }

        $hasWeapon = $has('kinetic_gun') > 0 || $has('energy_weapon') > 0;
        if (! $hasWeapon && $credits >= 60) {
            return ['verb' => 'buy', 'args' => ['resource' => 'kinetic_gun', 'n' => 1], 'why' => 'unarmed — buy a kinetic_gun to defend yourself'];
        }

        if ($has('kinetic_gun') > 0 && $has('slug') < 5 && $credits >= 40) {
            return ['verb' => 'buy', 'args' => ['resource' => 'slug', 'n' => 5], 'why' => 'a gun with no ammo is dead weight — buy slugs'];
        }
        if ($has('energy_weapon') > 0 && $has('energy_cell') < 3 && $credits >= 40) {
            return ['verb' => 'buy', 'args' => ['resource' => 'energy_cell', 'n' => 3], 'why' => 'buy energy_cells for the energy_weapon'];
        }

        return null;
    }

    /**
     * Rung 1d: a deterministic nudge toward the active stance, ahead of the
     * generic ladder. Returns `null` for `homestead` (the generic ladder already
     * plays it) and whenever the stance has nothing pressing to add this turn.
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $inv
     * @param array<string,int>   $raws
     * @param array<string,bool>  $tried            Combine sets submitted this run.
     * @param array<string,bool>  $worldKnown       Combine sets already invented.
     * @param bool                $allowSpeculation When false, the research nudge is skipped.
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function stanceMove(string $stance, array $raw, array $inv, array $raws, int $credits, int $x, int $y, array $tried = [], array $worldKnown = [], bool $allowSpeculation = true): ?array
    {
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);

        if ($stance === Stance::Aggressive->value) {
            // Keep ammo deeper than the survival minimum.
            if ($has('kinetic_gun') > 0 && $has('slug') < 15 && $credits >= 40) {
                return ['verb' => 'buy', 'args' => ['resource' => 'slug', 'n' => 10], 'why' => 'aggressive stance — keep the magazine deep'];
            }
            // Close on the weakest reachable target so the combat rung can finish it.
            $myHp = (float) ($raw['hp'] ?? 100);
            $prey = null;
            foreach ((array) ($raw['nearby_agents'] ?? []) as $ag) {
                $ag = (array) $ag;
                $d = (int) ($ag['dist'] ?? 99);
                if ($d <= 15 && $d > 6 && (float) ($ag['hp'] ?? 100) < $myHp * 0.6 && isset($ag['x'], $ag['y'])
                    && ($prey === null || $d < (int) ($prey['dist'] ?? 99))) {
                    $prey = $ag;
                }
            }
            if ($prey !== null) {
                return ['verb' => 'move', 'args' => ['x' => (int) $prey['x'], 'y' => (int) $prey['y']], 'why' => 'aggressive stance — close on #' . (int) ($prey['id'] ?? 0) . ' for the kill'];
            }
        }

        if ($stance === Stance::Capitalist->value) {
            // Work an open contract you already cover.
            foreach ((array) ($raw['contracts'] ?? []) as $c) {
                $c = (array) $c;
                $want = (array) ($c['want'] ?? []);
                $covered = $want !== [];
                foreach ($want as $res => $qty) {
                    $covered = $covered && (int) ($inv[$res] ?? 0) >= (int) $qty;
                }
                if ($covered && isset($c['id'])) {
                    return ['verb' => 'fulfill', 'args' => ['contract_id' => (int) $c['id']], 'why' => 'capitalist stance — fulfil a contract you already cover'];
                }
            }
            // Convert any depot-tradeable raw above the stockpile target to credits.
            $sellRes = self::sellableBiggest($raws);
            if ($sellRes !== null && $raws[$sellRes] > self::RESOURCE_TARGET + 10) {
                return ['verb' => 'sell', 'args' => ['resource' => $sellRes, 'n' => min($raws[$sellRes] - self::RESOURCE_TARGET, 20)], 'why' => "capitalist stance — bank the {$sellRes} surplus"];
            }
        }

        if ($stance === Stance::Expansionist->value) {
            $expansion = (array) ($raw['expansion'] ?? []);
            $atBody = $expansion['at_body'] ?? null;
            $alt = (int) ($raw['altitude'] ?? 0);
            $inSpace = (bool) ($raw['in_space'] ?? false);
            $onGround = ! $inSpace && $alt === 0;

            // Arrived at a body but still in its orbit → put down.
            if ($atBody !== null && $alt > 0) {
                $verb = in_array((string) $atBody, ['moon', 'luna'], true) ? 'land_moon' : 'land_body';

                return ['verb' => $verb, 'args' => [], 'why' => "expansionist — you have reached {$atBody}; land and build the base"];
            }

            // On a body → the mission itself: fund the colony, then the
            // terraform stages; an extractor in between for standing income.
            if ($atBody !== null && $alt === 0) {
                $colony = (array) ($expansion['colony'] ?? []);
                $terraform = (array) ($expansion['terraform'] ?? []);
                if ($colony !== [] && empty($colony['complete']) && $credits >= 200) {
                    $module = (string) ($colony['next_module'] ?? $colony['module'] ?? '');
                    $args = array_filter(['shape' => 'colony', 'body' => (string) $atBody, 'module' => $module], 'strlen');

                    return ['verb' => 'construct', 'args' => $args, 'why' => "expansionist — fund the {$atBody} colony ({$module})"];
                }
                if ($terraform !== [] && ! empty($colony['complete']) && $credits >= 200) {
                    $stage = (string) ($terraform['next_stage'] ?? $terraform['stage'] ?? '');
                    $args = array_filter(['shape' => 'terraform', 'body' => (string) $atBody, 'stage' => $stage], 'strlen');

                    return ['verb' => 'construct', 'args' => $args, 'why' => "expansionist — fund the {$atBody} terraform stage {$stage}"];
                }
                if ($has('metal') >= 4) {
                    return ['verb' => 'construct', 'args' => ['shape' => 'extractor', 'kind' => 'mine', 'body' => (string) $atBody], 'why' => "expansionist — an extractor on {$atBody} keeps paying after you fly home"];
                }
            }

            // A finalized ship is the gate for every flight verb. A loose
            // `ion_thruster` *resource* is just cargo — it cannot `land`,
            // `depart`, or hold an altitude; only a `finalize`d vehicle can.
            $hasShip = self::hasOrbitalShip($raw);
            $fuelled = $has('hydrogen') > 0 || $has('cryo_fuel') > 0 || $has('helium3') > 0;
            $flightReady = $fuelled && $hasShip;

            // In space with no ship → dead end. Altitude decays, every verb up
            // here needs a vehicle, and `land` is rejected outright. Get back
            // down and build one.
            if ($inSpace && ! $hasShip) {
                return self::descentWithoutShip($raw)
                    ?? self::noop($raw, 'expansionist — no ship in space, hold for decay then gear up');
            }

            // In Earth orbit, flight-ready, a window open you can afford → depart
            // (a moon first — a Forward Base discounts every later route).
            if ($inSpace && $alt >= 300 && $atBody === null && $flightReady) {
                $order = ['deimos' => 50, 'phobos' => 55, 'mars' => 100, 'venus' => 130];
                foreach ($order as $dest => $dv) {
                    $w = (array) (($expansion['windows'][$dest] ?? []));
                    if (! empty($w['open'])) {
                        $needShield = in_array($dest, ['mars', 'venus'], true);
                        $needAcid = $dest === 'venus';
                        if ((! $needShield || $has('heat_shield') > 0) && (! $needAcid || $has('acid_skin') > 0)) {
                            return ['verb' => 'depart', 'args' => ['dest' => $dest], 'why' => "expansionist — {$dest} window is open and you are fuelled and shielded"];
                        }
                    }
                }
            }

            // In orbit by an asteroid → grab space metals for parts.
            if ($inSpace && (array) ($raw['asteroids'] ?? []) !== [] && ! ($raw['docked'] ?? false)) {
                return ['verb' => 'dock', 'args' => [], 'why' => 'expansionist — dock the asteroid and mine iridium/nickel for ship parts'];
            }

            // On the ground and NOT flight-ready → GEAR UP toward a WORKING ship.
            // Agents that have flying vehicles (`codex-inventor`'s multi-engine
            // ships) got there via a concrete craft chain, not a random search:
            // motor → rocket_engine / advanced_motor, then an engine-heavy
            // `build` + `finalize`. That chain is the priority here.
            if ($onGround && ! $flightReady) {
                $held = self::looseParts($raw);
                $count = static fn(string $p): int => count(array_filter($held, static fn(string $q): bool => $q === $p));
                $engineParts = $count('engine');

                // A working vehicle needs SCALE. Probed live: bundles under ~28
                // parts always finalise inert (drives=false) no matter the mix;
                // a ~34-part engine-heavy bundle finalises **drives=true**, and
                // that vehicle can be `deploy`ed as an auto-miner (passive
                // income — far better than another inert hull). `flies=true`
                // (for `depart`) needs an even bigger bundle (codex's flyer is
                // ~mass 2000) — chase that once the deployed miners have paid
                // for the materials. So: only `finalize` a near-complete mega.
                if (self::megaBundleReady($held)) {
                    return ['verb' => 'finalize', 'args' => ['name' => 'accord_runner'], 'why' => 'expansionist — finalize the mega-airframe (drives=true) and deploy it'];
                }

                // 1. DRIVE CHAIN — craft the propulsion items. The real blocker.
                if ($drive = self::driveChainStep($inv)) {
                    return $drive;
                }

                // 2. Cheap flight kit from credits.
                if ($has('ion_thruster') === 0 && $credits >= 150) {
                    return ['verb' => 'buy', 'args' => ['resource' => 'ion_thruster', 'n' => 1], 'why' => 'expansionist — buy the depot ion_thruster'];
                }
                if (! $fuelled) {
                    if ($credits >= 60) {
                        return ['verb' => 'buy', 'args' => ['resource' => 'cryo_fuel', 'n' => 3], 'why' => 'expansionist — buy cryo_fuel (a ship runs on it)'];
                    }
                    if ($has('ice') > 0 && ($has('coal') > 0 || $has('oil') > 0)) {
                        return ['verb' => 'combine', 'args' => ['ingredients' => ['ice' => 1, ($has('coal') > 0 ? 'coal' : 'oil') => 1]], 'why' => 'expansionist — combine cryo_fuel'];
                    }
                }
                if ($has('heat_shield') === 0) {
                    if ($has('superalloy') > 0 && $has('composite') > 0) {
                        return ['verb' => 'combine', 'args' => ['ingredients' => ['superalloy' => 1, 'composite' => 1]], 'why' => 'expansionist — combine a heat_shield'];
                    }
                    if ($has('superalloy') === 0 && $credits >= 60) {
                        return ['verb' => 'buy', 'args' => ['resource' => 'superalloy', 'n' => 1], 'why' => 'expansionist — buy superalloy toward a heat_shield'];
                    }
                }

                // 3. BUILD the mega-airframe to the confirmed drives=true
                //    composition (~34 parts): a `frame` chassis FIRST, then
                //    engines to 12, then wheels / wings / tanks / gear. Buy the
                //    metal the parts cost (each `engine` = metal 8 + crystal 1
                //    + steel 1).
                $upg = self::bestDriveUpgrade($inv);
                $canBuild = $has('metal') >= 8 || ($has('metal') < 10 && $credits >= 60);
                // Only start / continue an airframe once we can actually make
                // engines (a steel/motor upgrade + `engine` items on hand) or a
                // bundle is already underway — otherwise it is a dead hull.
                $target = self::SHIP_BUNDLE_TARGET;
                $airframeViable = ($upg !== null && $has('engine') > 0) || $held !== [];
                foreach ($airframeViable ? $target : [] as $part => $want) {
                    if ($count($part) >= $want) {
                        continue;
                    }
                    if ($part === 'engine' && ($upg === null || $has('engine') === 0)) {
                        continue; // need a steel/motor upgrade + an engine item
                    }
                    if (! $canBuild) {
                        break;
                    }
                    if ($has('metal') < 8 && $credits >= 60) {
                        return ['verb' => 'buy', 'args' => ['resource' => 'metal', 'n' => 24], 'why' => "expansionist — stock metal for the {$part}"];
                    }
                    if ($part === 'engine' && $has('crystal') < 2 && $credits >= 60) {
                        return ['verb' => 'buy', 'args' => ['resource' => 'crystal', 'n' => 6], 'why' => 'expansionist — stock crystal (each engine part needs 1)'];
                    }
                    if ($has('metal') < 8) {
                        break; // can't afford — fall through to earn
                    }
                    $args = ['part' => $part];
                    if ($part === 'engine') {
                        $args['with'] = [$upg => 1];
                    } else {
                        foreach (self::PART_UPGRADES[$part] ?? [] as $u) {
                            if ($has($u) > 0) {
                                $args['with'] = [$u => 1];
                                break;
                            }
                        }
                    }

                    return ['verb' => 'build', 'args' => $args, 'why' => "expansionist — build {$part} (" . ($count($part) + 1) . "/{$want}) for the airframe"];
                }

                // 4. Drive chain + airframe done and it still won't fly → a
                //    novel `combine` off the raw surplus (a real shot at the
                //    missing piece, and inventor points), then harvest.
                if ($allowSpeculation && ($research = self::speculativeCombine($raws, $tried, $worldKnown))) {
                    return $research;
                }

                // Nothing pressing — the generic ladder HARVESTS the raws the
                // chain and research need next. No towers.
                return null;
            }

            // On the ground and flight-ready → get to the elevator that
            // actually reaches orbit (300–600). Riding a 120 m spire only
            // decays straight back; if there is no tall elevator, launch.
            if ($onGround && $flightReady) {
                $orbitLift = self::orbitElevator($raw);
                if ($orbitLift !== null) {
                    $here = $orbitLift['x'] === $x && $orbitLift['y'] === $y;

                    return $here
                        ? ['verb' => 'ride', 'args' => [], 'why' => "expansionist — ride the {$orbitLift['height']} m elevator to orbit and depart"]
                        : ['verb' => 'move', 'args' => ['x' => $orbitLift['x'], 'y' => $orbitLift['y']], 'why' => 'expansionist — walk to the tall elevator base, then ride to orbit'];
                }

                return ['verb' => 'launch', 'args' => [], 'why' => 'expansionist — no elevator reaches orbit here; launch the ship toward 300+'];
            }
        }

        return null;
    }
}
