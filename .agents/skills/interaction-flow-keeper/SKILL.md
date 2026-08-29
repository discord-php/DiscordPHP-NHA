---
name: interaction-flow-keeper
description: >-
  Maintain DiscordPHP-NHA interaction flow — slash command registration,
  readiness gates, option dispatch, deferred responses, and component
  updates. Use when touching slash commands or interaction callbacks.
---

# Skill: interaction-flow-keeper

Use this skill when work touches:

- slash command registration in `bot.php`
- `listenCommand()` callbacks
- interaction acknowledgement or original-response updates
- option flattening and slash dispatch
- `Button::setListener()` interaction callbacks

This is typed-interaction flow skill. Load it when work affects how Discord interactions enter the local application, route to NHA behavior, or receive a response.

## Goal

Keep interactions as a coherent local pipeline:

- DiscordPHP receives and types the interaction upstream
- local startup waits for client and application readiness
- global command state is freshened before definitions are created
- slash options route into the shared `Commands` service
- NHA network work happens after acknowledgement
- response builders update the original interaction response
- component callbacks queue an action, re-observe, and update the message

## Read in this order

1. Slash-command section of `bot.php`
2. `$replyToInteraction` and `$flattenOptions` in `bot.php`
3. `src/NHA/Commands.php`
4. `src/NHA/Parts/AgentObservation.php`
5. `src/NHA/NHA.php`
6. Installed DiscordPHP interaction and builder APIs only when dependency behavior must be confirmed

## Core contract

Interaction flow has two distinct ownership sides.

### Upstream DiscordPHP

- gateway `INTERACTION_CREATE` dispatch
- concrete `Interaction` typing and type maps
- resolved data hydration and caches
- `listenCommand()` registration machinery
- interaction acknowledgement/update protocol
- component listener plumbing

### Local DiscordPHP-NHA

- command names, descriptions, and options
- readiness gate for registration
- flattening option values
- mapping a subcommand to a `Commands` method
- acknowledgement-before-NHA-I/O ordering
- conversion of success/failure to a `MessageBuilder`

Do not mix dependency internals into local command semantics.

## Typing rules

DiscordPHP supplies typed:

- `Interaction`
- application `Command`
- command `Option`
- global command repository
- command and component builders

Use their public methods and constants. Do not add local interaction type maps or raw interaction decoding.

`$flattenOptions` intentionally turns typed option Parts into a simple associative array at the adapter boundary. Keep that conversion local to dispatch; do not weaken the upstream interaction object globally.

## Registration rules

### Readiness gate

`bot.php` waits for both:

- `init`
- `application-init`

Only then does it call the slash registration closure and update presence. Preserve both flags and the one coordination function.

### Freshen before create

`$nha->application->commands->freshen()` obtains current global command state. Definitions are created only when a command name is missing.

Preserve:

- asynchronous `freshen()` flow
- lookup by command name
- builder creation followed by repository-backed save
- listener registration for every locally supported slash command

### Command shape

The `/nha` command contains typed subcommand options. Standalone `/observe` and `/say` aliases mirror common prefix aliases.

When adding a subcommand, update all companion surfaces:

1. `Option` definition
2. dispatch `match`
3. `listenCommand()` path if top-level
4. prefix command equivalent when intended
5. README command list

## Interaction response rules

NHA world requests are network calls. Use this order:

1. call `acknowledgeWithResponse()`
2. then await the `Commands` Promise
3. on success, call `updateOriginalResponse($builder)`
4. on failure, update the original response with a safe error builder

Do not run the NHA request first and hope it finishes inside Discord's response window.

## Registered command routing rules

The local route is explicit:

- `/nha` reads the chosen subcommand
- nested options are flattened
- `$dispatch` maps the subcommand to one `Commands` method
- standalone aliases call the same dispatch closure

Keep these facts straight:

- `Commands` is shared business behavior, not DiscordPHP's registered-command tree
- prefix commands still use `MessageCommandClient`
- slash callbacks still use `listenCommand()`
- sharing a service does not mean merging the two inbound protocols

## Component interaction rules

Observation buttons are registered with `Button::setListener()` in `AgentObservation::toContainer()`.

Preserve:

- the current `NHA` instance supplied to listener registration
- intent action first
- fresh `observe()` second
- `updateMessage()` with a new `MessageBuilder` third
- Promise chaining all the way through

Do not mutate and redisplay the old observation after an action.

## Performance rules

Interaction code has little patience for extra work.

Prefer:

- acknowledge immediately
- use option values already present
- route directly to `Commands`
- reuse response builders
- update the original response once

Avoid:

- REST fetches unrelated to the command
- broad Discord cache scans
- synchronous file or network waits in callbacks
- duplicate NHA calls from both adapter and service

`StateStore` access is intentionally tiny and local, but long-running or shared persistence would need a separate async design.

## Outbound response rules

### Use builders

- command definitions: `CommandBuilder`
- command options: typed `Option` Parts
- message responses: `MessageBuilder`
- Components V2: component builders

### Keep response shape shared

`Commands` should continue resolving builders usable by both prefix and slash delivery. Error adapters may create their own small builder because delivery semantics differ.

### Preserve safe mentions

Use `NHA::createBuilder()` for local response messages so arbitrary NHA or user text does not create unintended mentions.

## Boundaries to preserve

### Interaction system vs prefix command system

They are different adapters:

- application commands route through DiscordPHP interactions and `listenCommand()`
- prefix commands route through `MessageCommandClient` command objects
- both call `Commands`

Do not unify parsing, acknowledgement, or help behavior.

### Interaction vs NHA model

An interaction may display an `AgentObservation`, but the observation is not an interaction Part and has no Discord persistence.

### Local flow vs DiscordPHP internals

Interaction type maps, gateway handlers, resolved-data caches, and repository mechanics live upstream. Identify dependency changes separately.

## Smells

Stop if you see:

- slash registration before both readiness events
- NHA network work before interaction acknowledgement
- raw interaction payload decoding added locally
- a local interaction type map
- slash behavior duplicated instead of calling `Commands`
- prefix parsing mixed into slash callbacks
- option definition names that do not match dispatch keys
- component callbacks updating stale observation data
- raw response arrays replacing builders
- broad cache or REST work added to a hot callback

## Checklist before commit

- both `init` and `application-init` still gate slash setup
- global commands are freshened asynchronously
- missing commands are created through `CommandBuilder`
- listener registration exists for every supported slash entry point
- option definitions match dispatch names and PHP value expectations
- `/nha` subcommands route through the shared dispatch closure
- standalone aliases route through the same shared behavior
- interactions acknowledge before NHA I/O
- success and failure both update the original response
- button listeners action, re-observe, then update
- prefix-command boundaries remain intact
- upstream interaction internals are not claimed as local ownership
- docs/tests updated if public interaction behavior changed

## Bottom line

Interaction code here is a thin, latency-sensitive adapter over DiscordPHP and the shared `Commands` service. Keep it typed by the dependency, acknowledged early, explicitly routed, and separate from prefix parsing.
