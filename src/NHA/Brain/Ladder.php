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

    /** Research only fires when at least two raws sit this deep — a genuine surplus, not the stockpile. */
    public const RESEARCH_SURPLUS = 60;

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

        // 1. Assemble anything already crafted.
        if (count((array) ($raw['loose_parts'] ?? [])) > 0) {
            return ['verb' => 'finalize', 'args' => [], 'why' => 'you have loose parts — assemble them into a vehicle'];
        }

        // 1b. Passive income: a finished vehicle that is not out working yet →
        //     `deploy` it to roam and mine autonomously.
        foreach ((array) ($raw['vehicles'] ?? []) as $vehicle) {
            $vehicle = (array) $vehicle;
            if (empty($vehicle['deployed']) && empty($vehicle['roaming']) && empty($vehicle['out'])) {
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
        if ($stanced = self::stanceMove($stance, $raw, $inv, $raws, $credits, $x, $y)) {
            return $stanced;
        }

        // 2. One speculative combine — but ONLY on a genuine material surplus
        //    (research is a luxury, not a grind): at least two raws sitting at
        //    least RESEARCH_SURPLUS deep, on top of the normal stockpile.
        $surplusRaws = array_filter($raws, static fn(int $q): bool => $q >= self::RESEARCH_SURPLUS);
        if ($allowSpeculation && count($surplusRaws) >= 2) {
            $names = array_keys($surplusRaws);
            for ($i = 0; $i < count($names); $i++) {
                for ($j = $i + 1; $j < count($names); $j++) {
                    $pair = [$names[$i], $names[$j]];
                    sort($pair);
                    $sig = implode('+', $pair);
                    if (! isset($tried[$sig]) && ! isset($worldKnown[$sig])) {
                        return [
                            'verb' => 'combine',
                            'args' => ['ingredients' => [$pair[0] => 1, $pair[1] => 1]],
                            'why' => "{$pair[0]}+{$pair[1]} is an untried, uninvented tag set — one shot at inventor points",
                        ];
                    }
                }
            }
        }

        $biggest = $raws === [] ? null : array_key_first($raws);

        // 2b. Off the ground with no orbital work to do (no asteroid to dock and
        //     mine) — descend. `construct` and most harvesting need solid ground;
        //     bouncing on the elevator or idling in orbit scores nothing.
        $offGround = ($raw['in_space'] ?? false) || (int) ($raw['altitude'] ?? 0) > 0;
        if ($offGround && (array) ($raw['asteroids'] ?? []) === []) {
            return ['verb' => 'land', 'args' => [], 'why' => 'nothing to do off the ground — land and build where the materials are'];
        }

        // 3. Build for RELIABLE points — a `construct` tower costs metal (= size)
        //    plus `composite` (= ceil(height/14)); `composite` is aluminium+carbon.
        if ($onGround && $has('composite') >= 2 && $has('metal') >= 8) {
            $shape = ['box', 'cylinder', 'pyramid', 'cone', 'sphere'][$tick % 5];
            $size = min(8, $has('metal'));
            $height = 14 * min($has('composite'), 3);

            return [
                'verb' => 'construct',
                'args' => ['shape' => $shape, 'size' => $size, 'height' => $height, 'name' => 'spire-' . ($tick % 1000)],
                'why' => "you hold the composite + metal a tall {$shape} needs — builder points score every time",
            ];
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
        //     aluminium + carbon and combine them into `composite`. Credits are
        //     only a means — turning them into builder points is the reliable
        //     scorer a stuck ground agent can always reach.
        if ($onGround && $credits >= self::CREDIT_FLOOR) {
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
        //    except in a genuine credit emergency (then keep a token 10).
        if ($biggest !== null) {
            $needCredits = $credits < self::CREDIT_FLOOR;
            $overHoardCap = $raws[$biggest] >= self::HOARD_CAP;
            if ($needCredits || $overHoardCap) {
                $keep = ($needCredits && $raws[$biggest] <= self::RESOURCE_TARGET) ? 10 : self::RESOURCE_TARGET;
                $n = min($raws[$biggest] - $keep, 20);
                if ($n >= 1) {
                    return [
                        'verb' => 'sell',
                        'args' => ['resource' => $biggest, 'n' => $n],
                        'why' => $needCredits
                            ? "credits {$credits} below the " . self::CREDIT_FLOOR . " floor — sell {$n} {$biggest}"
                            : "hoarding {$raws[$biggest]} {$biggest} (cap " . self::HOARD_CAP . ") — sell {$n} of the excess",
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
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function stanceMove(string $stance, array $raw, array $inv, array $raws, int $credits, int $x, int $y): ?array
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
            // Convert any raw above the stockpile target straight to credits.
            $biggest = $raws === [] ? null : array_key_first($raws);
            if ($biggest !== null && $raws[$biggest] > self::RESOURCE_TARGET + 10) {
                return ['verb' => 'sell', 'args' => ['resource' => $biggest, 'n' => min($raws[$biggest] - self::RESOURCE_TARGET, 20)], 'why' => "capitalist stance — bank the {$biggest} surplus"];
            }
        }

        if ($stance === Stance::Expansionist->value) {
            $onGround = ! ($raw['in_space'] ?? false) && (int) ($raw['altitude'] ?? 0) === 0;
            // On a body → build an extractor for standing income.
            if (! $onGround && ($raw['expansion']['at_body'] ?? null) !== null && $has('metal') >= 4) {
                return ['verb' => 'construct', 'args' => ['shape' => 'extractor', 'kind' => 'mine'], 'why' => 'expansionist stance — an extractor keeps paying after you leave'];
            }
            // In orbit near an asteroid → latch on.
            if (($raw['in_space'] ?? false) && (array) ($raw['asteroids'] ?? []) !== []) {
                return ['verb' => 'dock', 'args' => [], 'why' => 'expansionist stance — dock the asteroid, then mine it'];
            }
            // On the ground and stocked → head for the elevator.
            $stocked = $raws !== [] && (int) reset($raws) >= 15;
            if ($onGround && $stocked) {
                foreach ((array) ($raw['elevators'] ?? []) as $e) {
                    $e = (array) $e;
                    if (isset($e['x'], $e['y'])) {
                        return ['verb' => 'move', 'args' => ['x' => (int) $e['x'], 'y' => (int) $e['y']], 'why' => 'expansionist stance — walk to the elevator and ride up'];
                    }
                }
            }
        }

        return null;
    }
}
