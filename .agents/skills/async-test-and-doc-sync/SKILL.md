---
name: async-test-and-doc-sync
description: >-
  Maintain test and documentation alignment — PHPUnit 9 unit tests, opt-in
  Discord integration tests, async wait helpers, PHPDoc, README, and
  environment examples. Use when changing public behavior or tests.
---

# Skill: async-test-and-doc-sync

Use this skill when adding or changing public behavior, writing or reviewing tests, updating PHPDoc, changing command coverage, or touching setup behavior that `README.md` or `.env.example` describes.

This is alignment skill. Load it when the question is not only "does the code work?" but "do tests prove it and do docs describe it?"

## Goal

Keep tests, PHPDoc, and repository documentation synchronized with public behavior:

- unit tests assert semantic behavior without live Discord
- integration tests are explicit, live, and opt-in
- async tests use the shared ReactPHP wait bridges
- every unit test extends `NHATestCase`
- PHPDoc describes local public APIs accurately
- `README.md` and `.env.example` reflect the executable users actually run

## Read in this order

1. `tests/functions.php` — `wait()`, `waitForDiscord()`, mock factories
2. `tests/NHATestCase.php` — unit-safe base
3. `tests/DiscordIntegrationTestCase.php` — live opt-in base
4. `tests/bootstrap.php` — autoload and environment loading
5. `tests/DiscordSingleton.php` — unit-safe and live Discord clients
6. `phpunit.xml` — PHPUnit 9 suite configuration
7. Representative unit tests:
   - `tests/Parts/AgentObservationTest.php`
   - `tests/VerbsTraitTest.php`
   - `tests/HelperTraitTest.php`
   - `tests/Http/EndpointTest.php`
   - `tests/StateStoreTest.php`
8. `README.md` and `.env.example`

## Core contract

Tests and docs form a contract surface:

- all unit-test classes extend `NHATestCase`
- tests requiring live Discord extend `DiscordIntegrationTestCase`
- `wait()` uses the unit-safe `DiscordSingleton::get()`
- `waitForDiscord()` uses the credential-gated live singleton
- `getMockDiscord()` and `getMockDiscordCommandClient()` provide lightweight dependency objects
- PHPUnit is version 9
- `tests/bootstrap.php` loads Composer, optional `.env`, helpers, singletons, and bases
- `README.md` is the local long-form user guide

This repository does not own DiscordPHP's generated docs, `guide/`, gateway tests, repository tests, type-map tests, or voice tests.

## Unit tests vs integration tests

### Unit tests

Every unit-test class extends `NHATestCase`, including tests for:

- endpoint binding
- typed verb argument forwarding
- observation fallback/default behavior
- helper formatting and builder creation
- JSON-backed state behavior
- command validation that can be isolated from I/O
- serialization and component construction that is unit-safe

Use `$this->getMockBuilder()` for isolated class dependencies. Use `getMockDiscord()` or `getMockDiscordCommandClient()` only when a real dependency object is needed without live credentials.

### Integration tests

Tests that require a connected Discord client extend `DiscordIntegrationTestCase` and use `waitForDiscord()`.

Examples:

- sending or updating Discord messages
- application-command registration
- real interaction/event behavior
- live channel lookup
- behavior that depends on gateway/application readiness

Live tests require `DISCORD_TOKEN` or `TOKEN` and `TEST_CHANNEL`. Missing credentials must skip, not fail the ordinary unit suite.

NHA network integration should also be opt-in and isolated from unit tests. Do not make the default suite depend on the public NHA service.

## The client factories

### `getMockDiscord()`

Creates a Discord client with an empty token and `NullLogger`. Use it for unit-safe Discord-backed objects. Do not claim it is connected.

### `getMockDiscordCommandClient()`

Creates a command client with an empty token and a null logger in `discordOptions`. Use it only when command-client construction is part of the unit contract.

### `DiscordSingleton`

Maintains separate singletons:

- `get()` for the unit-safe mock client
- `getLive()` for credential-gated live Discord

Do not let unit tests accidentally call `getLive()`.

## Test suite organization

### Directory layout

Tests mirror local source structure:

- NHA root utilities and traits → `tests/`
- HTTP classes → `tests/Http/`
- observation model → `tests/Parts/`
- infrastructure → root `tests/` files

### Where to place new tests

- `src/NHA/Http/Endpoint.php` → `tests/Http/EndpointTest.php`
- `src/NHA/Parts/AgentObservation.php` → `tests/Parts/AgentObservationTest.php`
- root NHA service/trait → `tests/{Name}Test.php`
- live-only behavior → matching test extending `DiscordIntegrationTestCase`

### `phpunit.xml`

Uses `tests/bootstrap.php` and discovers tests recursively under `tests/`. Keep syntax compatible with PHPUnit 9.

## Async testing patterns

### The `wait()` bridge

`wait()` schedules a callback on the unit-safe client's event loop:

```php
return wait(function (Discord $discord, callable $resolve) {
    $promise
        ->then(fn ($value) => $this->assertSame($expected, $value))
        ->then($resolve, $resolve);
});
```

### The `waitForDiscord()` bridge

Use the same pattern for live Discord work:

```php
return waitForDiscord(function (Discord $discord, callable $resolve) {
    $this->channel()->sendMessage($builder)
        ->then(fn ($message) => $this->assertNotNull($message->id))
        ->then($resolve, $resolve);
});
```

Key rules:

- return the wait call from the test
- pass `$resolve` through the final fulfillment and rejection path
- use the callback's client rather than constructing another loop owner
- keep the default timeout intentional
- use `waitForDiscord()` only from live integration tests
- do not use wait helpers for purely synchronous code

## Integration base rules

`DiscordIntegrationTestCase::setUpBeforeClass()`:

- requires `DISCORD_TOKEN` or `TOKEN`
- connects through the live singleton
- resolves `TEST_CHANNEL`
- skips when credentials, connection, or channel are unavailable

Use `$this->channel()` only after the base has established the live channel.

## PHPDoc as contract surface

### What to document

Document local public:

- Promise return types and resolved values
- endpoint/agent parameter meaning
- readonly model purpose
- fallback/default semantics when not obvious
- exceptions from validation or missing default agent
- intentional dependency boundaries

### Important distinction

`AgentObservation` should be documented as a plain read-only model, not with Discord Part magic-property annotations. Do not add `$fillable`-style PHPDoc or repository claims.

### When to update docblocks

- method signature or resolved Promise type changes
- new public getter or endpoint method is added
- fallback/default behavior changes
- a class's role changes
- executable/public command behavior changes enough to affect callers

## README and environment structure

### `README.md`

Describes:

- repository purpose
- local source layout
- command and interaction surfaces
- setup and startup
- default-agent registration flow

### `.env.example`

Lists executable configuration. Keep descriptions accurate to `bot.php`. If a variable is not consumed, do not claim active behavior for it.

### No local docs tree

Do not refer to `guide/` or `docs/` as local documentation surfaces. Those may exist in the installed DiscordPHP dependency, but DiscordPHP-NHA's owned public docs are currently `README.md`, `.env.example`, and PHPDoc.

## When docs must change

Docs should change when:

- a public NHA method or verb is added
- command names, arguments, aliases, or slash coverage change
- setup or environment requirements change
- observation output or interaction behavior changes meaningfully
- default behavior changes for existing bots

Docs should not change when:

- internal refactoring preserves public behavior
- unit test infrastructure changes without user impact
- upstream DiscordPHP internals change without a local API change

## Running tests and checks

| Purpose | Command |
| --- | --- |
| Run PHPUnit 9 suite | `composer unit` |
| Apply project code style | `composer cs` |
| Apply Pint formatting to `src` | `composer pint` |

Use only these repository-provided commands. Do not invent static-analysis, coverage, or docs-build commands that are not defined here.

## What to test

### Observation tests should assert

- nested dot-path reads
- fallback keys
- missing/default behavior
- collection-like empty defaults
- raw `jsonSerialize()` fidelity
- component construction behavior when practical

### Verb tests should assert

- exact verb string
- exact argument keys and values
- omission of null optional arguments
- returned Promise contract when meaningful

### HTTP tests should assert

- placeholder binding
- query binding
- local request URL construction
- unauthenticated header behavior when isolated safely

### State tests should assert

- missing file default
- persisted id
- reload behavior
- overwrite behavior

Use repository-local scratch paths for new tests when possible; do not introduce platform assumptions.

### Integration tests should assert

- live behavior completes asynchronously
- returned Discord objects are correctly typed
- missing live configuration skips cleanly
- unit suite remains network-independent

### What not to test

- DiscordPHP gateway behavior itself
- upstream repository/type-map/voice internals
- trivial getters with no semantic behavior
- implementation details instead of public outcomes
- public NHA service availability in ordinary unit tests

## Smells

Stop if you see:

- a unit test extending raw `PHPUnit\Framework\TestCase`
- a live test extending only `NHATestCase`
- default unit execution requiring Discord or NHA network access
- `waitForDiscord()` in a unit test
- `wait()` around synchronous assertions
- Promise chains that never resolve or reject through `$resolve`
- PHPUnit 10+ syntax added to this PHPUnit 9 suite
- `AgentObservation` documented as a Discord Part
- README command/setup claims that do not match `bot.php`
- references to nonexistent local `guide/` or `docs/` trees
- commands documented that are not in `composer.json`

## Checklist before commit

- [ ] Every unit test class extends `NHATestCase`
- [ ] Every live Discord test extends `DiscordIntegrationTestCase`
- [ ] Unit tests remain safe without credentials or network
- [ ] Async unit tests use and return `wait()`
- [ ] Live tests use and return `waitForDiscord()`
- [ ] Promise chains reach `$resolve` on fulfillment and rejection
- [ ] Tests are compatible with PHPUnit 9
- [ ] Test files mirror local source layout
- [ ] Public methods and model behavior have accurate PHPDoc
- [ ] `AgentObservation` remains documented as a plain model
- [ ] README updated if public command/setup behavior changed
- [ ] `.env.example` updated if executable configuration changed
- [ ] `composer unit` passes
- [ ] `composer cs` and `composer pint` are used as the available style commands
- [ ] No upstream-only DiscordPHP layer is presented as local ownership

## Bottom line

Tests prove local behavior, PHPDoc declares the API, and README/environment docs teach operation. Keep unit tests offline, live tests opt-in, all unit classes on `NHATestCase`, and every surface compatible with PHPUnit 9.
