# Changelog

All notable changes to DiscordPHP-NHA are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project uses
SemVer with the **major tracking the NHA world API version**.

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

[3.1.3]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.3
[3.1.2]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.2
[3.1.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.1
[3.1.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.0
[3.0.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.1
[3.0.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.0
