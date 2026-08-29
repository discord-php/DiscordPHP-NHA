---
name: runtime-bootstrap-keeper
description: >-
  Maintain DiscordPHP-NHA runtime bootstrapping, NHA client wiring, startup
  readiness, observation polling, relay behavior, and process lifecycle.
  Use when touching NHA.php, bot.php startup, loop wiring, or NHA HTTP setup.
---

# Skill: runtime-bootstrap-keeper

Use this skill when work touches `src/NHA/NHA.php`, `bot.php` startup, the ReactPHP loop, NHA HTTP construction, readiness gates, polling, relay listeners, logging, or process lifecycle.

This is not generic PHP skill. This is the extension-orchestrator guard. Load it when changing how the DiscordPHP subclass gains NHA behavior or how the executable wires that behavior into the long-running bot.

## Goal

Keep runtime ownership clear:

- DiscordPHP's `MessageCommandClient` owns Discord option resolution, gateway connection, application state, and loop lifecycle
- `NHA` adds one NHA HTTP client, Promise-based NHA methods, typed verb helpers, and a last-observation cache
- `bot.php` owns environment loading, logger setup, command registration, readiness coordination, polling, relay behavior, and the final `run()`
- startup remains non-blocking and event-driven
- slash commands register only after both Discord client and application readiness

## Read in this order

1. `src/NHA/NHA.php` — the extension client
2. `src/NHA/Http/Http.php` — NHA request queue and driver contract
3. `src/NHA/Http/Endpoint.php` — NHA route vocabulary
4. `src/NHA/Http/Request.php` — NHA base URL binding
5. `bot.php` — application wiring and lifecycle
6. `src/NHA/Commands.php` — shared work invoked by runtime adapters
7. `README.md` and `.env.example` — public startup contract

Do not start by redesigning DiscordPHP gateway internals. They belong to the upstream dependency. Understand the local subclass and executable first.

## Core contract

`NHA` is an application-specific `MessageCommandClient` subclass, not a replacement for DiscordPHP's runtime.

- `parent::__construct($options)` must run before local code reads the parent loop, logger, or resolved options
- one `NHA\Http\Http` instance is created and shared for the lifetime of the client
- the local HTTP driver uses the same ReactPHP loop as DiscordPHP
- NHA methods return `PromiseInterface`; production code does not synchronously await them
- `observe()` materializes `AgentObservation`, updates the cache for that agent id, and resolves with the model
- `bot.php` calls `$nha->run()` once, after listeners and timers are registered

Discord gateway connection, reconnect logic, intents, repositories, event handler registration, and voice remain dependency concerns.

## Options and environment resolution

Discord client and command-client options are passed to the parent:

| Option used locally | Purpose |
| --- | --- |
| `token` | Discord bot authentication |
| `logger` | shared Discord and NHA logging |
| `prefix` | prefix-command parsing |
| `socket_options` | also forwarded to the local React HTTP driver when present |

`NHA` does not own an `OptionsResolver` layer. Do not claim or add local normalization for the full DiscordPHP option set unless the extension truly introduces a new public option.

`bot.php` reads:

| Environment value | Purpose |
| --- | --- |
| `TOKEN` | Discord token used by the executable |
| `ERROR_CHANNEL_ID` | optional fatal error report destination |
| `NHA_CHANNEL_ID` | optional Discord/NHA relay channel |
| `NHA_POLL_INTERVAL` | observation polling interval, default `5` seconds |

Keep `README.md` and `.env.example` aligned if executable requirements change. Some example variables may be reserved for future behavior; do not describe them as active unless `bot.php` consumes them.

## Construction vs run() lifecycle

### What `NHA::__construct()` does

1. Calls `MessageCommandClient::__construct()` with caller options.
2. Reuses the resolved parent loop and logger.
3. Creates a React HTTP driver with optional socket options.
4. Creates the unauthenticated `NHA\Http\Http` client.

Do not move bot command registration, state-file setup, timers, or relay listeners into the client constructor.

### What `bot.php` does before `run()`

1. Loads Composer and `.env`.
2. Creates stdout logging.
3. Constructs `NHA`.
4. Installs rejection and exception handlers.
5. Creates `StateStore` and `Commands`.
6. Registers prefix commands.
7. Defines and gates slash-command registration.
8. Optionally installs polling and `MESSAGE_CREATE` relay handlers.
9. Calls `$nha->run()`.

### Why this matters

The client stays reusable as a library, while `bot.php` remains the executable policy layer. Moving application wiring into `NHA` makes unit testing harder and couples every library consumer to this one bot.

## NHA HTTP lifecycle

### Local client construction

`NHA\Http\Http` uses DiscordPHP HTTP interfaces and traits but changes the transport assumptions:

- base URL is `https://nha.recluse.lol`
- the API is unauthenticated
- no Discord `Authorization` header is added
- request queuing remains Promise-based
- the React driver is shared with the parent event loop

### Endpoint binding

Parameterized routes use `NHA\Http\Endpoint::bind()` and `bindAssoc()`:

```php
$endpoint = Endpoint::bind(Endpoint::OBSERVE)
    ->bindAssoc(['agent_id' => $agent_id]);
```

Do not hand-assemble `observe/{id}` or `agent/{id}` in callers.

### Request flow

`NHA` calls `Http::get()` or `Http::post()`. `HttpTrait` builds the operation, local `queueRequest()` supplies NHA-safe headers, and `NHA\Http\Request::getUrl()` prefixes the NHA base URL. Keep all stages asynchronous.

## Observation cache

`NHA::$agents` stores the last successful `AgentObservation` by integer agent id.

Rules:

- `getCachedObservation()` returns the cached model or `null`
- failed requests must not replace a good cached observation
- each successful `observe()` replaces only its own agent entry
- cache contents are process-local and non-durable
- `StateStore` stores the default agent id; it does not store observations

Do not present this array as a Discord repository, generic cache backend, or source of authoritative world state.

## Readiness flow

Slash setup requires both signals used by `bot.php`:

1. `init` means the Discord client is initialized.
2. `application-init` means application data needed for global commands is available.
3. `$maybeStart` returns until both flags are true.
4. Slash commands are registered/freshened once both are ready.
5. Presence is updated after the same gate.

Preserve the two-flag gate. Registering application commands from only one signal can race application repository availability.

## Polling and relay flow

When `NHA_CHANNEL_ID` is configured:

- a periodic timer checks the default agent id
- `observe()` fetches current NHA state
- new messages and threats trigger a Discord Components V2 update
- `MESSAGE_CREATE` forwards eligible plain Discord messages as `say` intents

The relay ignores:

- messages from other channels
- bot-authored messages
- messages starting with the configured command prefix

Keep callbacks non-blocking. Promise rejection should flow to the configured rejection handler or an intentional local handler.

## Logging and error handling

`bot.php` installs one fatal-error reporter for uncaught exceptions and unhandled Promise rejections. It logs locally and optionally sends a Discord message to `ERROR_CHANNEL_ID`.

Preserve:

- no secret/token values in logs
- failure to report to Discord must not create a blocking recovery path
- user-facing command failures stay in the prefix/interaction reply adapters
- NHA HTTP remains unauthenticated

## Dependency boundary

The following are upstream DiscordPHP behavior:

- gateway connection and reconnection
- intent resolution and event dispatch
- root Discord repositories and caches
- interaction type maps and resolved-data hydration
- builder validation internals
- voice

Local runtime work may consume those APIs, but this repository does not own their implementation.

## Smells

Stop if you see:

- local Discord gateway or reconnect logic added to `NHA`
- `NHA\Http\Http` gaining a Discord authorization header
- a second event loop or logger created for NHA requests
- blocking waits in production code
- bot command/timer policy moved into `NHA::__construct()`
- raw parameterized NHA URLs assembled outside `Endpoint`
- observation cache described as durable or repository-backed
- slash registration triggered before both `init` and `application-init`
- `run()` called before listeners and timers are installed
- relay messages echoed back because bot, prefix, or channel guards were removed

## Checklist before commit

- `parent::__construct()` still runs before local dependency wiring
- one NHA HTTP client shares the parent loop and logger
- NHA calls still return Promises
- parameterized routes use `Endpoint::bind()`
- successful `observe()` still updates only that agent's cache
- failed observations do not corrupt cached state
- startup listeners and timers are registered before `run()`
- slash registration still waits for both readiness signals
- polling remains timer-driven and non-blocking
- `MESSAGE_CREATE` relay filters channel, bots, and prefixed commands
- README and `.env.example` match any public startup change
- DiscordPHP internals are treated as dependency boundaries

## Bottom line

DiscordPHP owns the Discord runtime; `NHA` adds one asynchronous world client and a small observation cache; `bot.php` wires the application. Keep those three responsibilities separate and keep the process event-driven.
