# DiscordPHP-NHA Agent Guide

This file is the repo operating manual for AI agents. It describes how to work inside this repository without breaking its design. Specialist skills in `.agents/skills/` provide deeper playbooks for the local extension layers.

If a change crosses layers, load multiple skills and use the playbooks in this file to keep boundaries clean.

## Start here

Before changing anything:

1. Identify the local layer you are touching.
2. Read the base implementation for that layer before copying a caller.
3. Read one representative test for the same behavior.
4. Trace how that layer connects to `bot.php` and to DiscordPHP.
5. Make the change in the narrowest local class that can own it.
6. Update companion tests, PHPDoc, and README examples that define the same contract.

In this repo, companion surfaces usually matter as much as the line you edit.

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

## Skill map

Each skill lives in `.agents/skills/<name>/SKILL.md` and is loaded automatically when relevant. When a task crosses layers, load multiple skills.

| If task touches... | Skill to load |
| --- | --- |
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
| Tests and docs | behavioral contract | `tests/*`, `phpunit.xml`, `README.md`, `.env.example` | PHPUnit 9, unit/live separation, async helpers, public setup accuracy |

## Dependency boundaries

DiscordPHP-NHA depends on upstream packages rather than owning their internals:

| Dependency | Why it matters | Local touchpoints |
| --- | --- | --- |
| `team-reflex/discord-php` | `MessageCommandClient`, gateway events, interactions, Parts, repositories, builders, components | `NHA extends MessageCommandClient`; imports throughout `bot.php`, `Commands.php`, and `AgentObservation.php` |
| `discord-php/http` | `HttpTrait`, `EndpointTrait`, request driver, endpoint interfaces | `src/NHA/Http/*`, `NHA::__construct()` |
| ReactPHP | loop and Promise execution | all production I/O, polling timer, tests' wait bridges |

Discord repositories, gateway handlers, interaction type maps, builder validation internals, and voice are upstream DiscordPHP concerns. If a local change exposes a dependency defect, identify that boundary explicitly; do not describe those upstream layers as owned by this repository.

## Repo worldview

### Runtime to response flow

`bot.php` constructs `NHA`. DiscordPHP's parent client owns gateway bootstrap. `NHA` adds an unauthenticated HTTP client for the NHA world. Prefix and slash adapters call `Commands`; `Commands` calls `NHA` methods or typed verb wrappers; those return Promises; the resolved `MessageBuilder` is sent or used to update an interaction. `observe()` additionally replaces the cached `AgentObservation` for that agent.

### Why the split matters

- If `NHA` absorbs command formatting, the client becomes an application script instead of an API extension.
- If `bot.php` duplicates business behavior, prefix and slash commands drift.
- If `AgentObservation` becomes a Discord Part, NHA world data acquires false Discord persistence and repository semantics.
- If callers build raw NHA URLs, endpoint changes spread through the codebase.
- If interaction callbacks skip acknowledgement before network work, Discord responses can time out.

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
- NHA requests are unauthenticated; Discord credentials are for the Discord client only
- the local `$agents` array is a last-observation cache, not a Discord repository

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

### Application wiring

Expect:

- prefix subcommands registered through `MessageCommandClient`
- slash definitions authored with `CommandBuilder` and `Option`
- `listenCommand()` callbacks routed to `Commands`
- interaction acknowledgement before NHA network calls complete
- slash registration gated by both `init` and `application-init`
- periodic observation through `Loop::get()->addPeriodicTimer()`
- `Event::MESSAGE_CREATE` relay filtering bots, wrong channels, and prefixed commands

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

Smell: adding stateful orchestration or HTTP queues to either trait.

## Common companion surfaces

If you touch one of these, inspect the companions too:

| Touching | Also inspect |
| --- | --- |
| `src/NHA/NHA.php` constructor or API method | `src/NHA/Http/*`, `src/NHA/VerbsTrait.php`, tests, `bot.php` callers |
| `src/NHA/Http/Endpoint.php` | `NHA` methods, endpoint tests, NHA API documentation |
| `src/NHA/Parts/AgentObservation.php` | observation tests, `Commands::observe()`, polling and button flows in `bot.php` |
| `src/NHA/VerbsTrait.php` | NHA API verb contract, `Commands`, `VerbsTraitTest` |
| `src/NHA/Commands.php` | both prefix and slash dispatch in `bot.php`, builder behavior, tests |
| `src/NHA/StateStore.php` | registration/default-agent flows, polling relay, state tests |
| prefix command registration | equivalent slash dispatch and `Commands` method |
| slash command definition | slash dispatch, `listenCommand()` callback, interaction response path |
| component buttons | `AgentObservation::toContainer()`, async update flow, component limits |
| public setup or environment variables | `README.md`, `.env.example`, `bot.php` |

## Change playbooks

### Playbook: editing the extension client

1. Keep parent construction first.
2. Reuse the parent's loop and logger for NHA HTTP.
3. Bind parameterized routes through `NHA\Http\Endpoint`.
4. Return Promises; do not block.
5. Update the observation cache only with a successfully materialized observation.
6. Check typed verb wrappers and every command caller.
7. Add or update unit tests around local behavior.

### Playbook: editing the observation model

1. Preserve readonly raw payload and agent id.
2. Add fallback accessors only for real NHA payload variants.
3. Keep `jsonSerialize()` faithful to the raw response.
4. Build output through DiscordPHP component builders.
5. Respect component and text limits.
6. Keep button listeners Promise-based and refresh through `observe()`.
7. Add semantic unit tests.

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

## Design tripwires

If you see one of these, slow down:

- a synchronous wait or loop-stop trick outside tests
- a raw NHA URL assembled outside `NHA\Http\Endpoint` or `Request`
- Discord authorization added to the unauthenticated NHA HTTP client
- `AgentObservation` treated as a Discord Part or persisted through a repository
- command callbacks duplicating `Commands` business logic
- a shared command method depending on `Message` or `Interaction`
- an interaction doing NHA network work before acknowledgement
- slash registration happening before both `init` and `application-init`
- raw component, command, or message arrays replacing existing DiscordPHP builders
- local claims of owning DiscordPHP gateway internals, repositories, type maps, or voice
- production code writing state anywhere other than the intentionally small `StateStore`

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

### Docs

- Public class and method behavior should remain accurate in PHPDoc.
- This repository's long-form public guide is `README.md`; setup variables are mirrored in `.env.example`.
- Do not invent local `guide/` or `docs/` ownership. Those trees belong to the DiscordPHP dependency, not DiscordPHP-NHA.
- Keep docs in sync when preferred usage, command coverage, or environment requirements change.

## Useful commands

| Purpose | Command |
| --- | --- |
| PHPUnit 9 suite | `composer unit` |
| project code style | `composer cs` |
| Pint formatting for `src` | `composer pint` |

Live integration tests require `DISCORD_TOKEN` or `TOKEN` and `TEST_CHANNEL`; ordinary unit tests must remain safe without live credentials.

## Final rule

When unsure where code belongs, choose the local class that already owns the same kind of knowledge, and stop at the DiscordPHP dependency boundary. Matching the existing ownership model matters more than shaving a few lines off one callback.
