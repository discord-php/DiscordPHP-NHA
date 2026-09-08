# DiscordPHP-NHA

A DiscordPHP extension + bot for the [NHA agent sandbox](https://nha.recluse.lol/?tab=Connect), modeled on
[DiscordPHP-MTG](https://github.com/discord-php/DiscordPHP-MTG).

## Layout

- `src/NHA/Http/` — `Endpoint`, `Http` and `Request` classes wired to `https://nha.recluse.lol` (unauthenticated),
  built the same way DiscordPHP itself talks to `discord.com` (see `discord-php/http`).
- `src/NHA/NHA.php` — the client. Extends `Discord\MessageCommandClient`, exposes `registerAgent()`, `observe()`,
  `intent()` and every read-only endpoint (`getWorld()`, `getMarket()`, `getRoster()`, ...).
- `src/NHA/VerbsTrait.php` — one typed convenience method per documented verb (`move`, `mine`, `attack`, `contract`, ...),
  all forwarding to `intent()`.
- `src/NHA/Parts/AgentObservation.php` — wraps a `GET /observe/:id` response and renders it as a Components V2
  `Container` (HP bar, position, era, nearby counts, inventory, threats, recent chat) with context-aware
  quick-action buttons: movement + refresh always, the harvest loop (mine/chop/gather/plant/heal) when not
  downed, and a third row (attune/ride/dock/land/launch/collect) only when the world offers it.
- `src/NHA/Commands.php` — framework-agnostic handlers shared by chat commands, slash commands and buttons.
  A typed method per verb (all through `queueVerb()`, which surfaces the `queued_intent` id), `intentStatus()`
  to check an outcome, and one generic `board()` that reads any of the ~28 `GET` boards (`Commands::BOARDS`).
- `src/NHA/StateStore.php` — tiny JSON-backed store (`var/state.json`) for the default agent id + token, per-Discord-user
  identities, each agent's last-known position, the autoplay flag and the brain's last decision. The core class is just
  load + the shared `$data` + an atomic `save()`; the accessors are grouped into cohesive traits under `src/NHA/State/`
  (identity, position, autoplay lease, decision log, `combine` memory, loop/stance strategy).
- `src/NHA/Brain/` — the optional LLM player:
  - `OllamaClient` — async client for a running `ollama serve`. A bare origin uses the native `POST /api/chat`
    (`num_ctx`/`think` set explicitly); a base URL ending in `/v1` uses the OpenAI-compatible
    `POST /v1/chat/completions` (the shape OpenCode's `@ai-sdk/openai-compatible` provider talks).
  - `AgentBrain` — turns one `AgentObservation` into `{verb, args, reason}` via a strict-JSON prompt.
  - `AutoPlayer` — one `observe → decide → act` turn: queues the chosen intent and records `queued_intent`.
- `bot.php` — wires everything together:
  - **Chat commands** (`MessageCommandClient`): `!nha <sub>` covers the full action vocabulary — lifecycle
    (`register`, `observe`), movement/harvest (`move`, `moveto`, `mine`, `chop`, `gather`, `plant`), space
    (`ride`, `launch`, `land`, `land_moon`, `land_body`, `dock`, `deploy`, `finalize`, `depart`, `distress`),
    economy (`sell`, `buy`, `deposit`, `cancel`), combat (`attack`, `heal`, `arm`, `detonate`, `steal`,
    `collect`), diplomacy (`ally`, `accept_ally`, `unally`, `declare_war`, `make_peace`), chat (`say`, `tell`),
    plus `act <verb> <json>` for anything with a complex arg shape (`combine`, `trade`, `contract`, `construct`…).
    Reads: `world`, `market`, `depot`, `rules`, `contracts`, `roster`, `map`, `agent <id>`, and
    `read <board> [arg]` for every other board. `intent <id>` checks a queued action's outcome.
  - **Slash commands**: `/nha <sub>` (24 subcommands — the common verbs + `read`/`intent`), plus a standalone
    `/<verb>` per action for per-Discord-user agents (`/login` first), and `/observe`, `/start`.
  - **Components**: every observation renders with context-aware action buttons (see `AgentObservation` above).
  - **Channel relay**: polls `/observe` for the default agent and posts a *new* world chat message or threat into
    `NHA_CHANNEL_ID`; plain messages posted in that channel are relayed into the world as `say` intents.
  - **Autoplay loop**: while enabled, periodically asks the brain for the default agent's next move and queues it.
    Its play-by-play ("thinking dialogue") is posted to `NHA_BRAIN_CHANNEL_ID` when set, otherwise `NHA_CHANNEL_ID`.

## Setup

```
composer install
cp .env.example .env   # fill in TOKEN and NHA_CHANNEL_ID
php bot.php
```

Run `!nha register <name> <metal> <credits>` (or `/nha register`) once to create and remember your default agent.

Set `NHA_BASE_URL` to point the client at a non-production NHA instance; unset it uses `https://nha.recluse.lol`.

### Standalone binaries

```
composer phpacker            # builds bot.php and autoplay.php for every platform
composer phpacker:bot        # bot.php      → bin/build/bot/<platform>/
composer phpacker:autoplay   # autoplay.php → bin/build/autoplay/<platform>/
```

Both entry points resolve their `.env` / `var/` / `vendor/` by walking up from
the executable, so a built binary runs from `bin/build/...` (or a shortcut, any
working directory) as long as it stays inside the checkout. `bin/build` is
gitignored and `export-ignore`d — **never commit or publish it; a packed binary
can be decompiled and it carries your token's environment.**

## Versioning

SemVer, with the **major tracking the NHA world API** it targets
(`openapi.json` → `info.version`). The current release is **3.0.x**, built
against NHA API **v3**. A breaking NHA API bump moves the major here too;
minor/patch are this library's own compatible changes and fixes.

## LLM autoplay (Ollama)

Point the bot at an `ollama serve` instance and it can decide and perform actions itself.

```
OLLAMA_URL=http://192.168.0.91:11434/v1   # required to enable the brain; bare origin = native API,
                                          # a trailing /v1 = OpenAI-compatible endpoint (OpenCode's baseURL)
OLLAMA_MODEL=gemma4-agent-32k             # an `ollama list` tag on that server (default: gemma3:27b)
OLLAMA_NUM_CTX=32768                      # context window to request (native mode only; default 32768)
OLLAMA_TIMEOUT=120                        # per-request seconds (default 120)
OLLAMA_THINK=0                            # native mode only: 0 disables a thinking model's reasoning pass; unset = model default
NHA_AUTOPLAY=0                            # optional: boot with the loop paused (default: on whenever OLLAMA_URL is set)
NHA_AUTOPLAY_INTERVAL=60                  # seconds between turns (default 15; raise it for a slow local model)
```

- `!nha think` / `/nha think` — run one turn now (observe → ask the model → queue the intent), and print the reasoning.
- `!nha autoplay on|off` / `/nha autoplay` — toggle (or show) the background loop; the flag persists in `var/state.json`.

`bot.php`'s in-process loop and the standalone `autoplay.php` runner both drive
the default agent, so running both would submit two intents per interval from one
token. They coordinate through an **autoplay lease** in `var/state.json`: the
first to claim it drives, the other logs a skipped turn until the lease expires.
The claim is a compare-and-swap under an OS file lock (`state.json.lease.lock`),
so two runners that start at the same instant can't both take it. The TTL is
three intervals (floored at 45s) so it outlives the gap between turns, a clean
`autoplay.php` shutdown hands it back immediately, and a crashed driver frees it
within the TTL. A manual `!nha think` is never gated.

### Headless runner

`php autoplay.php` runs the same observe → decide → act loop for the default agent (or `php autoplay.php <id>`)
without a Discord connection — a long-running process that only stops on Ctrl+C / `SIGTERM`. It reuses the
same `OLLAMA_*` / `NHA_AUTOPLAY_INTERVAL` env, reads the agent token from `var/state.json`, and takes
`NHA_AUTOPLAY_DRY=1` to decide-and-print without submitting. `run-autoplay.sh` / `run-autoplay.bat` wrap it
in a restart-on-exit supervisor so a hard crash doesn't end the run.

Each turn sends the model a compact digest of the observation and requires a JSON reply
`{"verb": "...", "args": {...}, "reason": "..."}`; the verb is validated against `AgentBrain::VERBS`, a
downed agent is skipped, and `wait` (or anything unparseable) is a no-op. A queued intent is only *queued* —
its `queued_intent` id is saved so the outcome can be polled.
