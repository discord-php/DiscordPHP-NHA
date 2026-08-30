---
name: repository-cache-keeper
description: >-
  Maintain DiscordPHP-NHA observation caching and JSON state persistence.
  Use when changing NHA::observe(), cached agent observations, StateStore,
  or deciding whether a new persistence boundary is warranted.
---

# Skill: repository-cache-keeper

Use this skill when work touches:

- `src/NHA/NHA.php` observation caching
- `src/NHA/StateStore.php`
- `src/NHA/Parts/AgentObservation.php`
- the relay state in `bot.php`

This is cache-and-persistence boundary skill. DiscordPHP-NHA now uses a local repository hierarchy where `NHA\Repositories\AbstractRepository` extends `Discord\Repository\AbstractRepository` to provide common NHA-specific dependency injection.

## Goal

Keep local state as:

- a last-observation cache owned by `NHA`
- small durable bot configuration owned by `StateStore`
- typed observation snapshots represented by `AgentObservation`
- explicit boundaries between transient remote state and persisted local state

Not as a speculative repository framework.

## Read in this order

1. `src/NHA/NHA.php` — `$agents`, `observe()`, and `getCachedObservation()`
2. `src/NHA/Parts/AgentObservation.php` — the cached snapshot type
3. `src/NHA/StateStore.php` — JSON-backed default-agent persistence
4. `src/NHA/Commands.php` — consumers of the default agent and observations
5. `bot.php` — periodic observation polling and relay cursors
6. `tests/StateStoreTest.php`
7. `tests/Parts/AgentObservationTest.php`

## Core contract

The two local state mechanisms have different jobs:

- `NHA::$agents` is an in-memory map from agent id to the most recent
  `AgentObservation`.
- `StateStore` persists small bot-owned configuration to JSON. Today it stores
  only `default_agent`.

They are not interchangeable. Observations describe changing NHA world state
and become authoritative only when `observe()` resolves. The default agent is
local configuration that must survive process restarts.

If a change no longer feels like last-known observation state or tiny bot
configuration, define the new ownership and consistency boundary before adding
it here.

## Meaning of current state

### `NHA::$agents`

This protected array is the observation cache. Its keys are integer agent ids
and its values are `AgentObservation` instances. It is process-local and is
empty after restart.

### `NHA::observe(int $agent_id)`

This is the authoritative cache-write path:

1. bind `Endpoint::OBSERVE`
2. perform the asynchronous GET
3. wrap the decoded response in `AgentObservation`
4. replace `$agents[$agent_id]`
5. resolve with the same snapshot that was cached

Do not update the cache before the request succeeds. A rejected observation
must leave the last successful snapshot intact.

### `NHA::getCachedObservation(int $agent_id)`

This is a synchronous, non-fetching read. It returns the last successful
snapshot or `null`. It must not silently trigger network I/O.

### `StateStore`

`StateStore` loads JSON once during construction, updates its in-memory array,
and writes the complete document when `setDefaultAgent()` is called. Keep it
small and explicit. It is not an event log, database abstraction, or world-state
cache.

### Relay cursors in `bot.php`

`$seenMessageCount` is runtime relay bookkeeping. It is neither NHA observation
state nor durable configuration. Keep cursor semantics close to the poll loop
unless restart durability is deliberately required and designed.

## Cache and persistence semantics

### Observation cache

- key by the requested agent id
- cache only fully constructed `AgentObservation` values
- replace snapshots atomically after successful HTTP resolution
- treat the cache as last-known data, not guaranteed-current data
- use `observe()` when freshness is required

### JSON state

- persist only values owned by this application
- decode to an array and normalize values at the accessor boundary
- create the parent directory when saving
- keep reads and writes deterministic for tests
- consider write failures, malformed JSON, and concurrent writers before
  expanding the store

Do not persist raw observations to `StateStore` merely because a JSON file is
available. Remote world snapshots have different freshness and growth
requirements.

## Endpoint routing rules

Use `NHA\Http\Endpoint` and its inherited binding helpers for observation
routes. Keep route construction in `NHA` or the HTTP boundary, not in
`AgentObservation` or `StateStore`.

If an observation route changes, inspect:

- `NHA\Http\Endpoint::OBSERVE`
- `NHA::observe()`
- `tests/Http/EndpointTest.php`

Do not spread manual URL interpolation across callers.

## When to add a repository layer

Do not add an `AbstractRepository` clone to mirror upstream DiscordPHP.

A local repository boundary is justified only when DiscordPHP-NHA gains a real
resource family that needs several of these at once:

- identity-keyed collections
- repeated fetch/list/create/update/delete operations
- shared hydration rules
- cache eviction or freshness policy
- persistence behavior used by multiple callers

Even then, start from the NHA API's actual resource and consistency model. Do
not copy DiscordPHP repository machinery solely for architectural symmetry.

## Ownership boundary: snapshot vs client vs store

### `AgentObservation` owns

- read-only interpretation of one observation payload
- compatibility fallbacks between known payload keys
- presentation derived from that snapshot
- JSON serialization of the raw snapshot

### `NHA` owns

- making observation requests
- constructing `AgentObservation`
- replacing the per-agent last-observation cache
- exposing cached snapshots

### `StateStore` owns

- durable application configuration
- JSON file loading and saving
- default-agent persistence

### `bot.php` owns

- process-local relay cursors
- polling schedule
- deciding when a fresh observation should be sent to Discord

If a class starts making decisions assigned to another owner, move the logic
back across the boundary.

## New cached-state playbook

1. Name the source of truth: NHA response, Discord event, or local setting.
2. Decide whether the state is transient or must survive restart.
3. Choose one owner for writes.
4. Define the cache key and replacement/merge behavior.
5. Define what happens on request or write failure.
6. Keep Promise resolution and cache mutation in the same chain for remote data.
7. Add focused tests for first read, update, failure, and reload behavior.
8. Add a repository abstraction only if the resource lifecycle truly requires
   one.

## Existing patterns worth copying

### Successful-observation replacement

`NHA::observe()` creates the typed snapshot and writes it to `$agents` in one
fulfilled Promise callback. Preserve that ordering.

### Tiny persistence surface

`StateStore` exposes domain-named accessors instead of generic public
`get(string $key)` and `set(string $key, mixed $value)` methods. Keep that
explicit style while the state remains small.

### Read-only snapshot wrapper


`AgentObservation` keeps the raw payload but provides stable accessors for
fields the application consumes. Add accessors when they express NHA meaning;
do not turn the class into an active persistence object.

## Gateway interaction rules

Discord gateway caching belongs to the `team-reflex/discord-php` dependency.
The local relay in `bot.php` consumes `Event::MESSAGE_CREATE`, but it does not
own DiscordPHP repository internals.

When changing local cache semantics, cross-check:

- explicit command calls to `NHA::observe()`
- component refresh callbacks in `AgentObservation::toContainer()`
- periodic calls to `NHA::observe()` in `bot.php`
- any new code that reads `NHA::getCachedObservation()`

All paths should agree that `observe()` produces and caches the newest local
snapshot.

## Smells

Stop if you see:

- a new local `AbstractRepository` added without a concrete resource boundary
- observations persisted in `StateStore` without a freshness and size policy
- `$agents` filled with raw arrays or `stdClass` instead of
  `AgentObservation`
- cache mutation before an HTTP request has succeeded
- `getCachedObservation()` performing hidden network I/O
- multiple classes writing the same cached key with different semantics
- `StateStore` becoming a generic dumping ground for runtime relay state
- manual observation URLs duplicated outside `NHA\Http\Endpoint`

## Checklist before commit

- [ ] The source of truth for every changed value is explicit
- [ ] `NHA::observe()` still caches only successful typed observations
- [ ] Agent ids remain the observation-cache keys
- [ ] Fresh reads use `observe()` and cache-only reads use
      `getCachedObservation()`
- [ ] `StateStore` contains only deliberately durable application state
- [ ] JSON reload and overwrite behavior remain covered by tests
- [ ] Relay cursors and observation cache semantics stay distinct
- [ ] No repository layer was invented without a real lifecycle boundary
- [ ] Related commands, component callbacks, and pollers remain coherent

## Bottom line

State in this repo should feel deliberately small: `NHA` keeps the last typed
observation per agent, `StateStore` keeps durable bot configuration, and
`bot.php` keeps relay runtime bookkeeping. Do not invent DiscordPHP's repository
architecture until DiscordPHP-NHA has a real resource boundary that needs it.
