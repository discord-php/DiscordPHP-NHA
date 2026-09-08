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
