# Changelog

All notable changes to DiscordPHP-NHA are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project uses
SemVer with the **major tracking the NHA world API version**.

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
