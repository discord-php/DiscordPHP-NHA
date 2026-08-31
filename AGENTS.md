# DiscordPHP-NHA Agent Guide

This file is the repo operating manual for AI agents. It describes how to work inside this repository without breaking its design. Specialist skills in `.agents/skills/` provide deeper playbooks for the local extension layers.

If a change crosses layers, load multiple skills and use the playbooks in this file to keep boundaries clean.

DiscordPHP-NHA extends DiscordPHP rather than replacing it. DiscordPHP remains authoritative for Discord gateway/runtime behavior, Parts, repositories, builders, interactions, and related Discord infrastructure. This repository owns the NHA client, NHA transport, observation model, NHA verbs, shared command behavior, local state, and application wiring.

The NHA game itself is a separate external system. Its live API and game rules are authoritative for NHA world behavior.

## Start here

Before changing anything:

1. Identify the local layer you are touching.
2. Read the base implementation for that layer before copying a caller.
3. Read one representative test for the same behavior.
4. Trace how that layer connects to `bot.php` and to DiscordPHP.
5. Determine whether the behavior comes from DiscordPHP-NHA or from the external NHA game/API.
6. Make the change in the narrowest local class that can own it.
7. Update companion tests, PHPDoc, README examples, and skills that define the same contract.

In this repo, companion surfaces usually matter as much as the line you edit.

When a task involves actual NHA gameplay, intent verbs, observations, world state, crafting, combat, diplomacy, construction, economy, or Expansion-era mechanics, load `.agents/skills/nha-agent/SKILL.md`.

## Non-negotiable truths

1. **CLI-only runtime.** DiscordPHP-NHA is a long-running ReactPHP process. Do not design around web requests, controllers, middleware stacks, or per-request state.
2. **Async first.** NHA and Discord I/O returns `PromiseInterface`. Blocking helpers belong in tests only.
3. **`NHA` is an extension client.** `src/NHA/NHA.php` subclasses DiscordPHP's `MessageCommandClient`; Discord gateway lifecycle remains owned by the DiscordPHP dependency.
4. **`AgentObservation` is not a Discord Part.** It is a plain, read-only NHA model around one observation payload.
5. **`Commands` owns shared command behavior.** Prefix commands, slash commands, and component callbacks should reuse Promise-returning handlers that resolve to `MessageBuilder`.
6. **DiscordPHP builders own outbound Discord payload rules.** Local code composes `MessageBuilder`, `CommandBuilder`, and component builders; it does not duplicate their internals.
7. **NHA endpoint binding stays centralized.** Use `NHA\Http\Endpoint` and the local async HTTP client instead of scattering raw NHA URLs.
8. **Prefix and interaction entry points stay distinct.** They may share `Commands`, but parsing, acknowledgement, and response mechanics are different.
9. **The observation cache is last-known state.** `NHA::observe()` updates one `AgentObservation` per agent id; it is not a repository or durable store.
10. **Use current dependency idioms.** Prefer DiscordPHP builders, `listenCommand()`, `Button::setListener()`, and Promise chains already used by this repo.
11. **The NHA server is authoritative.** Do not duplicate live game rules in transport code, and never treat a successful intent submission as proof that the requested game action succeeded.
12. **NHA intents are asynchronous.** An intent is queued and resolved by the game on a later tick. Pending, applied, and rejected are distinct states.
13. **Do not assume observations remain current.** An observation is a snapshot of the world at a particular tick. Other agents and the game engine may change the world before an intent is applied.
14. **NHA authentication and Discord authentication are separate.** NHA action tokens belong to the NHA API and must not be mixed with Discord credentials or headers.
15. **Live NHA rules belong to the NHA service.** When current gameplay behavior matters, consult the live NHA API schema and rules instead of assuming this repository's wrappers are the complete specification.

## Skill map

Each skill lives in `.agents/skills/<name>/SKILL.md` and is loaded automatically when relevant. When a task crosses layers, load multiple skills.

| If task touches... | Skill to load |
| --- | --- |
| NHA game/API semantics, endpoint contracts, intent verbs, observations, agent lifecycle, crafting, economy, combat, diplomacy, construction, space, Expansion-era behavior | `nha-agent` |
| `src/NHA/NHA.php`, `bot.php` startup, loop wiring, NHA HTTP setup, observation cache | `runtime-bootstrap-keeper` |
| `src/NHA/Parts/AgentObservation.php`, observation accessors, rendering | `part-model-maintainer` |
| Discord repository/cache APIs consumed by local code | `repository-cache-keeper` |
| `Event::MESSAGE_CREATE` relay and other gateway-fed local behavior | `gateway-cache-sync-keeper` |
| Discord `MessageBuilder`, `CommandBuilder`, or Components V2 payload composition | `builder-payload-smith` |
| Discord interaction/component subtype maps used through the dependency | `type-map-keeper` |
| slash commands, `listenCommand()`, interaction acknowledgement or updates | `interaction-flow-keeper` |
| `MessageCommandClient`, prefix commands, aliases, subcommands | `legacy-command-client-keeper` |
| tests, PHPUnit async helpers, PHPDoc, README behavior | `async-test-and-doc-sync` |
| `src/NHA/Http/*`, `HelperTrait`, `StateStore`, endpoint and utility boundaries | `helpers-and-infra-keeper` |
| voice questions or attempted voice integration | `voice-subsystem-keeper` |

Repository internals, gateway cache machinery, type maps, and voice are primarily DiscordPHP dependency layers. Their skills should be used to keep the boundary explicit, not to imply that those upstream implementations live in DiscordPHP-NHA.

The `nha-agent` skill is domain guidance for building agents with this library. It must not become a second implementation of the NHA transport layer.

## NHA authoritative sources

When a change depends on current NHA gameplay or API behavior, use these sources in this order:

1. **Machine-readable API contract:** `https://nha.recluse.lol/openapi.json`
2. **Interactive API documentation:** `https://nha.recluse.lol/docs`
3. **Current game rules:** `https://nha.recluse.lol/rules`
4. **NHA agent guidance:** `https://github.com/Recluse/nha-mmo/blob/main/AGENTS.md`
5. **This repository's implementation and tests**

The upstream NHA game guide is useful operational documentation, but it is not a substitute for the live API contract when the two disagree.

Do not copy volatile game-balance numbers, cooldowns, caps, resource quantities, or other mutable values into this repository's generic abstractions unless the library specifically needs to encode that contract.

## NHA async and intent model

The NHA world is authoritative and asynchronous.

The conceptual lifecycle is:

```text
register/reuse
    ↓
observe
    ↓
decide
    ↓
queue intent
    ↓
world advances
    ↓
intent becomes applied or rejected
    ↓
observe again
```

The world advances independently of the client.

The response from `POST /intent` means the intent was accepted into the queue. **This response returns a unique intent ID.** It does not, by itself, mean the requested game action happened. To determine the outcome, you must poll the intent result endpoint using that ID or wait for a subsequent observation.

The relevant states are conceptually:

```text
queued
   ↓
pending
   ↓
applied
   └── rejected
```

Use the intent result endpoint and/or a later observation to determine the outcome.

### Consequences for library code

- `NHA::intent()` should remain an API operation rather than silently turning a queued intent into an assumed success.
- Typed verb methods in `VerbsTrait` should preserve the underlying intent semantics.
- Higher-level helpers may wait for an outcome only when that behavior is explicitly part of their contract.
- Callers must not blindly resubmit the same intent while the previous one is pending.
- Rejected game actions are not necessarily transport failures.
- A stale observation must not be treated as a guarantee that an action will still be valid when applied.

## NHA agent lifecycle

Registration is an initialization operation, not an action loop.

To identify yourself, your request should follow the NHA authentication contract. When reusing an existing identity, you MUST include the `"reuse": true` flag.

An agent should generally:

1. Register once.
2. Persist its returned NHA credentials securely.
3. Reuse the same identity across restarts (using `"reuse": true`).
4. Observe the current state.
5. Submit intents as needed.
6. Re-observe as the world advances.

NHA action tokens are secrets.

Never:

- commit tokens to the repository
- hardcode tokens in examples intended for publication
- log tokens
- include tokens in exception text
- put NHA tokens in Discord messages
- confuse a NHA token with the Discord bot token

The small local `StateStore` may persist durable agent identity information where required by the application, but network credentials must still be handled as secrets.

## Library Mapping

This library provides a structured way to interact with the NHA API.

| NHA Concept | Library Implementation | Notes |
| --- | --- | --- |
| **API Endpoint** | `NHA\Http\Endpoint` | Centralized binding for all NHA routes. |
| **Game Action (Intent)** | `NHA\VerbsTrait` / `NHA::intent()` | Wrappers for sending actions to the queue. |
| **World State Snapshot** | `NHA\Parts\AgentObservation` | A read-only, non-Discord model of a single tick. |
| **Data Repository** | `NHA\Repository\*` | Specialized classes to fetch and map NHA data to `Out` parts. |
| **Auth Token** | `NHA` authentication headers | Must be kept separate from Discord credentials. |
| **Client** | `NHA\Client\Client` | The core NHA client, residing in `NHA\Client`. |

When using the library, remember:
- **Intents are queued, not completed.** A successful `POST` only means the action was accepted into the game's queue.
- **Observations are snapshots.** They represent the world at a specific tick.
- **Repositories return `Out` parts.** These are Discord-compatible models that extend `Discord\Parts\Part`.

## Reclaiming Identity

When an agent needs to resume its previous session, it must use the authentication contract to reuse its existing NHA identity.

1. **Reuse Flag**: When calling the registration endpoint, you MUST include `"reuse": true` in the payload if you wish to maintain your existing credentials and agent state.
2. **Persistence**: The client should persist the returned NHA credentials securely (e.g., via `StateStore`) to allow for seamless reuse across restarts.
3. **Identity vs Connection**: Reusing an NHA identity is separate from reconnecting to the Discord Gateway. An agent should be able to reconnect to Discord without necessarily changing its NHA identity.


## Downed agents

The current NHA game contract restricts what a downed agent can do.

Client code should not invent its own recovery rules.

When an observation shows the agent is downed:

- consult the current NHA rules/API contract
- avoid repeatedly submitting actions known to be illegal
- preserve the server's rejection behavior
- allow the agent's strategy layer to decide how to recover or communicate

Do not encode old or guessed downed-state behavior as permanent library assumptions.

## Action vocabulary and verbs

`VerbsTrait` provides typed convenience wrappers over `NHA::intent()`.

These methods should:

- use the exact server-side verb names
- use the exact argument keys expected by the API
- preserve Promise-based behavior
- avoid duplicating HTTP transport
- avoid embedding strategy decisions

A verb wrapper is a transport/API convenience, not an autonomous strategy.

When adding a new verb:

1. Verify its current form in `/openapi.json`.
2. Verify its semantics in `/docs` and `/rules` when relevant.
3. Add the smallest typed wrapper necessary.
4. Preserve the server's asynchronous result.
5. Add tests around request shape and resolution behavior.
6. Update `nha-agent/SKILL.md` if the verb changes the guidance available to agent authors.

The API accepts well-formed verb strings even when the game engine does not recognize the verb. Unknown or invalid gameplay verbs may therefore reach the game layer and become rejected intents.

Do not confuse API-level acceptance with game-level success.

## Game-domain guidance

The game-specific skill should contain detailed agent strategy and gameplay guidance. Core library classes should remain comparatively neutral.

The following principles are nevertheless important at the repository level.

### Survival before optimization

An autonomous agent should generally consider:

1. whether it is alive and able to act
2. whether it is in immediate danger
3. whether an existing action or obligation should be completed
4. whether current resources support its next goal
5. what long-term strategy to pursue

This is guidance for consumers of the library, not a reason to put strategy logic into `NHA.php`.

### Crafting and invention

Crafting rules are owned by the NHA game engine.

The library should expose the API accurately without hardcoding a complete recipe database unless a specific client feature requires one.

Agents may use current observations and rules to decide:

- what to craft
- what materials to reserve
- whether to experiment
- whether a newly discovered recipe or property is strategically valuable

A rejected crafting action should not automatically result in infinite retries.

### Economy and trading

Trading and market behavior are asynchronous.

Agents should distinguish between:

- creating an order or offer
- an order or offer remaining open
- a trade being executed
- a trade being rejected or cancelled

The library should expose the underlying API state accurately rather than hiding pending trade state behind optimistic assumptions.

### Combat

Combat decisions should use current observations whenever practical.

The library should not assume:

- a target remains at the same location
- an agent remains in range
- inventory and ammunition have not changed
- another agent has not intervened
- a previous attack succeeded

The game server remains authoritative for range, target validity, resource consumption, damage, and combat outcomes.

### Diplomacy and communication

Diplomacy is game behavior, not Discord command infrastructure.

An NHA agent may use `say`, `tell`, alliance, war, peace, trade, and related verbs through the NHA API, but strategic decisions belong to the agent.

Do not couple NHA social behavior to Discord user identities unless the application explicitly defines such a mapping.

### Construction and cooperation

Large NHA projects may require coordinated contributions from multiple agents.

Client wrappers should expose project state and action results accurately.

They should not assume:

- that one agent can always complete a project
- that a contribution immediately changes final project state
- that the same contribution remains valid after another agent acts

### Space and Expansion-era behavior

Space, orbital, lunar, and interplanetary behavior is governed by the current NHA rules.

When implementing support:

- inspect current capability requirements
- inspect current location/orbit/body state
- account for delayed actions
- do not hardcode stale transfer or landing assumptions
- preserve the server's authoritative result

An agent attempting to travel should verify enough current state to avoid making decisions based on an obsolete observation.

## Architecture map

| Layer | Owns | Primary files | What to preserve |
| --- | --- | --- | --- |
| Extension client | DiscordPHP command-client subclass, NHA HTTP wiring, observation cache, NHA API methods | `src/NHA/NHA.php` | parent runtime contract, Promise returns, one NHA HTTP client, cache update after successful observe |
| NHA transport | endpoint constants/binding, unauthenticated request queue, base URL | `src/NHA/Http/Endpoint.php`, `src/NHA/Http/Http.php`, `src/NHA/Http/Request.php` | `Endpoint::bind()`, React driver, no Discord authorization header, Promise-based API |
| Observation model | read-only observation payload access and Discord rendering | `src/NHA/Parts/AgentObservation.php` | plain model status, raw payload fidelity, fallback keys, Components V2 limits |
| NHA verbs | typed convenience wrappers over `NHA::intent()` | `src/NHA/VerbsTrait.php` | exact verb names and argument keys; no duplicated transport |
| Shared command service | command semantics and response builders shared by every entry point | `src/NHA/Commands.php` | resolve default agent once, return Promises resolving to `MessageBuilder`, keep entry-point details out |
| Local state | durable default agent id | `src/NHA/StateStore.php` | tiny JSON-backed scope; no Discord or network concerns |
| Application wiring | environment, logging, prefix commands, slash commands, buttons, polling, relay, startup | `bot.php` | thin adapters over `Commands`, deferred interactions, dual-ready slash registration, non-blocking polling |
| Tests and docs | behavioral contract | `tests/*`, `phpunit.xml`, `README.md`, `.env.example`, `.agents/skills/*` | PHPUnit 9, unit/live separation, async helpers, public setup accuracy, current agent guidance |

## Dependency boundaries

DiscordPHP-NHA depends on upstream packages rather than owning their internals:

| Dependency | Why it matters | Local touchpoints |
| --- | --- | --- |
| `team-reflex/discord-php` | `MessageCommandClient`, gateway events, interactions, Parts, repositories, builders, components | `NHA extends MessageCommandClient`; imports throughout `bot.php`, `Commands.php`, and `AgentObservation.php` |
| `discord-php/http` | `HttpTrait`, `EndpointTrait`, request driver, endpoint interfaces | `src/NHA/Http/*`, `NHA::__construct()` |
| ReactPHP | loop and Promise execution | all production I/O, polling timer, tests' wait bridges |
| NHA MMO API | agent registration, observations, intents, world state, gameplay rules | `src/NHA/NHA.php`, `src/NHA/Http/*`, `src/NHA/VerbsTrait.php` |

Discord repositories, gateway handlers, interaction type maps, builder validation internals, and voice are upstream DiscordPHP concerns. If a local change exposes a dependency defect, identify that boundary explicitly; do not describe those upstream layers as owned by this repository.

The NHA game engine is also an external authority. This repository adapts its API; it does not own the world simulation.

## Repo worldview

### Runtime to response flow

`bot.php` constructs `NHA`. DiscordPHP's parent client owns gateway bootstrap. `NHA` adds an HTTP client for the NHA world. Prefix and slash adapters call `Commands`; `Commands` calls `NHA` methods or typed verb wrappers; those return Promises; the resolved `MessageBuilder` is sent or used to update an interaction. `observe()` additionally replaces the cached `AgentObservation` for that agent.

For autonomous NHA behavior, the logical flow is:

```text
NHA client
    ↓
observe current world
    ↓
agent strategy
    ↓
typed verb / intent
    ↓
NHA server queues action
    ↓
world advances
    ↓
intent resolves
    ↓
observe current world again
```

### Why the split matters

- If `NHA` absorbs command formatting, the client becomes an application script instead of an API extension.
- If `bot.php` duplicates business behavior, prefix and slash commands drift.
- If `AgentObservation` becomes a Discord Part, NHA world data acquires false Discord persistence and repository semantics.
- If callers build raw NHA URLs, endpoint changes spread through the codebase.
- If interaction callbacks skip acknowledgement before network work, Discord responses can time out.
- If an NHA caller assumes intent submission is success, the agent will make decisions from false world state.
- If game rules are duplicated in low-level transport classes, server-side rule changes will create stale client behavior.
- If an agent does not account for tick progression, it will make decisions using stale observations.

## Common class patterns

### Extension client and verbs

Expect:

- `NHA extends MessageCommandClient`
- one local `NHA\Http\Http` instance created after parent construction
- API methods returning `PromiseInterface`
- read-only endpoint helpers delegating through `fetch()`
- verb helpers delegating through `intent()`
- observation caching only after a successful response

Semantic rules:

- the parent constructor owns Discord client option resolution and gateway setup
- the NHA HTTP client shares the parent loop and logger
- NHA requests use the NHA API's own authentication rules; do not add Discord authorization to them
- the local `$agents` array is a last-observation cache, not a Discord repository
- the client should not embed autonomous strategy or world simulation

### Observation model

Expect:

- readonly `agentId` and raw payload
- `get()` for nested dot-separated access
- focused convenience accessors with documented fallback keys
- `jsonSerialize()` returning the original payload
- `toContainer()` producing a Components V2 view and button listeners

Semantic rules:

- keep it a plain PHP model implementing `JsonSerializable`
- do not add `Discord\Parts\Part`, `$fillable`, repositories, `save()`, or factory hydration
- rendering may use DiscordPHP builders, but stored data remains NHA-shaped
- do not silently normalize away fields needed to understand current NHA responses
- accessors should document fallback keys when NHA payload variants require them
- the observation tick is meaningful state and should remain accessible

### Shared commands

Expect:

- constructor injection of `NHA` and `StateStore`
- methods returning Promises that resolve to `MessageBuilder`
- default-agent resolution in `resolveAgentId()`
- common formatting helpers for Components V2 responses

Semantic rules:

- no `Message` or `Interaction` parameter belongs in the shared command API
- transport and command UI adapters remain in `bot.php`
- rejected validation should remain a rejected Promise or thrown exception handled by the adapter
- NHA API/game errors should not be disguised as successful command output

### Application wiring

Expect:

- prefix subcommands registered through `MessageCommandClient`
- slash definitions authored with `CommandBuilder` and `Option`
- `listenCommand()` callbacks routed to `Commands`
- interaction acknowledgement before NHA network calls complete
- slash registration gated by both `init` and `application-init`
- periodic observation through `Loop::get()->addPeriodicTimer()`
- `Event::MESSAGE_CREATE` relay filtering bots, wrong channels, and prefixed commands

The application layer may implement scheduling and strategy, but low-level NHA transport belongs in the client/HTTP layer.

## Cross-layer rules

### 1. `NHA` owns NHA transport; DiscordPHP owns Discord runtime

Local code may add NHA API behavior to the subclass, but it must not reimplement Discord gateway connection, intent maps, repositories, or event dispatch.

Smell: a local gateway handler or Discord resource cache introduced to avoid using the parent client.

### 2. Observation semantics stay outside Discord Parts

`AgentObservation` may render with Discord components, but it models the NHA world.

Smell: adding `$fillable`, `getRepository()`, `save()`, or Discord factory construction to `AgentObservation`.

### 3. Shared command behavior returns builders

`Commands` should perform validation, call the NHA client, and produce response builders. Prefix and interaction adapters decide how to deliver them.

Smell: the same register, observe, or act flow implemented independently in two callbacks.

### 4. Builders own Discord payload shape

Use DiscordPHP builder APIs for messages, application commands, and components. Keep allowed-mention defaults centralized in `HelperTrait::createBuilder()`.

Smell: new nested response arrays where a DiscordPHP builder already exists.

### 5. Traits carry narrow horizontal behavior

`VerbsTrait` carries typed intent convenience methods. `HelperTrait` carries small builder and formatting helpers.

Smell: adding stateful orchestration, HTTP queues, or game strategy to either trait.

### 6. The NHA server owns gameplay rules

Transport and convenience wrappers should not become a second game engine.

Client-side validation is appropriate for:

- malformed request arguments
- obviously invalid types
- local API-shape guarantees

Server-side validation remains authoritative for:

- whether an action is currently legal
- whether a target exists
- whether resources are sufficient
- whether an agent is in range
- whether a recipe/project/travel condition is satisfied
- whether an action succeeds
- whether an action is rejected

Smell: a low-level NHA class reproducing large portions of game simulation logic.

### 7. Intent submission and intent success stay separate

A successful HTTP request means the API accepted the request according to the transport contract. It does not guarantee the game engine will apply the action.

Smell: returning `true`, a "success" object, or a completed domain action from `intent()` solely because the POST returned successfully.

### 8. Tick-aware decisions belong above transport

The HTTP layer should not decide when an agent should act.

The runtime/strategy layer may track:

- latest observation tick
- pending intents
- completed intent results
- recent rejections
- current goals

The transport layer should simply expose the server contract accurately.

## Common companion surfaces

If you touch one of these, inspect the companions too:

| Touching | Also inspect |
| --- | --- |
| `src/NHA/NHA.php` constructor or API method | `src/NHA/Http/*`, `src/NHA/VerbsTrait.php`, tests, `bot.php` callers |
| `src/NHA/Http/Endpoint.php` | `NHA` methods, endpoint tests, live NHA API documentation |
| `src/NHA/Parts/AgentObservation.php` | observation tests, `Commands::observe()`, polling and button flows in `bot.php`, `nha-agent` skill |
| `src/NHA/VerbsTrait.php` | NHA API verb contract, `Commands`, `VerbsTraitTest`, `nha-agent` skill |
| `src/NHA/Commands.php` | both prefix and slash dispatch in `bot.php`, builder behavior, tests |
| `src/NHA/StateStore.php` | registration/default-agent flows, polling relay, state tests |
| prefix command registration | equivalent slash dispatch and `Commands` method |
| slash command definition | slash dispatch, `listenCommand()` callback, interaction response path |
| component buttons | `AgentObservation::toContainer()`, async update flow, component limits |
| public setup or environment variables | `README.md`, `.env.example`, `bot.php` |
| an NHA API route or verb | `/openapi.json`, `/docs`, `/rules`, endpoint binding, typed wrappers, tests, `nha-agent` skill |
| NHA gameplay guidance | `.agents/skills/nha-agent/SKILL.md`, upstream NHA `AGENTS.md`, current API/rules |
| authentication behavior | `StateStore`, NHA HTTP request headers/body, README setup documentation |
| polling or tick scheduling | `NHA::observe()`, `bot.php`, ReactPHP loop usage, async tests |

## Change playbooks

### Playbook: editing the extension client

1. Keep parent construction first.
2. Reuse the parent's loop and logger for NHA HTTP.
3. Bind parameterized routes through `NHA\Http\Endpoint`.
4. Return Promises; do not block.
5. Update the observation cache only with a successfully materialized observation.
6. Preserve NHA authentication semantics.
7. Do not turn queued intents into assumed successes.
8. Check typed verb wrappers and every command caller.
9. Add or update unit tests around local behavior.
10. Update documentation and the NHA skill when public semantics changed.

### Playbook: editing an NHA endpoint

1. Check `/openapi.json` first.
2. Check `/docs` and `/rules` when semantics are not obvious from the schema.
3. Use `NHA\Http\Endpoint` rather than assembling raw URLs in callers.
4. Reuse the local async HTTP abstraction.
5. Preserve request and response fields without inventing unsupported defaults.
6. Return a Promise.
7. Update tests with representative API responses.
8. Update PHPDoc and README examples when user-facing behavior changed.
9. Update `.agents/skills/nha-agent/SKILL.md` if the endpoint materially changes what agent authors can do.

### Playbook: editing an NHA verb

1. Verify the verb name and argument shape against `/openapi.json`.
2. Verify semantics against `/docs` or `/rules` when appropriate.
3. Add the thinnest wrapper possible to `VerbsTrait`.
4. Delegate to `NHA::intent()`.
5. Do not add game simulation logic.
6. Preserve queued/pending/applied/rejected semantics.
7. Add request-shape and error-path tests.
8. Update the NHA skill with agent-facing implications.

### Playbook: editing the observation model

1. Preserve readonly raw payload and agent id.
2. Preserve the observation tick.
3. Add fallback accessors only for real NHA payload variants.
4. Keep `jsonSerialize()` faithful to the raw response.
5. Build output through DiscordPHP component builders.
6. Respect component and text limits.
7. Keep button listeners Promise-based and refresh through `observe()`.
8. Add semantic unit tests.
9. Update `nha-agent` guidance when newly exposed state changes agent decision-making.

### Playbook: editing outbound payloads

1. Start from `HelperTrait::createBuilder()` for messages.
2. Put reusable response creation in `Commands` or `AgentObservation`.
3. Use DiscordPHP component and command builders.
4. Keep optional fields omitted unless intentionally supplied.
5. Confirm interaction and prefix delivery paths accept the same resolved builder.
6. Update tests and README examples if public usage changed.

### Playbook: editing interactions or commands

1. Keep application-command and prefix-command adapters separate.
2. Put shared semantics in `Commands`.
3. Keep interaction option flattening and dispatch explicit.
4. Acknowledge interactions before waiting on NHA I/O.
5. Keep slash registration behind both readiness signals.
6. Preserve prefix parsing, aliases, and help metadata supplied by DiscordPHP.
7. Update both entry points when public command coverage changes.

### Playbook: changing polling or autonomous runtime behavior

1. Verify whether the change belongs to application scheduling or the NHA client.
2. Reuse the existing ReactPHP loop.
3. Avoid blocking sleeps.
4. Track the latest observation tick where necessary.
5. Do not issue duplicate intents merely because a previous intent has not resolved yet.
6. Re-observe after meaningful world progression.
7. Keep strategy and scheduling separate from HTTP transport.
8. Add async tests for the new scheduling semantics.

## NHA agent design guidance

The `nha-agent` skill contains the detailed strategy guidance, but agents built with this library should generally follow these architectural principles.

### Observe before deciding

A robust agent should prefer:

```text
observe
→ inspect current state
→ choose action
→ submit intent
→ wait for world progression
→ observe again
```

rather than:

```text
guess
→ act repeatedly
→ assume success
```

### Track pending work

An autonomous agent should keep enough local state to avoid duplicate actions.

Useful strategy state may include:

```text
last_observed_tick
pending_intents
recent_intent_results
last_successful_action
recent_rejections
current_goal
goal_progress
```

This belongs in an agent runtime or strategy implementation, not in the low-level NHA HTTP client.

### Adapt to rejection

A rejected action is information.

Agents should inspect the rejection reason and determine whether:

- prerequisites are missing
- the world changed
- the target disappeared
- the agent is in the wrong state
- another action is needed first
- the action should simply be abandoned

Repeatedly submitting the same rejected action without meaningful state change is a design smell.

### Avoid stale plans

A plan spanning multiple ticks should be periodically revalidated against new observations.

This is particularly important for:

- movement
- resource gathering
- combat
- trading
- construction
- space travel

## Design tripwires

If you see one of these, slow down:

- a synchronous wait or loop-stop trick outside tests
- a raw NHA URL assembled outside `NHA\Http\Endpoint` or `Request`
- Discord authorization added to the NHA HTTP client
- `AgentObservation` treated as a Discord Part or persisted through a repository
- command callbacks duplicating `Commands` business logic
- a shared command method depending on `Message` or `Interaction`
- an interaction doing NHA network work before acknowledgement
- slash registration happening before both `init` and `application-init`
- raw component, command, or message arrays replacing existing DiscordPHP builders
- local claims of owning DiscordPHP gateway internals, repositories, type maps, or voice
- production code writing state anywhere other than the intentionally small `StateStore`
- treating a successful `POST /intent` as proof that the game action succeeded
- polling indefinitely without respecting world tick progression
- repeatedly submitting the same pending or rejected intent
- encoding mutable NHA balance numbers into transport abstractions
- implementing NHA game simulation inside `NHA\Http/*`
- adding strategy logic directly to `VerbsTrait`
- assuming an `AgentObservation` remains authoritative after the world advances
- silently converting an NHA rejection into a successful application-level response
- adding a new game verb without checking `/openapi.json`
- changing game-domain behavior without updating `nha-agent` guidance when appropriate

## Preferred reference files

When you need an example worth imitating, start here:

- Extension orchestration: `src/NHA/NHA.php`
- NHA endpoints and transport: `src/NHA/Http/Endpoint.php`, `src/NHA/Http/Http.php`, `src/NHA/Http/Request.php`
- Typed NHA verbs: `src/NHA/VerbsTrait.php`
- Plain observation model and component rendering: `src/NHA/Parts/AgentObservation.php`
- Shared command semantics: `src/NHA/Commands.php`
- Local persistence: `src/NHA/StateStore.php`
- Runtime adapters and startup: `bot.php`
- Unit test base: `tests/DiscordTestCase.php`
- Live test base: `tests/DiscordIntegrationTestCase.php`
- Async test bridge: `tests/functions.php`
- NHA domain guidance: `.agents/skills/nha-agent/SKILL.md`
- Live NHA machine schema: `https://nha.recluse.lol/openapi.json`
- Live NHA API documentation: `https://nha.recluse.lol/docs`
- Live NHA rules: `https://nha.recluse.lol/rules`
- Upstream NHA agent guide: `https://github.com/Recluse/nha-mmo/blob/main/AGENTS.md`

For DiscordPHP builder, interaction, repository, gateway, type-map, or voice internals, inspect the installed/upstream DiscordPHP dependency and treat changes there as a separate repository task.

## Testing and docs workflow

### Tests

- PHPUnit is version 9.
- Every unit-test class extends `DiscordTestCase`.
- Tests needing live Discord infrastructure extend `DiscordIntegrationTestCase`.
- Use `$this->getMockBuilder()` for isolated dependencies and `getMockDiscord()` for unit-safe Discord-backed objects.
- Use and return `wait()` for asynchronous work against the unit-safe singleton.
- Use and return `waitForDiscord()` only for live, opt-in integration work.
- Keep semantic tests focused on behavior, not incidental implementation details.
- Unit tests must not require live NHA credentials.
- Tests of NHA HTTP behavior should use representative fixtures or mocks rather than assuming the live world is deterministic for a unit test.
- Integration tests involving NHA should be clearly separated from ordinary unit coverage.

### Docs

- Public class and method behavior should remain accurate in PHPDoc.
- This repository's long-form public guide is `README.md`; setup variables are mirrored in `.env.example`.
- Do not invent local `guide/` or `docs/` ownership. Those trees belong to the DiscordPHP dependency, not DiscordPHP-NHA.
- Keep docs in sync when preferred usage, command coverage, or environment requirements change.
- Keep `.agents/skills/nha-agent/SKILL.md` in sync when the public NHA agent contract changes.
- Do not duplicate the entire NHA game's mutable API reference in this repository's README or AGENTS file.
- Link or point agent authors toward the live NHA API/rules where appropriate.

## Useful commands

| Purpose | Command |
| --- | --- |
| PHPUnit 9 suite | `composer unit` |
| project code style | `composer cs` |
| Pint formatting for `src` | `composer pint` |

Live integration tests require `DISCORD_TOKEN` or `TOKEN` and `TEST_CHANNEL`; ordinary unit tests must remain safe without live credentials.

NHA action credentials must not be committed to `.env`, fixtures, logs, or source control.

## Final rule

When unsure where code belongs, choose the local class that already owns the same kind of knowledge, and stop at the DiscordPHP dependency boundary.

For NHA gameplay behavior, stop again at the NHA server boundary: the library should expose the game's current API accurately, while the NHA server remains authoritative for world state and action outcomes.

Matching the existing ownership model matters more than shaving a few lines off one callback, and preserving the distinction between **DiscordPHP behavior, library behavior, and NHA game behavior** is more important than making any one layer appear convenient.