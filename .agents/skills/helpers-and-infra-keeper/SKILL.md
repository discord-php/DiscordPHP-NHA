---
name: helpers-and-infra-keeper
description: >-
  Work with DiscordPHP-NHA infrastructure — HelperTrait, NHA HTTP transport,
  Endpoint binding, Request URLs, StateStore JSON persistence, ReactPHP
  promises, and discord-php/http contracts.
---

# Skill: helpers-and-infra-keeper

Use this skill when work touches:

- `src/NHA/HelperTrait.php`
- `src/NHA/Http/*`
- `src/NHA/StateStore.php`
- ReactPHP Promise chains in `src/NHA/NHA.php` or `src/NHA/Commands.php`
- contracts inherited from `discord-php/http`

These are cross-cutting infrastructure utilities. They should contain reusable
mechanics and explicit boundaries, not NHA game strategy or Discord command
policy.

## Read in this order

1. `src/NHA/HelperTrait.php` — message-builder safety and progress rendering
2. `src/NHA/Http/Endpoint.php` — NHA route constants and endpoint contract
3. `src/NHA/Http/Request.php` — NHA base-URL resolution
4. `src/NHA/Http/Http.php` — request queueing and HTTP contract adaptation
5. `src/NHA/StateStore.php` — JSON-backed local configuration
6. `src/NHA/NHA.php` — how the HTTP client and Promises are consumed
7. `src/NHA/Commands.php` — Promise transformation into Discord responses
8. `tests/HelperTraitTest.php`, `tests/Http/EndpointTest.php`, and
   `tests/StateStoreTest.php`

## HelperTrait

`HelperTrait` contains small presentation helpers shared by `NHA` and
`AgentObservation`.

### `createBuilder(bool $prevent_mentions = true)`

This returns a DiscordPHP `MessageBuilder`. By default it applies
`AllowedMentions::none()` so NHA/world-controlled text cannot unexpectedly ping
users or roles.

**Rule:** preserve mention prevention as the default. A caller that enables
mentions must make that choice explicitly and must trust or sanitize the
content source.

### `bar(float $current, float $max, int $length = 10)`

This renders a bounded visual ratio while preserving the original numeric
values in the label. It clamps the filled portion to 0–100% and treats
non-positive maximum values as `1`.

Keep it deterministic and presentation-only. NHA health rules do not belong in
the helper.

## HTTP infrastructure

### Contract layers

| Class | Role |
| --- | --- |
| `NHA\Http\Endpoint` | NHA route constants plus `Discord\Http\EndpointInterface` / `EndpointTrait` behavior |
| `NHA\Http\Request` | Extends `Discord\Http\Request` and prefixes `Http::BASE_URL` |
| `NHA\Http\Http` | Implements `Discord\Http\HttpInterface`, uses `HttpTrait`, and queues NHA requests |
| `Discord\Http\Drivers\React` | Dependency-provided asynchronous HTTP driver constructed by `NHA` |

The local classes adapt `discord-php/http` contracts to the unauthenticated NHA
API. Do not duplicate the dependency's queue, bucket, Promise, or endpoint
machinery unless the contract requires a local override.

### Authentication boundary

The NHA API is currently unauthenticated. `Http` accepts a token only for
interface compatibility and intentionally omits Discord's authorization
headers in `queueRequest()`.

Do not add the Discord bot token to NHA requests. If NHA authentication is
introduced, design an NHA-specific credential and header contract.

### Request queue

`Http::queueRequest()` must:

- reject with a Promise when no driver exists
- set the NHA user agent
- infer content headers when appropriate
- preserve caller-supplied headers
- construct `NHA\Http\Request`
- pass the request through inherited bucket/queue behavior
- return the deferred Promise

Do not bypass the queue with direct blocking HTTP calls.

## Endpoint binding

NHA routes are named constants in `src/NHA/Http/Endpoint.php`. Dynamic
segments use `:name` placeholders and inherited binding:

```php
$endpoint = Endpoint::bind(Endpoint::OBSERVE)
    ->bindAssoc(['agent_id' => $agent_id]);
```

Static routes still pass through `Endpoint::bind()` when an endpoint object is
required.

**Rule:** do not build dynamic route strings throughout callers. Use a named
constant and bind placeholders so route construction stays testable and
compatible with HTTP bucket identification.

### Adding an endpoint

1. add a descriptive constant to `NHA\Http\Endpoint`
2. use placeholders for dynamic segments
3. bind values in `NHA` or the owning transport method
4. keep base URL logic in `NHA\Http\Request::getUrl()`
5. add or update `tests/Http/EndpointTest.php`

Do not add NHA routes to dependency-owned `Discord\Http\Endpoint`.

## Request URL boundary

`NHA\Http\Request::getUrl()` is the only local place that combines
`Http::BASE_URL` with the endpoint path.

Keep route constants relative. Do not:

- embed the base URL in each endpoint
- concatenate full URLs in `NHA`
- send NHA routes through Discord's API base URL
- put query-string assembly in `Request::getUrl()` when `Endpoint` already
  supports query parameters

## ReactPHP promises

All NHA network operations return `React\Promise\PromiseInterface`.

### Promise-chain rules

- transform values with `then()` and return the resulting Promise
- keep cache updates in the successful chain that produced the value
- reject validation failures with a rejected Promise when the public method is
  Promise-based
- let callers attach user-facing error behavior without blocking
- never call synchronous wait/sleep loops on the event loop
- preserve concrete resolved-value contracts in docblocks and callbacks

`NHA::observe()` resolves `AgentObservation`; registration resolves an integer
agent id; command handlers generally resolve `MessageBuilder`.

### Error handling

`bot.php` installs ReactPHP rejection handling for fatal asynchronous errors and
adds local error callbacks for message/interaction responses. Infrastructure
methods should reject with meaningful exceptions rather than log-and-return
ambiguous values.

Do not create orphaned Promises for side effects without deciding where
rejection is observed.

## StateStore

`StateStore` is a tiny JSON-file-backed store for durable application
configuration.

Current behavior:

- load the file during construction when it exists
- expose `getDefaultAgent(): ?int`
- update `default_agent` through `setDefaultAgent()`
- create the parent directory on first save
- rewrite the JSON document with `JSON_PRETTY_PRINT`

**Rule:** keep this surface domain-named and small. Do not turn it into a
general cache, repository, or database facade.

### Expanding StateStore

Before adding a value:

1. confirm it must survive process restart
2. define its JSON shape and normalization
3. decide behavior for missing or malformed data
4. consider write failures and concurrent processes
5. add reload and overwrite tests

Volatile observations, active Promises, Discord parts, and voice session state
do not belong in JSON persistence.

## Companion surfaces

| Touching | Also inspect |
| --- | --- |
| `HelperTrait::createBuilder()` | `src/NHA/Parts/AgentObservation.php`, `src/NHA/Commands.php`, message safety |
| `HelperTrait::bar()` | observation rendering and `tests/HelperTraitTest.php` |
| `Endpoint` constants | callers in `src/NHA/NHA.php`, `tests/Http/EndpointTest.php` |
| `Request::getUrl()` | `Http::BASE_URL`, dependency `Discord\Http\Request` contract |
| `Http::queueRequest()` | `HttpTrait`, `HttpInterface`, driver behavior, Promise rejection |
| `StateStore` | `src/NHA/Commands.php`, relay setup in `bot.php`, state tests |
| Promise return types | every caller that transforms or reports the resolved value |

## Design tripwires

- enabling mentions by default for NHA-controlled content
- adding game/domain decisions to `HelperTrait`
- building endpoint URLs by hand outside `Endpoint`/`Request`
- adding NHA endpoints to dependency-owned Discord endpoint constants
- sending the Discord bot token to the unauthenticated NHA service
- bypassing `discord-php/http` queue and bucket contracts
- returning a non-Promise from an established asynchronous API
- mutating observation cache outside the successful request chain
- blocking the ReactPHP event loop
- storing volatile remote snapshots or runtime objects in `StateStore`
- swallowing file or network errors and returning misleading success values

## Reference files

- `src/NHA/HelperTrait.php` — safe builders and progress rendering
- `src/NHA/Http/Endpoint.php` — NHA endpoint templates
- `src/NHA/Http/Request.php` — NHA absolute URL construction
- `src/NHA/Http/Http.php` — HTTP contract adapter and request queue
- `src/NHA/StateStore.php` — JSON persistence
- `src/NHA/NHA.php` — infrastructure composition and Promise APIs
- `src/NHA/Commands.php` — Promise consumers and response builders
- `bot.php` — event loop, rejection handling, and runtime wiring
- `composer.json` — dependency contracts and validation commands

## Checklist before commit

- [ ] Mention prevention remains the default for shared builders
- [ ] Helpers remain deterministic and free of domain policy
- [ ] Endpoints use named constants and placeholder binding
- [ ] `Request::getUrl()` remains the NHA base-URL boundary
- [ ] `Http` still honors `discord-php/http` interfaces and queue behavior
- [ ] NHA requests do not leak the Discord bot token
- [ ] Public asynchronous methods return `PromiseInterface`
- [ ] Promise resolved-value and rejection contracts remain intentional
- [ ] `StateStore` contains only durable, JSON-safe application state
- [ ] Focused tests cover changed helper, endpoint, or state behavior
- [ ] Validate with `composer unit`, `composer cs`, and `composer pint` as
      appropriate

## Bottom line

Infrastructure in DiscordPHP-NHA should stay small and unsurprising:
`HelperTrait` handles safe presentation, `Endpoint`/`Request`/`Http` adapt
`discord-php/http` to NHA, ReactPHP Promises carry asynchronous results, and
`StateStore` persists only deliberate local configuration.
