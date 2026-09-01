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
| `Discord\Http\Drivers\Guzzle` | Dependency-provided asynchronous HTTP driver constructed by `NHA` for `nha_http` |

The local classes adapt `discord-php/http` contracts to the unauthenticated NHA
API. Do not duplicate the dependency's queue, bucket, Promise, or endpoint
machinery unless the contract requires a local override.

### The NHA HTTP driver must be `Guzzle`, not `React`

`NHA::__construct()` builds `nha_http` with `new Guzzle($this->loop, ...)`.
Discord's own gateway/HTTP client is unaffected and still uses `React`
internally — this is only about the second, NHA-specific HTTP client.

**Do not change this back to `Discord\Http\Drivers\React` without re-verifying
against the live host.** It was previously wired to `React`, and every request
to `https://nha.recluse.lol` hung forever: the request was queued
(`BUCKET ... queued REQ ...` logged) and then nothing — no success, no
rejection, no timeout — ever again. `curl` and the `Guzzle` driver both
complete the same request in under a second in the same process. The likely
cause is a mid-handshake TLS renegotiation the server performs (visible as
`schannel: remote party requests renegotiation` in `curl -v`) that PHP's
openssl-backed `react/socket` streams do not complete, while curl/schannel and
Guzzle's curl handler do.

If you ever suspect a hang against the live NHA host again, the fastest way to
confirm is a same-process A/B test: swap `$http`'s `driver` property (via
reflection in a throwaway script) between `new React(...)` and
`new Guzzle(...)` for the exact same request and compare. Do not assume a hang
is a network or DNS problem before ruling out the driver — `curl` succeeding
does not mean the ReactPHP driver will.

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

### Query strings belong on the `Endpoint`, never on `Http::get()`'s `$content`

`HttpTrait::get(string|Endpoint $url, $content = null, array $headers = [])`
treats a non-null `$content` as a request **body** (it gets JSON-encoded via
`guessContent()`), not a query string. Passing an options array as the second
argument to `->get()` silently sends it as a JSON body — the NHA API will
reject GET requests that need query parameters (e.g. `deposits?x=&y=`) with a
422, since the required fields never arrived as query params.

Use `Endpoint::addQuery(string $key, $value)` before calling `->get($endpoint)`:

```php
$endpoint = Endpoint::bind(Endpoint::DEPOSITS);
$endpoint->addQuery('x', $options['x']);
$endpoint->addQuery('y', $options['y']);

return $this->nha_http->get($endpoint)->then(...);
```

This was an actual bug in `DepositsRepository::getDeposits()` — fixed by
switching to `addQuery()`. Any new repository method with query parameters
must follow this pattern, not the `get($url, $arrayOfParams)` shape.

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

### 422 responses are our bug, not the server's

The NHA sandbox is designed to fail safe: invalid or currently-illegal
*gameplay* actions (e.g. an intent verb the game engine rejects) are simply
rejected/dropped per the async intent model — that is expected and must not
be treated as a transport error. A **422 Unprocessable Entity**, however,
means the request we sent does not match the API's schema (missing/invalid
field, wrong type, malformed query) — that is a bug in this codebase's request
construction, not a gameplay outcome.

`NHA\Http\Http::handleError()` overrides the inherited `HttpTrait::handleError()`
to special-case 422: it logs the response body at `error` level and returns
`NHA\Http\Exceptions\ValidationException` (extends `\DomainException`) instead
of the generic `RequestFailedException`. The exception message includes the
raw response body so the caller can see exactly which field/query was
rejected.

**Rule:** never add code that silently swallows or retries a 422. If a new
endpoint or verb starts returning 422s, treat it as a signal to fix the
request shape (check `/openapi.json`), not to catch-and-ignore the exception.

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
- switching `nha_http`'s driver back to `Discord\Http\Drivers\React` (hangs
  forever against the live NHA host — see "The NHA HTTP driver must be
  `Guzzle`" above)
- passing query parameters as `Http::get()`'s `$content` argument instead of
  `Endpoint::addQuery()`
- catching or retrying a 422/`ValidationException` instead of fixing the
  request shape
- using `isset($part->someMagicOrRepositoryProperty)` as a guard — DiscordPHP's
  `PartTrait` defines `__get` but never `__isset`, so `isset()` on any
  magic/repository property is **always false** in PHP regardless of whether
  it actually resolves via `__get`. Guard with a direct call/access (or a
  try/catch), never `isset()`, for Part-backed properties.

## Reference files

- `src/NHA/HelperTrait.php` — safe builders and progress rendering
- `src/NHA/Http/Endpoint.php` — NHA endpoint templates
- `src/NHA/Http/Request.php` — NHA absolute URL construction
- `src/NHA/Http/Http.php` — HTTP contract adapter, request queue, and 422 handling
- `src/NHA/Http/Exceptions/ValidationException.php` — thrown for 422 responses
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
- [ ] `nha_http` still uses the `Guzzle` driver, not `React`
- [ ] Query parameters are bound with `Endpoint::addQuery()`, never passed as
      `Http::get()`'s `$content` argument
- [ ] 422 responses still surface as `ValidationException` with the response
      body logged, not swallowed or retried
- [ ] No new `isset()` guard on a Part-backed magic/repository property
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
