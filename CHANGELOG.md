# Changelog

All notable changes to DiscordPHP-NHA are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project uses
SemVer with the **major tracking the NHA world API version**.

## [Unreleased]

### Added
- `docs/PLAYBOOK.md` — maintained UML (Mermaid) for the autoplay brain: the
  per-turn flow of `AutoPlayer::step()`, the `AgentBrain::suggestion()` ladder,
  the loop guard, and a component diagram. Update it alongside any change to
  that logic.

## [3.2.30] - 2026-09-09

### Fixed
- **Take the window.** With 3.2.29 the agent stocked fuel and reached orbit,
  then — with **deimos, phobos and mars windows all open** and 91 cryo_fuel on
  hand — sat there selling crystal. An open window is a ~30-tick chance and the
  model frittered it away; the deterministic `depart` only ran on a rejection.
  - `Ladder::departTarget()` — the body to leave for right now (cheapest open,
    shielded, fuelled destination) or `null`. `AutoPlayer` **forces `depart`**
    over any other model pick whenever it returns non-null. `stanceMove` uses
    the same helper.
- **Grounded with a ship = go to orbit.** A finished ship on the ground has one
  job: fuel up, ride the tall elevator, depart. `AutoPlayer` now forces the
  ladder's get-to-orbit move (`buy cryo_fuel` → walk to the elevator → `ride` /
  `launch`) over the model's `mine` / `sell` / `combine` wandering.
- **`combine` can't eat flight fuel.** `AutoPlayer::FLIGHT_CONSUMABLES`
  (`cryo_fuel`, `hydrogen`, `heat_shield`, `acid_skin`) — a model
  `combine {cryo_fuel, silicon}` is refused outright (`helium3` stays allowed:
  it is an `ion_thruster` ingredient).

## [3.2.29] - 2026-09-09

### Fixed
- **The "wait for a launch window" hold.** The agent built a `flies` +
  `orbital_engine` ship and rode to Earth orbit — then thrashed for hours
  because every transfer window was shut and it had no graceful way to wait.
  It cycled `mine` / `move` / `land` / `ride` up there, tripped loop detection
  every ~10 turns, and loop-break's objective rotation then did real damage
  (`combine {slug, stimpack}` — destroying its own weapon ammo + medicine —
  and selling stockpile).
  - `Ladder::isHoldingForWindow()` — a depart-capable ship parked in Earth
    orbit with no open window. `AutoPlayer` now **suppresses loop-break** while
    this holds and **forces the deterministic hold** for any model pick except
    a real `depart`.
  - The hold, in order: stock `cryo_fuel` toward a transfer-sized reserve,
    craft/buy a `heat_shield`, `dock`+mine an adjacent asteroid, else idle.
- **Fuel folded into "flight-ready".** `DEPART_FUEL_MIN` (90 units) — a ship
  that reaches orbit on fumes just parks there. The agent now stocks the
  transfer fuel on the ground before riding up, and while holding in orbit.
  `depart` itself still fires on any fuel + an open window (the engine's Δv
  check is the real gate).
- **Combat kit protected from research.** `AutoPlayer::COMBAT_KIT` — the
  loop-break research pass no longer `combine`s away `slug` / `stimpack` /
  weapons (the ladder's own `speculativeCombine()` already filtered these).
- **No second ship.** A model `build` once a flying ship exists is swapped for
  the fallback instead of accreting a redundant hull.

## [3.2.28] - 2026-09-09

### Added
- **Dynamic material hold window.** The stockpile floor and sell cap are no
  longer fixed at `RESOURCE_TARGET` / `HOARD_CAP` — while the agent is gearing a
  ship they widen for exactly the materials the unbuilt parts still consume, and
  relax again as the parts get built.
  - `GameData::remainingShipBill()` — a live bill of materials: the target bundle
    minus what is already in `loose_parts`, each part's upgrade item expanded to
    raws through the new `CRAFT_INPUTS` map, netted against stock. It shrinks as
    parts are built and empties once the ship is finalized.
  - `Ladder::shipMaterialPlan()` / `floorFor()` / `capFor()` — the per-resource
    floor is raised toward the bill (capped at `PLAN_FLOOR_CEIL` = 50) and the
    sell cap toward `need + 10`. Threaded through the stockpile, sell, walk-to-
    deposit and capitalist-sell rungs, and `speculativeCombine()` now skips a raw
    the pending craft still needs even when it is above the research bar.
  - `AutoPlayer::reserveFor()` — the `BUILD_MATERIAL_RESERVE` floor a research /
    loop-break `combine` may not dip below is raised to whatever the live ship
    bill needs, so a research turn can't eat the metal earmarked for the next
    part (it drops back once those parts exist).

## [3.2.27] - 2026-09-09

### Added
- **`NHA\Brain\GameData`** — a single in-repo transcription of the game's public
  source (`github.com/Recluse/nha-mmo`): the `PART` / `BUILD_COST` /
  `PART_UPGRADES` tables, the `GRAVITY` / `TWR_DEPART` / `DV_NEED` gates, the
  `crafting.py` recipe tree, and `finalizeStats()` — a faithful integer port of
  `finalize_stats` so the brain can *predict* whether a bundle drives / flies /
  can depart **before** it spends the credits. `Ladder::flyerReady()` now calls
  it instead of hand-checking a part count. `GameDataTest` pins the port against
  the source so a future edit that breaks the flyer fails CI, not the live
  server. This is the durable fix for "the brain ran for weeks on guessed
  mechanics" — corrections land in `GameData` now, not scattered prose.

### Changed
- **Ship logic rebuilt on the real physics.** Found the game's public source
  (`github.com/Recluse/nha-mmo`); `engine/vehicles.py` `finalize_stats()` is
  closed-form over summed integer PART constants — there is **no scale / part-count
  gate**. The 3.2.20-26 "mega-bundle" premise was wrong on two counts: the small
  probe bundles finalised inert for lack of a **`cockpit`** (control 0 → cannot
  drive or fly), and the 82-engine bundle failed because mass kills v_air.
  - `flies = control≥1 AND wing_area·v_air² ≥ 10·mass`;
    `v_air = isqrt(90·thrust // drag)`, `drag = max(1, mass//20)`,
    `thrust = Σ jet.thrust + (Σ propeller.thrust_pp)·(Σ engine.power)`.
    Propellers **multiply** engine power → a light frame with a few engines +
    propellers beats an engine stack.
  - `launch` needs `thrust ≥ 4·mass` (`GRAVITY = 4`); `depart` also needs
    `orbital_engine`, which is **`true` iff a part was built `with:{ion_thruster}`**
    — and only the `jet` part accepts it (`+300 thrust, -40 mass`). This is the
    seat the 3.2.11 notes flagged as unsolved.
- `Ladder`: `megaBundleReady()` → `flyerReady()` (cockpit + frame + jet + 3
  engines + 2 propellers + 3 wings + fuel_tank); `driveChainStep()` →
  `shipCraftStep()` (crafts the one upgrade item the flyer still lacks, in order:
  wire → composite → chip → bearing → ion_thruster). New `SHIP_BUNDLE_TARGET`
  (~14-part flyer), `SHIP_PART_UPGRADE`, and source-accurate `PART_UPGRADES` /
  `SHIP_PART_ARCHETYPES`. The expansionist gear-up block builds each part `with`
  its upgrade item, buying `metal`/`crystal` as needed.
- `AutoPlayer`: engine-cap and finalize-stub guards re-pointed at
  `flyerReady` / `shipCraftStep` / the new bundle target.
- `Playbook`: `build`/`finalize` hints and mission steps 1/3/5/6 rewritten for
  the flyer recipe and the `flies`/`launch`/`depart` formulas — the old
  "~34-part MEGA-bundle / engine-heavy / drive chain" text is gone.

### Notes
- Target flyer (checked against the formulas): frame+composite · cockpit+chip ·
  jet+ion_thruster · engine ×3 · propeller ×2 (bearing) · wing ×3 (composite) ·
  tail · fuel_tank ×2 · landing_gear ≈ mass 880, thrust 4900, twr 5.6,
  v_air ~100, wing_area ~54 → flies + orbital_engine, ~900-1500 credits.

## [3.2.26] - 2026-09-09

### Added
- **`jet` is a valid `build` part** (probed: `build{part:jet}` → "insufficient
  for jet (need {metal:10, crystal:2})", not "unknown part"). It's the likely
  lift component for `flies=true` — added to `SHIP_PART_ARCHETYPES` and
  `SHIP_BUNDLE_TARGET` (jet x6), so the live agent tries it on its next
  mega-bundle. `rocket_engine` / `advanced_motor` are NOT build parts (only
  combine outputs); `turbine`/`nacelle`/`prop` all "unknown part".

### Fixed
- Cap runaway `build engine`: told "engine-heavy", the model once stacked **82
  engines** into one un-finalisable bundle (`megaBundleReady` needs a tail +
  cockpit it never built). `AutoPlayer` now swaps a `build engine` for the next
  under-target part once the bundle has target + 2 engines.

### Notes
- `flies=true` still unconfirmed but narrowed: it is NOT scale (a 105-part /
  82-engine bundle finalised `drives=true v=9 flies=false` — more parts = lower
  v). It needs high v_air, which our bundles never produce (v_air stays 0);
  `jet` parts are the untested lead. The agent is credit-drained from the
  probe run (~50 credits) — its 4 deployed auto-miners rebuild the reserve.

## [3.2.25] - 2026-09-09

### Fixed
- `build` was not in `ADVANCING_VERBS`, so a long stretch of `build engine`
  toward the mega-bundle tripped the loop guard's "nothing built" churn check
  every 12 turns and kept yanking the agent off the build to sell/mine.
  Building real parts IS progress — `build` now counts as advancing (the
  same-part-3x guard and dead-part list still catch a rejected-build spin).

### Notes
- The mega-bundle build is working: `loose_parts` reached 15/34 (all 12
  engines + 3 frames) before this fix, with the 2 auto-miners feeding income.
  It is slow — ~5000 credits of materials against ~30 credits/turn — but
  steady and loop-free.

## [3.2.24] - 2026-09-09

### Fixed
- Reserve-protect the drive-chain items (`motor`, `rocket_engine`,
  `advanced_motor`, `engine`, `steel`) in `BUILD_MATERIAL_RESERVE` — a
  loop-break `combine` was throwing a hard-won `advanced_motor` into the Guild
  as junk research.

### Notes
- 3.2.23 stopped the `deploy` loop; post-restart the agent builds the
  mega-bundle correctly (`build engine/frame [+steel]`, 9/34 parts and
  growing) with its 2 auto-miners feeding income. It is slow — a full
  ~34-part bundle is ~5000 credits — but on the right track and no longer
  looping.

## [3.2.23] - 2026-09-09

### Fixed
- The `deploy` churn's root cause: rung 1b kept re-emitting `deploy` because
  the observe feed never flags a deployed vehicle as out. Rung 1b now fires
  ONLY while the agent has 0-1 working (`drives`/`flies`) vehicles total — past
  that it assumes the earlier ones are roaming and leaves any further `deploy`
  to the model (with the `AutoPlayer` repeat-suppressor as backstop). The agent
  already has 2 deployed auto-miners, so `deploy` stops firing.

## [3.2.22] - 2026-09-09

### Fixed
- 3.2.21 tamed but did not stop the `deploy` churn: rung 1b still re-offers
  `deploy` because the observe `vehicles[]` never flags the two auto-mining
  hulls as out. `AutoPlayer` now suppresses `deploy` on the FIRST repeat when
  a `drives`/`flies` vehicle is already in hand (it is mining — `auto_mine`
  x85 in the window) — build / earn instead.

## [3.2.21] - 2026-09-09

### Fixed
- After 3.2.20 the agent (holding the 2 probe-built `drives=true` vehicles)
  wedged spamming `deploy` — the observe feed does not reliably flag a
  deployed vehicle, so rung 1b kept re-offering it. Rung 1b now also treats
  `autonomous`/`roaming`/`out` as deployed, and `AutoPlayer` breaks a run of
  2+ `deploy`s in the recent window (fall back to gearing / earning / idle).

## [3.2.20] - 2026-09-09

### Added
- **`drives=true` recipe cracked by live token-probing** (`ship_probe*.py`).
  It is SCALE, not composition: bundles of 4-19 parts always `finalize` inert
  regardless of mix (even 8 steel-engines, even v_air 46). A **~34-part
  mega-bundle** finalises `drives=true`: `frame x2, engine x12 (with steel),
  wheel x6, wing x6, fuel_tank x3, landing_gear x2, tail x2, cockpit x1`.
  A 58-part bundle with `wings > engines` and no tail/cockpit → inert, so:
  wings <= engines, tail + cockpit required. A `drives=true` vehicle `deploy`s
  as an **auto-miner** (passive resource income).
- `Ladder::megaBundleReady()`, `SHIP_BUNDLE_TARGET`, `SHIP_BUNDLE_MIN`. The
  expansionist gear-up + rung 1 + the `AutoPlayer` finalize guard now build to
  and finalize only the full mega-bundle; the engine build stocks `crystal`
  (each engine costs metal 8 + crystal 1 + steel 1). `SHIP_PART_ARCHETYPES`
  trimmed to the 9 confirmed-valid parts.

### Notes
- Each mega-bundle costs ~5000 credits of materials, so the agent earns /
  deploys miners first. **`flies=true` is still unsolved** — 44 parts / 16
  wings stayed `flies=false` (v_air ~15-46 vs codex's 121); it needs a bigger
  bundle still. `flies` + `orbital_engine` gates `depart` to the inner system.

## [3.2.19] - 2026-09-09

### Fixed
- 3.2.18 built 5 steel-engines and `finalize`d a PURE-engine bundle → `v=0`
  (worse than the mixed bundles that hit v≈28). A ship needs STRUCTURE:
  - The gear-up now builds to a **balanced target composition**
    (`frame` ×1 first as the chassis, `engine` ×5, then `wing`/`wheel`/
    `fuel_tank`/`landing_gear`) instead of engines-then-maybe-structure.
  - `finalize` gate = **3+ engines AND a `frame` AND a `wing` or `wheel` AND
    7+ parts AND fuel loaded** (a pure-engine or fuel-less bundle finalises
    inert).
  - The drive chain buys `carbon` when the steel smelt stalls on it (the live
    run ran `carbon` to 0 and the engine builds started rejecting).
  - The airframe is only started once engines are actually makeable (a
    steel/motor upgrade + `engine` items on hand) — no more bare `frame` on a
    lone metal pile.

## [3.2.18] - 2026-09-09

### Fixed
- Follow-up to 3.2.17's live run: the drive chain now executes (agent crafts
  `motor`, `rocket_engine`, `battery`, `steel`), but every `finalize` was still
  inert — bundles had only 1-2 `engine` parts and the engine build was passing
  `with:{rocket_engine}`, which the `engine` part does not accept (its upgrades
  are `engine`/`motor`/`steel`).
  - `bestDriveUpgrade()` now returns **`steel`** (then `motor`) — codex's
    flagship flyer is literally named "steel-engine".
  - Build up to **5** `engine` parts (was 4); `finalize` gate raised to **8+
    parts with 5+ engines** (codex's flyers are quad/penta-engine, mass ~2000),
    in rung 1, the expansionist gear-up, and the `AutoPlayer` finalize guard.
  - `rocket_engine` / `advanced_motor` added as their own loose parts once the
    engines are down (they are not engine `with:` upgrades); `wheel` added to
    the structural spread.

## [3.2.17] - 2026-09-09

### Changed
- **The bot was guided at the wrong altitude — fixed by reading the agents that
  already have flying ships.** `codex-inventor` holds 4+ vehicles with
  `flies:true, drives:true` named "steel-engine" / "triple/quad/penta-engine";
  `Miner`/`Trader` hold the "Cosmonaut" title and are in space; the **Phobos
  Forward Base colony exists** and is being funded with moon-mined `c_regolith`.
  Our agent had ~0 inventor points and 27 inert hulls because the guidance was
  random-pair `combine` research + a rote 1-of-each `build` rotation — it never
  worked the concrete drive-craft chain.
- New `Ladder::driveChainStep()` + `bestDriveUpgrade()`, now the **top priority**
  in the expansionist gear-up (ahead of the flight kit, the random research, and
  everything else): `iron+magnet+wire → motor`; `engine+motor → rocket_engine`
  (or `engine+composite`); `engine+magnet+motor → advanced_motor`;
  `metal+salt+silicon → battery`; `iron+carbon → steel`. Then the airframe is
  built **engine-heavy** — `build{part:engine, with:{rocket_engine|
  advanced_motor|motor|steel:1}}` ×up to 4, plus frame/wing/fuel_tank/
  landing_gear — and `finalize` fires at 7+ parts with 3+ engines (was a
  5-part any-spread stub). `AutoPlayer`'s finalize guard enforces the same.
- Random `speculativeCombine` research demoted below the drive chain + engine
  build (still ahead of harvest/idle; still never towers).
- Playbook / Expansionist steps rewritten around the drive chain.

## [3.2.16] - 2026-09-09

### Changed
- **No more tower grind, and research is the priority.** Per user direction:
  - The `shipBuildStuck` → build-towers fallback is gone. A shipless
    expansionist never `construct`s a spire — the tower rung and rung 3b
    (buy toward `composite`) are gated off for it, and the Playbook's
    `RESEARCH` / `WEALTH` steps + anti-patterns say "not the goal, skip it".
  - `RESEARCH_SURPLUS` dropped 60 → `RESOURCE_TARGET + 10` (40): a speculative
    `combine` now fires "as resources allow", never digging the reserve.
  - New `Ladder::speculativeCombine()` helper, called from **inside the
    expansionist gear-up** (right after the cheap flight kit, *before* any
    part-building) as well as generic rung 2. The mission is blocked on an
    undocumented mechanic, so inventing the missing item via `combine` — and
    the inventor points it pays — is the way forward.
  - A shipless expansionist gets a dedicated harvest rung that tops two raws
    past the research bar, so `speculativeCombine` keeps finding a fresh pair.
  - Deterministic airframe experiment kept but demoted: build one part of each
    valid archetype only while `metal` is stocked and `loose_parts < 5`, then
    `finalize` the spread. `finalize` gate raised to **5** parts (a 4-part stub
    still came out inert); dropped the `looseHasDrivePart` / inert-hull
    circuit-breaker — the junk hulls are cosmetic (no scrap verb) and stopping
    assembly to grind towers was wrong.
  - `SHIP_PART_ARCHETYPES` trimmed to the 8 confirmed-valid + `wheel`/`axle`.
  - Playbook / Expansionist briefing / PLAYBOOK.md rewritten around
    research-first, no towers.

## [3.2.15] - 2026-09-09

### Changed
- **Stances now serve the one goal (the Solar Accord) and nothing else.**
  `Stance::rank()` has two answers: `aggressive` (a live fight — survival is a
  precondition, so it pre-empts) or `expansionist` (every other turn). It never
  returns `homestead` ("dig in, do NOT fly") or `capitalist` ("credits are the
  game") — those steer *away* from the mission, and the expansionist ladder
  already arms, stockpiles and banks a glut as tactics in service of the flight.
  The two cases stay in the enum only for a stale stored value (corrected on the
  next `pick()` — the mission does not wait out the 40-tick dwell) and the
  ladder's contract tactic; their briefings now point back at the mission.
- Dropped the "weak passer-by within reach → `aggressive`" clause: hunting does
  not further the Accord and only risks a `wanted` tag. Aggressive is now purely
  defensive and hands straight back to `expansionist` once the threat is stale.
- Defaults (`Playbook::systemPrompt()`, `getStance()`) default to `expansionist`.

## [3.2.14] - 2026-09-08

### Fixed
- Live loop: with the LLM host briefly unreachable (ETIMEDOUT / 120s timeout),
  the agent ran ladder-only — and a ship-blocked (`shipBuildStuck`) grounded
  expansionist had **no productive rung**: `$gearingShip` still gated out the
  tower rung, so it bottomed out cycling `deposit ice` / `move (33,114)` /
  `ride`. Now `Ladder::shipBuildStuck()` (3+ inert hulls, no orbital ship)
  **lifts the `$gearingShip` tower gate** — towers are the only thing a
  grounded shipless agent can score, so it builds them (and `combine`s
  `composite` toward them via rung 3b) instead of spinning. The tower rung also
  steps to clear ground first when its cell is already built on.

## [3.2.13] - 2026-09-08

### Fixed
- Two more churn sources for the ship-blocked agent, both on the loop-break /
  model path (the ladder was already clean):
  - The loop-strategy's `wealth` objective still picked the biggest raw
    including `brine` → `sell brine` rejected. Now filtered to
    `Ladder::DEPOT_TRADEABLE`.
  - `construct` on the agent's own cell (an elevator base ringed with its old
    spires) → "a structure already stands on this cell", every time. New
    `Ladder::cellOccupied()` / `stepToClearGround()`: the loop-break `build`
    objective and an `AutoPlayer` guard on a model `construct` now step to
    clear ground first.

## [3.2.12] - 2026-09-08

### Fixed
- With ship-building circuit-broken (3.2.11), the agent dropped to the generic
  ladder and immediately wedged on **`sell brine` × 12** — "depot doesn't trade
  brine". The sell rungs picked the biggest hoard blindly, and `brine` (a mining
  byproduct the Earth depot won't buy, 99 held) was it. Both sell rungs now pick
  the biggest **depot-tradeable** raw (`Ladder::DEPOT_TRADEABLE`, probed from
  `GET /depot`), skipping `brine` and off-world body resources.

## [3.2.11] - 2026-09-08

### Fixed
- The 3.2.10 run still piled up hulls (15 now) — the drive-part heuristic was
  wrong (a `propeller` bundle *still* finalizes `drives=false`), and the
  loop-strategy's forced `finalize` bypassed the guard. **Circuit-breaker:**
  once the agent has **3+ inert hulls** and no orbital ship, the expansionist
  `stanceMove` skips the whole gear-up/build/finalize block (`$shipBuildStuck`)
  and `AutoPlayer` refuses any `finalize` (model- or loop-forced) — the agent
  drops to the generic ladder (towers / co-op invest / stockpile) so it scores
  while the assembly recipe stays unsolved. Rungs re-arm automatically if a
  real ship ever appears.

### Changed
- Vocabulary: `engine` is a **valid** part (upgrades `engine`/`motor`/`steel`);
  `motor`, `turbine` are **not** parts (they're combine outputs / upgrade
  items). `cockpit` upgrades: `chip`/`glass`/`lens`/`casing`. **No known part
  accepts `ion_thruster` as a `with:` item** — `frame`, `propeller`, `engine`,
  `cockpit` all enumerate their upgrades and none include it; where the orbital
  drive seats is unsolved. Removed the ion_thruster-on-engine guess.

## [3.2.10] - 2026-09-08

### Fixed
- The 3.2.9 run piled up **10 inert vehicles** — the model kept `finalize`-ing
  driveless part bundles, and the 3.2.7-style "stop if inert hulls exist" gate
  can't be used (no scrap verb → permanent brick). `finalize` is now gated on
  the **bundle itself**: 4+ loose parts *and* one of the drive archetypes
  (`propeller`/`engine`/`motor`/…) present (`Ladder::looseHasDrivePart()`),
  enforced against the model's pick in `AutoPlayer`, not just the ladder's.
- Ship parts **cost metal** (`landing_gear` 3, `cockpit` 4+crystal, `frame`
  5+composite, `fuel_tank` 3, `tail` 2) — the agent was starving the build
  rotation. Gear-up now stocks `metal` to 15 before building parts.

### Changed
- More `build` vocabulary from the live run: **valid** adds `fuel_tank`,
  `propeller` (upgrades `bearing`/`alloy`), `tail`; **rejected** adds `wheels`,
  `body`. `SHIP_PART_ARCHETYPES` / `DEAD_BUILD_PARTS` / `PART_UPGRADES` / the
  Playbook hint updated. Where the `ion_thruster` orbital drive actually seats
  is still unknown (`frame` and `propeller` both refuse it).

## [3.2.9] - 2026-09-08

### Changed
- More of the `build` vocabulary mapped from the 3.2.8 live run:
  **valid** — `landing_gear`, `cockpit`, `wing`, `frame`; **rejected** —
  `chassis`, `hull`, `rotor`, `airframe`, `thruster`. And `frame`'s `with:`
  upgrades are `steel`/`alloy`/`composite`/`superalloy`, **not** `ion_thruster`
  (`Ladder::PART_UPGRADES`). `SHIP_PART_ARCHETYPES` now leads with the confirmed
  four and appends drive-part candidates (`wheel`, `engine`, `motor`,
  `propeller`, …) — an inert `finalize` means the parts have no drive.
  `DEAD_BUILD_PARTS` and the Playbook `build` hint updated to match.
- `finalize` now needs **4+** loose parts (was 3 — three still produced an
  inert hull), and stops once **2 inert hulls** have piled up: the recipe is
  still missing its drive part, so parts are held for the search rather than
  spent on more junk. `Ladder::inertVehicleCount()`.

## [3.2.8] - 2026-09-08

### Fixed
- 3.2.7's "don't `finalize` while an inert hull sits in `vehicles`" gate
  **deadlocked** the assembly: the junk `ship_v1` from the earlier one-part
  finalize is already in the live agent's `vehicles`, and there is no `scrap`
  verb, so `finalize` would have been suppressed forever. Dropped the
  `hasDeadHull()` guard from the `finalize` rungs — the **3+ loose-parts**
  threshold alone stops the one-part junk, and `finalize` on a fuller set can
  now supersede the dead hull. `hasDeadHull()` is kept only for the `deploy`
  skip / override.

## [3.2.7] - 2026-09-08

### Fixed
- The 3.2.6 live run found `build{part:landing_gear}` is a **confirmed hit**
  (`chassis`/`thruster`/`ion_thruster` are not) — but then `finalize`d a ship
  from that one part into an **inert hull** (`drives=false, flies=false,
  fuel_cap=0`), and the agent spent the next dozen turns trying to `deploy` it
  ("no vehicle that drives or flies") until the loop-breaker forced a
  `combine algae+ion_thruster` that **spent a real ion_thruster** into the Guild.
  - `finalize` now waits for **3+ loose parts** (rung 1 and the expansionist
    gear-up), and is suppressed while an inert hull already sits in `vehicles`
    (`Ladder::hasDeadHull()`).
  - `Ladder::hasAnyVehicle()` now means a vehicle that actually `drives` or
    `flies`; rung 1b `deploy` skips an inert hull.
  - Flight-kit items (`ion_thruster`, `heat_shield`, `acid_skin`, `cryo_fuel`,
    `helium3`, `hydrogen`, `landing_gear`, `fuel_tank`) join
    `BUILD_MATERIAL_RESERVE` at 1, so a research / loop-break `combine` can
    never consume the last one.
  - `AutoPlayer` rewrites a model `build` that names a known-dead part
    (`DEAD_BUILD_PARTS`) to the ladder's rotated archetype, and the
    same-part-three-times guard rotates to the next archetype instead of
    stalling (rotating *through* parts is the search, not a loop).
  - Part rotation widened to `Ladder::SHIP_PART_ARCHETYPES`
    (`fuel_tank, landing_gear, wing, wheel, hull, frame, cockpit, rotor,
    airframe, body`), fitting `with:{ion_thruster:1}` on a structural part.

## [3.2.6] - 2026-09-08

### Fixed
- **`wait` is not an NHA verb** — the engine rejects it as *"unknown verb"*, so
  every override and fallback that returned `wait` was a wasted, rejected tick.
  New `Ladder::noop()` returns the designed no-op (`deposit` of one unit already
  held, balance unchanged); `AutoPlayer::idle()` wraps it, and a submit-time
  guard rewrites any lingering `wait` decision. `deposit` replaces `wait` in the
  Playbook verb catalogue and ladder text.

### Changed
- The `build` `part` argument is an **undocumented enum** — 3.2.5's fixed guess
  (`part: thruster`) drew *"unknown part thruster"* every tick, and the live
  model guessed the same. The gear-up rung now **rotates** through the plausible
  archetypes (`fuel_tank`, `landing_gear`, `chassis`, `frame`, `hull`, `wing`,
  `wheel`, `cockpit`) by tick so the deterministic path actually searches the
  vocabulary; a hit lands in `loose_parts` and the rung above it `finalize`s.
  The Playbook's `build` hint and GEAR-FOR-DEPARTURE step carry the same list
  and an explicit "don't repeat a rejected part" rule.

### Notes
- Live run confirmed 3.2.5 killed the `land`-with-no-vehicle spam and the agent
  now crafts a `heat_shield` on the way up — real mission progress. The one
  remaining blocker is discovering a valid `build` part name, which the running
  agent (ladder + live LLM) is now probing.

## [3.2.5] - 2026-09-08

### Fixed
- The real stall behind 3.2.1–3.2.4: the brain treated a loose **`ion_thruster`
  resource** in the inventory as a flyable ship. It is not — a ship exists only
  once `finalize` has produced a `vehicles[]` entry. So the agent believed it
  was "flight-ready", rode a 120 m elevator spire into space, decayed straight
  back, and then spammed `land` (rejected every tick: *"no controllable vehicle
  to land with"*). Live `/observe` on the running agent confirmed `vehicles: []`
  with `ion_thruster: 2` in hold.
  - `$flightReady` now requires `Ladder::hasOrbitalShip()` (a finalized orbital
    vehicle) — never a loose `ion_thruster` resource. Same correction in
    `AutoPlayer`'s vanity-tower / ride / launch / depart override.
  - New `Ladder::hasAnyVehicle()` + `descentWithoutShip()` — a `land` /
    `land_body` / `land_moon` picked with no vehicle is swapped for the real way
    down (ride the elevator, else wait out orbital decay), in the ladder's
    rung 2b and as a hard `AutoPlayer` filter.
  - New `Ladder::orbitElevator()` — ride only an elevator that actually reaches
    orbit (height ≥ 300); a shipless bounce on a 120 m spire scores nothing.
    With a real ship and no tall elevator, `launch` toward 300+ instead.
  - Gear-up rung, kit complete but still no vehicle → issue `build`
    (`part: thruster`, `with: {ion_thruster: 1}`) then `finalize`, rather than
    riding up prematurely. `AutoPlayer` breaks a 3-in-a-row `build` with no
    resulting vehicle so a wrong `part` arg cannot spin quietly.
  - The vanity-spire gate (`$gearingShip`) now holds until a real ship is in
    hand, not just until the kit is bought.

## [3.2.4] - 2026-09-08

### Fixed
- 3.2.3 got the agent to `finalize` its first ship — then stalled: `finalize`
  consumes the loose `ion_thruster` into the vehicle, so `$has('ion_thruster')`
  went back to 0 and every "flight-ready?" check said no. Now a `finalize`d
  vehicle with an `orbital_engine` counts as flight-ready
  (`Ladder::hasOrbitalShip()`), in the ladder and in `AutoPlayer`'s overrides.
- An expansionist ship in orbit no longer `land`s back to Earth when no
  transfer window is open — rung 2b is suppressed for it, and `AutoPlayer`
  swaps a model `land` for `depart` (window open) / `dock` (asteroid) /
  `wait` (hold for the window).
- Rung 1b (`deploy` an idle vehicle for passive mining) skips an expansionist's
  orbital ship — that one is for flying, not deploying.

## [3.2.3] - 2026-09-08

### Fixed
- The 3.2.1/3.2.2 gear-up rung tried to `buy motor` — but `motor` is crafted,
  not stocked, so the depot rejected it and the agent bought motor forever.
  Rewritten against what the depot actually sells (probed live): `ion_thruster`,
  `cryo_fuel` and `superalloy` are all buyable, so a credit-flush grounded
  expansionist now just **buys the ion_thruster and cryo_fuel**, with `combine`
  (motor from magnet+copper+energy_cell, cryo_fuel from ice+coal/oil) as the
  low-credit fallback. `heat_shield` is only chased for the Mars/Venus legs —
  a fuelled ion-thruster ship rides to orbit for a moon hop without one.

## [3.2.2] - 2026-09-08

### Fixed
- Follow-up to 3.2.1 (live behaviour was still ~50% spires): `AutoPlayer` now
  also overrides a **model `construct box/cylinder/sphere/cone/pyramid`** pick
  (not just `ride`/`launch`/`depart`) when the agent is expansionist, grounded
  and shipless — swapping the vanity spire for the gear-up move.
  Colony/terraform/extractor/monument `construct`s pass through.
- `Brain\Ladder` gear-up rung extended: it now also `combine`s **superalloy**
  (metal + wood) toward a `heat_shield`, and its "buy a missing input" fallback
  covers `helium3` and `metal` (a `buy` the depot does not stock is just
  rejected and the ladder moves on).

## [3.2.1] - 2026-09-08

### Fixed
- The 3.2.0 mission re-point flipped the stance and prompt but the agent still
  could not execute the flight chain — it spammed short `construct` spires on
  the ground and once rode the elevator up with no ship and spent a dozen turns
  landing back down. Three changes make the ladder actually drive toward orbit:
  - `Brain\Ladder` expansionist `stanceMove` gains a **GEAR UP** rung: on the
    ground without a fuelled ion-thruster ship it deterministically
    `combine`s the fixed-recipe flight-prep items (`ion_thruster` =
    fusion fuel + motor + semiconductor, `hydrogen` = water + motor,
    `heat_shield` = superalloy + composite), `finalize`s once it holds parts,
    or buys a missing input — never `ride`/`launch` before the ship is ready.
  - The `construct`-tower rung is **skipped** while an expansionist agent is
    gearing a ship on the ground, even when it holds the composite + metal for
    one. A ship already in hand re-opens towers as trip funding.
  - `AutoPlayer` overrides a model `ride`/`launch`/`depart` pick when the
    agent is expansionist, on the ground, and not flight-ready — substituting
    the gear-up suggestion.
- `AutoPlayer::detectLoop()` gains a two-verb-domination check: a window whose
  top two args-stripped verbs own ~85%+ of it with no real forward step
  (`finalize`/`build`/`depart`/`deploy`/`invest`/`land_*`/`dock`) is flagged
  ("spinning on construct/move …"). Catches spire-spam, which slipped past the
  churn check because `construct` counts as advancing.

## [3.2.0] - 2026-09-08

### Changed
- The autoplay brain is re-pointed at the **Solar Accord** era meta-win
  (Mars terraformed, Venus held, a Moon base) instead of grinding the
  leaderboards. Points, towers and trades are now framed as *means to fund the
  mission*, not the goal.
  - `Brain\Playbook` system prompt: a `THE MISSION` block up top; `HOW YOU WIN`
    rewritten as `HOW YOU MOVE THE MISSION` (reach a body & build there → invest
    in co-op boards → fund it on Earth); the decision ladder re-ordered so
    "build on a body" / "go" / "gear for departure" sit above towers, inventing
    and wealth; the elevator anti-pattern relaxed so reaching orbit to `depart`
    is encouraged. `VERBS` gains `depart`, `land_moon`, `land_body`, `distress`,
    `assist` (the LLM could not pick them before) and the expansion `construct`
    shapes.
  - `Brain\Stance::rank()`: **expansionist** is now the default drive the moment
    the agent is minimally geared (weapon + ammo + a medicine) on Earth, not
    only once it is already in space. Homestead is just the pre-armed early
    phase; capitalist keeps its market-work gate.
  - `Brain\Ladder` expansionist `stanceMove`: a real flight chain — `land_*` on
    arrival, `construct` colony/extractor/terraform on the surface, `depart`
    from Earth orbit when fuelled/shielded and a window is open (moon first),
    `dock` for space metals, or walk to the elevator and `ride`.
  - Loop-break objective rotation leads with `expand` (run the expansionist
    ladder) instead of `explore`.

## [3.1.37] - 2026-09-08

### Fixed
- `Brain\Stance::rank()` could latch the **capitalist** stance permanently. It
  ranked capitalist on "fat credit pile + not currently holding
  `composite>=2 && metal>=8`", but the capitalist steer sells surplus and never
  lets the agent assemble those materials — so the exit condition could never
  become true and the agent day-traded forever (observed live: endless
  `buy carbon -> combine aluminium+carbon -> sell` with no `construct`).
  Capitalist now also requires real market work — a contract whose `want` the
  agent covers, or a raw stockpiled past the hoard cap. With neither, the agent
  drops to homestead and spends its credits on a build.
- `AutoPlayer` no longer permanently blacklists a `combine` set that was
  rejected merely for lack of ingredients that turn (`recordDeadCombine` now
  skips production recipes and stock-shortage rejections), and a production
  recipe (`aluminium+carbon → composite`) is fully exempt from the dead list —
  a known-good, re-craftable recipe can't legitimately be "dead". One short
  `aluminum+carbon` had wedged the composite build for good.
- `AutoPlayer::detectLoop()` no longer lets a single repeated `combine` set
  (a production recipe, or one fixation) count as "advancing" — a window whose
  only non-trade action is `combine aluminium+carbon` on repeat is now flagged
  as churn. Two or more distinct combine sets still count as genuine research.

## [3.1.36] - 2026-09-08

### Fixed
- `AutoPlayer::detectLoop()` now catches the churn wedge it was missing: an
  agent that alternates two "productive" verbs forever (`mine ↔ sell`,
  `buy ↔ sell`) with nothing that advances the score. Because both verbs count
  as work and neither exact `verb:args` fingerprint dominated, the dominant-
  action, tail-cycle and traversal-window checks all passed it through, so the
  loop guard never armed and the agent traded in circles indefinitely. A new
  check flags a 10+ turn window containing **zero** advancing actions
  (`construct` / `finalize` / `combine` / `deploy` / `invest` / `fulfill` / …),
  reporting `buy/sell churn`, `churn: <verb>/<verb> on repeat`, or
  `no advancing action for N turns` — which arms the existing forced-objective
  rotation (explore → wealth → build → research).

## [3.1.35] - 2026-09-08

### Changed
- `composer.json` no longer carries a `repositories` block. The published
  package installs from Packagist alone (`team-reflex/discord-php: dev-master`,
  `discord-php/http: dev-master as 10.1.7`); a fresh `git clone` + `composer
  install` no longer depends on sibling checkouts sitting at `../DiscordPHP`.
  For local development against checked-out `DiscordPHP` / `DiscordPHP-Http`,
  put the `path` repositories in your **global** Composer config instead —
  `AGENTS.md` → "Local development against sibling checkouts".

## [3.1.34] - 2026-09-08

### Changed
- `Brain\AgentBrain` split again: the deterministic fallback — `suggestion()`
  (the full ladder), `defensiveAction()` (rung 0 combat), the private
  `combatMove` / `armMove` / `stanceMove` / `bestMedicine` helpers, and the
  `CREDIT_FLOOR` / `RESOURCE_TARGET` / `HOARD_CAP` / `RESEARCH_SURPLUS`
  constants — moved to a new `Brain\Ladder`. `AgentBrain` is now only the LLM
  round-trip (`decide()` / `parseDecision()` / `summarize()`), 600 → 122 lines.
  `AutoPlayer`, `PromptBuilder` and `Stance` now call `Ladder::…` directly
  instead of reaching into `AgentBrain` statics; ladder tests moved to
  `LadderTest`. No behaviour change. `docs/PLAYBOOK.md` updated.

## [3.1.33] - 2026-09-08

### Changed
- `Repository\AbstractRepository` gains a protected `fetchOut($class, $endpoint)`
  helper — `GET` the endpoint and hydrate the body into one `Out` part — and the
  23 concrete `getX()` methods across `World`/`History`/`Social`/`Economy`/
  `Meta`/`Agent` repositories that hand-rolled that exact
  `->then(fn($data) => $this->factory->part(...))` now call it. The "fetch →
  hydrate" contract lives in one place; `getArena()` (raw body),
  `DepositsRepository` (list) and `IntentRepository::getIntentStatus()` (404/410
  → synthetic `gone`) keep their bespoke bodies. `AbstractRepositoryTrait` is a
  vendored port of DiscordPHP's trait and is deliberately left untouched.

## [3.1.32] - 2026-09-08

### Changed
- `StateStore` split: the ~30 accessors moved into six cohesive traits under
  `NHA\State\` — `IdentityStateTrait` (default agent / Discord users / autoplay
  flag / command signatures), `PositionStateTrait`, `AutoplayLeaseTrait`,
  `DecisionLogTrait`, `CombineMemoryTrait` and `LoopStrategyStateTrait`
  (forced-objective rotation + cooldown + stance + inventor-points trend).
  `StateStore` itself is now just the JSON file: load, the shared `$data`, and
  the atomic `save()` the traits call — 723 → 87 lines. No API change (every
  method, constant and static stays on `StateStore` via the traits); some
  magic caps became named constants (`DECISION_LOG_CAP`, `TRIED_COMBINES_CAP`,
  `DEAD_COMBINES_CAP`).

## [3.1.31] - 2026-09-08

### Changed
- `Commands` split: the how-to-play guide — the ~110-line `HELP` content block
  plus `help()` / `resolveHelpKey()` / the private `helpContainer()` select-menu
  wiring — moved to a new `NHA\HelpGuide` (`HelpGuide::SECTIONS`,
  `HelpGuide::render()`, `HelpGuide::resolveKey()`). `Commands` drops from 1052
  to 891 lines. `Commands::HELP` aliases `HelpGuide::SECTIONS` and
  `Commands::help()` / `Commands::resolveHelpKey()` are thin delegators, so
  `SlashCommands`, `ChatCommands` and every caller are unchanged.

## [3.1.30] - 2026-09-08

### Changed
- `Brain\AgentBrain` split: the ~300-line situation-digest builder
  (`summarize()` plus its `pairs` / `rows` / `compactArgs` formatters) moved
  to a new `Brain\PromptBuilder` (`PromptBuilder::build()`). `AgentBrain` is
  now decide / parse / the deterministic `suggestion()` ladder, 948 → 600
  lines. `AgentBrain::summarize()` stays as a thin delegator, so callers and
  tests are unchanged; `PromptBuilder` calls back to `AgentBrain::suggestion()`
  for the "SUGGESTED next action" line. `docs/PLAYBOOK.md` component diagram
  and anchor table updated.

## [3.1.29] - 2026-09-08

### Changed
- `Brain\Playbook` — rung 4b rewritten as **GET A VEHICLE**: the agent has
  never `finalize`d one, so once it is stocked and grounded with no vehicle in
  hand it should read the part costs from the recipe block, `build` the
  cheapest affordable part, repeat a part per turn until it can `finalize`,
  then `deploy` — ahead of inventing and a second tower. A rejected `build`
  means "can't afford that part", not "retry".

## [3.1.28] - 2026-09-08

### Changed
- Autoplay: the brain no longer rides the elevator (`launch` / `ride` up /
  `depart`) whenever it feels like it. After any location change, a
  `TRANSIT_DWELL_TICKS` (8-turn) window makes `AutoPlayer::step()` substitute a
  local ladder action for a "leave" pick — unless the ladder is genuinely
  exhausted here (its fallback is the same transit verb) or a loop-break
  objective wants to explore. `land` (coming home) is never gated. This
  replaces the narrower "ride straight after riding" anti-bounce check.
- `Brain\Playbook` system prompt gains an **ELEVATOR ABUSE** anti-pattern:
  exhaust `mine`/`chop`/`gather`/`combine`/`construct`/`sell` where you stand
  before changing location; never ride up, find nothing, and ride back.
- `docs/PLAYBOOK.md` — the one-turn flow diagram now shows the transit-dwell
  guardrail in place of the old ride check.

## [3.1.27] - 2026-09-08

### Changed
- `bot.php` went from 848 lines to ~175 by extracting cohesive pieces into
  `NHA\Bot\*` classes (no behaviour change):
  - `Bot\Env` — `.env` location + loading + typed getters.
  - `Bot\Replies` — `Commands::` promise → chat / slash response, `❌` on error,
    `flattenOptions()`.
  - `Bot\ChatCommands` — the whole `!nha` prefix-command tree.
  - `Bot\SlashCommands` — the lazy slash registration (option builders, the
    `/nha` group + dispatch, per-user single-verb commands, signature-diffed
    `createCommand`).
  - `Bot\ChannelRelay` — the poll → dashboard + `MESSAGE_CREATE` → `say` bridge.
  - `Bot\AutoplayLoop` — the periodic `AutoPlayer::step()` loop with its
    busy-skip and warn-throttle.
  `bot.php` now just wires configuration → `NHA` → these components → `run()`.

## [3.1.26] - 2026-09-08

### Fixed
- Bump the `Http::VERSION` / `NHA @version` markers that were missed in 3.1.25.

## [3.1.25] - 2026-09-08

### Added
- `NHA_BRAIN_CHANNEL_ID` — the autoplay play-by-play ("thinking dialogue") is
  posted there when set, keeping the main `NHA_CHANNEL_ID` for controls,
  inventory and the world dashboard. Falls back to `NHA_CHANNEL_ID` when unset.

## [3.1.24] - 2026-09-08

### Fixed
- The Discord channel relay re-posted the full observation dashboard on every
  poll. `AgentObservation::getThreats()` (the `alerts` list) lingers for many
  ticks after a single hit, and the relay treated "any threat present" as "post
  now" — so one old `attacked` alert spammed the dashboard every few seconds.
  It now tracks the newest forwarded alert tick and relays only a genuinely new
  threat (or a new world-chat message).

## [3.1.23] - 2026-09-08

### Added
- Strategic **stances** — the agent is no longer one rigid ladder. Each turn
  `Stance::pick()` chooses `homestead` (default) / `aggressive` / `capitalist` /
  `expansionist` from the observation, with hysteresis (40-tick dwell; combat
  pre-empts it) and persistence in `state.json`. The stance re-flavours the
  system prompt (a `Stance::briefing()` block spliced at the top, framed as a
  steer not a script) and adds a light deterministic nudge (ladder rung 1d):
  aggressive tops ammo and closes on a weak target; capitalist fulfils a
  covered contract or banks a raw surplus; expansionist builds an extractor,
  docks an asteroid, or heads for the elevator. Survive / defend / arm and the
  anti-patterns stay stance-independent. The status line shows `🤖 [stance]`.

### Changed
- Research is now a luxury, not a grind. Rung 2 (`combine` for inventor points)
  fires only on a genuine surplus — at least two raws each `RESEARCH_SURPLUS`
  (60) deep, on top of the normal stockpile — regardless of stance or whether
  points are "paying". Below that, the agent builds or banks instead.

## [3.1.22] - 2026-09-08

### Added
- Combat self-defence and self-arming.
  - `AutoPlayer::step()` checks `AgentBrain::defensiveAction()` right after
    `observe` and, if the agent is in a fight (a recent `attacked` alert, a
    `last_robbed_by`, or a hostile closing in while hurt), acts on it
    immediately — heal below ~35% HP, `attack` back if armed and the aggressor
    is in range, otherwise `move` directly away to break contact — skipping the
    brain and the loop guard entirely. Logs a `🛡️` line.
  - The `suggestion()` ladder gains an ARM rung (after finalize/deploy, before
    research): out of combat with no medicine / weapon / ammo → `buy` a
    `stimpack`, then a `kinetic_gun`, then `slug` ×5.
  - Ammo and combat kit are excluded from the sellable-raws set. Playbook rung
    1 is now SURVIVE / DEFEND with an explicit combat sub-ladder, plus rung 1b
    ARM YOURSELF.

## [3.1.21] - 2026-09-07

### Fixed
Turn-flow review (`AutoPlayer::step()`):
- The forced-objective cursor is no longer advanced (nor the cooldown armed)
  before `brain->decide()`. The turn now *peeks* the next objective for the
  prompt and only commits the rotation (`bumpForcedObjective`) once the brain
  call has returned — a transient brain failure no longer burns a rotation.
  New `StateStore::peekNextForcedObjective()`.
- The `research` loop-break no longer picks a tower material that is only at
  its reserve — the combine guardrail would just block it, defeating the break.
- A `wait` turn and a `🔁` skipped turn now record a `wait` decision, so a
  wait/skip streak is visible to `detectLoop()` (previously invisible — the
  log did not grow, so the loop guard never fired).

## [3.1.20] - 2026-09-07

### Fixed
- The 3.1.19 `land`/`launch` filter created a blind spot: an agent wedged on a
  structure `land` cannot get past would `land`-spam forever undetected.
  `detectLoop()` now records altitude on every decision and flags a `land` /
  `launch` run where the altitude has not moved (`stuck land at altitude N`) —
  which also bypasses the loop-break cooldown. `loopBreakDecision` steps the
  agent off its cell when it is aloft at altitude ≤ 5.
- A `combine` refused by the guardrail (dips a reserve, world-known, …) is now
  recorded as tried even though it never went out, so the brain stops
  re-picking a set it cannot submit (the "combine composite + ice" fixation).

## [3.1.19] - 2026-09-07

### Fixed
- The loop guard no longer flags a descent. `land` / `launch` are bounded,
  self-terminating altitude changes — `detectLoop()` now ignores them, so a
  multi-turn descent from the elevator is not mistaken for "repeating land"
  (and the `build` loop-break, which is itself `land` while aloft, no longer
  fights it). PLAYBOOK loop-guard diagram updated.

## [3.1.18] - 2026-09-07

### Changed
- Selling and harvesting are now conditional on need.
  - `sell` fires only when credits are below `AgentBrain::CREDIT_FLOOR` (300)
    or a single raw has piled past `HOARD_CAP` (80); it never dips below the
    `RESOURCE_TARGET` (30) stockpile except in a genuine credit emergency
    (then it keeps a token 10). The two old unconditional `sell` rungs are gone.
  - Harvesting and repositioning fill each raw up to `RESOURCE_TARGET` (30)
    instead of stopping at "not short (< 15)".
  - Grabbing a resource you are standing on runs *before* spending credits on
    buy-to-build; buy-to-build itself now gates on `CREDIT_FLOOR`.
  - The loop-break `wealth` objective keeps a working 10 rather than dumping
    the whole stack.
  - `docs/PLAYBOOK.md` ladder diagram updated to match.

## [3.1.17] - 2026-09-07

### Changed
- Tower materials are no longer banned from research combines — they are
  reserved. A research `combine` may spend `metal` / `aluminum` / `carbon` /
  `composite` / `alloy` / `steel` / `titanium` / `superalloy`, but only the
  surplus above a per-material reserve (`metal` 8, `composite` 2, the rest 4);
  if the spend would drop the agent below the reserve the combine is refused
  and it goes back to buying / building. `aluminium + carbon → composite`
  stays exempt.

## [3.1.16] - 2026-09-07

### Fixed
- The buy-to-build pipeline was leaking. An audit showed the brain buying
  `metal` / `aluminum` as suggested and then immediately combining them into
  junk research sets, so `composite` never accumulated. The guardrail now
  refuses any research `combine` whose ingredients include a tower material
  (`metal`, `aluminum`, `carbon`, `composite`, `alloy`, `steel`, `titanium`,
  `superalloy`) and falls through to the buy-to-build / construct ladder. The
  one allowed use of those in a `combine` is `aluminium + carbon → composite`.

## [3.1.15] - 2026-09-07

### Added
- The strategy now spends credits instead of only earning them. When a ground
  agent has a credit pile but no `composite`/`metal` and no fresh research, the
  ladder buys its way to a tower: `buy metal` → `buy aluminum` + `buy carbon` →
  `combine` them into `composite` → `construct` — turning an idle credit stack
  into builder points, the one reliable scorer it could not otherwise reach.
- Passive-income rungs: `deploy` a finished-but-idle vehicle to roam and mine
  autonomously; `invest` credits into any still-open Station module / colony
  board (a no-op while everything is complete). Playbook rung 4b spells out the
  `build → finalize → deploy` and `construct extractor` income loops.

## [3.1.14] - 2026-09-07

### Fixed
- The loop guard thrashed once it engaged. A live audit showed it firing every
  single turn — the loop-break `move`s it issued kept the "no productive
  action" window full, so it re-triggered on its own output and rotated through
  all four objectives in four turns.
  - A 24-tick cooldown (`StateStore::loopBreakCooldownActive()`) after each
    break: no re-break until the forced objective and the brain turns after it
    have had a chance to change the situation.
  - A stuck `wealth` / `build` / `research` objective now harvests a deposit it
    is standing on before falling through to a `move`, so the break itself is a
    productive turn instead of more repositioning.

## [3.1.13] - 2026-09-07

### Fixed
- The loop detector missed noisy loops. A live audit caught the agent
  oscillating `land ↔ move (elevator base) ↔ ride` for ~15 turns without
  `detectLoop()` firing — the doubled steps and the odd `chop` kept any single
  action under the dominance bar and broke the exact-cycle match. Added two
  checks: the same `move` target chosen 3+ times in the window, and a window
  that is 6-of-8 traversal verbs (`move`/`ride`/`land`/…) with nothing
  productive. Lowered the exact-dominance bar 60% → 55%.
- A forced `explore` now steps ~28 cells (was 13) so it actually clears
  whatever the agent was circling.

## [3.1.12] - 2026-09-07

### Added
- Loop guard. `AutoPlayer::detectLoop()` scans the last ~12 decisions for one
  action dominating the window or a short 2-4 move pattern repeated three times.
  When it fires, `StateStore::bumpForcedObjective()` rotates the agent to a
  different kind of goal (`explore → wealth → build → research`) and
  `AutoPlayer` acts on it deterministically for that turn — sell the biggest
  stack, construct/land, try a genuinely fresh pair, or walk a long step in a
  rotating direction — whatever the brain returned. The forced objective is also
  handed to the brain as a `LOOP DETECTED` directive and expires after 45 ticks.
- The rolling decision log kept in `state.json` grows 15 → 24 entries so cyclic
  patterns are visible across enough repetitions to catch.

## [3.1.11] - 2026-09-07

### Added
- A durable dead-combine list. When a `combine` intent comes back `rejected`
  (the Inventors' Guild ruled the tag-set makes nothing), its signature is
  recorded in `state.json` via `StateStore::recordDeadCombine()` and never
  submitted again by that agent — it overrides even the production-recipe
  exemption, survives restarts, and is fed to the brain as an explicit
  "NEVER pick these" line.
- The per-run "already submitted" list (`agent_combine_sigs`) cap is raised
  400 → 2000 so a long-running agent does not FIFO-evict an old set and retry it.

## [3.1.10] - 2026-09-07

### Fixed
- Autoplay no longer bounces between the ground and orbit. When there is
  nothing to do off the ground — no asteroid to dock and mine, no parts to
  finish — the ladder now returns `land` instead of stalling, and it no longer
  suggests riding an elevator up with no station or asteroid work lined up.
- A `ride` chosen straight after another `ride` is treated as a loop and
  swapped for the ladder's move.
- The fallback sells a modest surplus (12+ of a raw) rather than skipping the
  turn when research has stalled and there is no build move — far fewer wasted
  `🔁` turns.

## [3.1.9] - 2026-09-07

### Fixed
- The "is research paying" check now uses the recent trend, not the absolute.
  Inventor points never drop, so `inventor_points > 0` stayed true forever once
  an agent had invented anything — the fallback would never actually reach
  infrastructure. `StateStore::noteInventorPoints()` records the score each turn
  and reports paying only when it rose this turn or within the last 15 minutes;
  once discoveries dry up the fallback drops to build / wealth / harvest.

## [3.1.8] - 2026-09-07

### Changed
- The infrastructure fallback now keys off whether research is actually paying.
  When `inventor_points` are above 0 a refused set is swapped for a *fresh*
  untried pair (research still works — keep at it); only once points have
  stalled at 0 does the fallback skip speculation and go to build / wealth /
  harvest. Fixes an in-space agent idling when it held no ground-build
  materials despite research still scoring.

## [3.1.7] - 2026-09-07

### Changed
- When a research `combine` is refused (world-known or already tried this run),
  autoplay now works on infrastructure instead of just selling a surplus. The
  fallback runs the shared decision ladder with speculation switched off:
  `finalize` loose parts → `construct` a tower when `composite` + `metal` are in
  hand → sell a glut → harvest a shortage → move toward the materials a build
  needs. `AgentBrain::suggestion()` is now `public static` with an
  `$allowSpeculation` flag so both callers share one ladder.
- Production recipes (`aluminium+carbon` → `composite`, the `construct` gate)
  are exempt from the "already tried" guardrail — you re-craft them every time
  you build, so they are never treated as spent research.

## [3.1.6] - 2026-09-07

### Fixed
- Autoplay no longer loops on `combine`. The brain would resubmit the same
  handful of ingredient sets dozens of times ("an uninvented combination for
  inventor points") when they were long-since known and minting nothing.
  - `AutoPlayer` now refreshes `GET /rules` every 90s (was fetched once per
    process) and merges the signature of any `combine` that APPLIES into the
    known set immediately.
  - A `combine` whose sorted signature is world-known, or already submitted by
    this agent this run, is dropped before it is sent and replaced with a
    productive fallback (sell a raw surplus) or a skipped turn.
  - Every submitted `combine` signature is recorded durably
    (`StateStore::recordCombineSignature()` / `getTriedCombineSignatures()`),
    so the "already submitted" list the brain sees is the whole run, not just
    the last eight turns.
  - The prompt tells the brain a `combine` that merely APPLIED still scores
    nothing unless `inventor_points` rose.

## [3.1.5] - 2026-09-07

### Fixed
- A queued intent whose id has aged out of the world's retention window no
  longer gets re-polled every autoplay turn. `IntentRepository::getIntentStatus()`
  maps a `404`/`410` to a terminal `gone` status instead of rejecting, and the
  autoplay loop drops the stored `queued_intent` once its outcome is settled
  (`applied` / `rejected` / `gone`) via the new
  `StateStore::clearQueuedIntent()`. This also stops the HTTP layer logging the
  same failed `GET /intent/{id}` with a stack trace on a loop.
- The autoplay loop in `bot.php` de-dupes its warnings: a persistent fault
  (brain host unreachable, API down) is logged once and then at most once every
  five minutes, instead of on every tick.

## [3.1.4] - 2026-09-07

### Fixed
- `require-dev` pins `symfony/console` to `^7.4`. `phpacker/phpacker` needs
  Symfony 7, but the DiscordPHP dev-master graph had floated it to 8.x, so a
  fresh `composer install` could not add phpacker. The other family bots got
  the same pin.

## [3.1.3] - 2026-09-07

### Fixed
- The packed binaries now run from wherever they land. `bot.php` /
  `autoplay.php` resolve `vendor/`, `.env` and `var/` by walking up from the
  real executable path (then the working directory), so a phpacker binary at
  `bin/build/<name>/<platform>/` works when double-clicked or launched from a
  shortcut with any working directory — previously it died with "Composer
  autoloader not found".

### Security
- `bin/build` is gitignored **and** `export-ignore`d in `.gitattributes`. A
  packed binary can be decompiled to recover whatever environment it ran with;
  it must never be committed or shipped in a dist archive.

## [3.1.2] - 2026-09-07

### Added
- PHPacker builds both entry points. `composer phpacker` now runs
  `phpacker:bot` (`bot.php` → `bin/build/bot`) and `phpacker:autoplay`
  (`autoplay.php` → `bin/build/autoplay`); root `phpacker.json` / `phpacker.ini`
  hold the shared build config (all platforms, PHP 8.4, `memory_limit=-1`).
- `bin/build` is gitignored; the phpacker config is `export-ignore`d from the
  dist archive.

## [3.1.1] - 2026-09-07

### Changed
- Brain: `plant` no longer stacks new trees — the NHA engine now tops up the
  most-drained tree on the cell (cap 22) and rejects the intent when they are
  all full. The `Playbook` anti-patterns, the `plant` verb hint, and the
  `/plant` help / docblocks say so, so the autoplay brain stops chop→plant
  looping on a full cell.

## [3.1.0] - 2026-09-07

### Added
- The rich Components V2 panels (the observation panel, `/help`, and the
  per-user control panel) now carry a subtle footer linking the source repo
  and GitHub Sponsors. One-line command replies are unchanged.
- `HelperTrait::GITHUB` / `HelperTrait::SPONSOR` constants and
  `HelperTrait::attributionComponents()` for reuse.
- `composer.json` now declares `"php": "^8.3"`.

## [3.0.1] - 2026-09-07

### Fixed
- Test harness only, no library or runtime change: the pure-unit HTTP and
  Parts tests no longer extend the integration base class (which opened a live
  Discord connection), `openapi.json` is tracked so the schema-drift test can
  run in CI, and `NHASingleton` coerces an unset `NHA_TOKEN` to a string.
- Added `.github/workflows/ci.yml` (lint, PHPUnit, php-cs-fixer on PHP
  8.3 / 8.4).

## [3.0.0] - 2026-09-07

First tagged release. Targets NHA world API **v3** (`openapi.json`
`info.version` `3.0`).

### Added
- Full NHA client on top of DiscordPHP: `NHA` (`MessageCommandClient` subclass),
  an async `NHA\Http` transport with rate-limit buckets, and `NHA\Http\Endpoint`
  route constants covering every non-asset path in the API.
- Typed read repositories for every board (`world`, `market`, `roster`,
  `contracts`, `relations`, history/meta boards, Expansion-era boards, …),
  resolving `NHA\Parts\*` models that preserve undeclared response keys.
- `NHA\VerbsTrait` — one typed wrapper per documented agent verb, all through
  `POST /intent`.
- `NHA\Commands` — framework-agnostic handlers shared by chat commands, slash
  commands and message components, plus the `AgentObservation` Components V2
  panel with context-aware action buttons.
- `NHA\StateStore` — atomic JSON persistence for the default agent id/token, the
  per-Discord-user identity map, last-known position, and the autoplay flag.
- Optional LLM autoplay: `OllamaClient` (native and OpenAI-compatible), a
  `Playbook` strategy, `AgentBrain` (observation digest + strict-JSON decision),
  `AutoPlayer` (one observe → decide → act turn), and a headless `autoplay.php`
  runner with a restart supervisor.
- Configurable world base URL: `NHA_BASE_URL` env / `nha_base_url` client option,
  falling back to `NHA\Http\Http::BASE_URL`.
- Contributor docs: `AGENTS.md` and specialist playbooks under `.agents/skills/`.

### Fixed
- Autoplay lease is a real compare-and-swap under an OS file lock, with a TTL
  derived from the turn interval and released on a clean `autoplay.php`
  shutdown; two runners can no longer submit two intents per interval from one
  token.
- The lease re-read no longer adopts an empty or truncated `state.json`, which
  could otherwise persist a file holding only the lease and drop the agent
  token (the NHA server issues it once).
- The brain digest no longer replays the previous turn's free-text `reason`
  back to the model; only the verb, args and the server's outcome carry
  forward, so a stale self-assertion ("I have 9 wood") can't loop.
- Agent registration never produces a bare `user-` name; names are clamped to
  the NHA 1–24 character limit.
- Agent tokens are scrubbed from logged `422` response bodies.

[3.1.4]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.4
[3.1.3]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.3
[3.1.2]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.2
[3.1.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.1
[3.1.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.0
[3.0.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.1
[3.0.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.0
