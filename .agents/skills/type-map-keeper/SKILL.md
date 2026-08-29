---
name: type-map-keeper
description: >-
  Guard polymorphic NHA payload dispatch. DiscordPHP-NHA has no local subtype
  map today; use when adding discriminator-based payload families or reviewing
  code that might be mistaken for a type map.
---

# Skill: type-map-keeper

Use this skill when work proposes:

- a local `TYPES` map
- `TYPE_*` constants for NHA payload variants
- dispatch by `type`, `kind`, `event`, `component_type`, or another runtime
  discriminator in NHA responses
- multiple concrete wrappers for one NHA payload family
- duplicated class-selection `match`/`switch` logic

This is subtype-dispatch skill. DiscordPHP-NHA currently has no local subtype
map and no polymorphic NHA part family. Do not pretend existing command-routing
`match` expressions are subtype maps.

## Goal

If polymorphic NHA payloads are introduced, keep type dispatch as:

- one centralized map per payload family
- explicit discriminator constants
- one safe generic fallback
- shared materialization logic
- tests for known and unknown discriminator values

Until then, keep the code simple and do not create a map without concrete
subtypes to resolve.

## Read in this order

1. `src/NHA/Parts/AgentObservation.php` — current non-polymorphic payload
   wrapper
2. `src/NHA/NHA.php` — current materialization in `observe()`
3. `src/NHA/Commands.php` — command methods and read-only endpoint formatting
4. `bot.php` — command dispatch `match` expression and Discord event listener
5. `src/NHA/Http/Endpoint.php` — route constants, which are not type constants
6. `tests/Parts/AgentObservationTest.php`

## Core contract

There is no local `TYPES` constant today.

`AgentObservation` is one concrete, read-only wrapper around an observation
response. It uses accessor fallbacks such as `hp`/`health` and
`position`/`pos`; those are schema compatibility aliases, not subtype
discriminators.

The `match ($sub)` expression in `bot.php` routes slash subcommand names to
`Commands` methods. The verb methods in `VerbsTrait` route application actions.
Neither expression selects a PHP class from a polymorphic payload, so neither
is a subtype map.

A local type map becomes appropriate only when one NHA response family has a
documented discriminator and genuinely different concrete representations.

## How a future type map should work

### Map shape

Put the map on the root class for the polymorphic family:

```php
public const TYPES = [
    0 => self::class,
    self::TYPE_EXAMPLE => ExamplePayload::class,
];
```

Keys must be actual discriminator values emitted by the NHA API. Values must be
classes in the same payload family. Index `0`, or another clearly documented
sentinel, should resolve unknown or missing values to the generic root class.

### Dispatch expression

Centralize class lookup in one factory or root-class method:

```php
$type = $data['type'] ?? 0;
$class = Payload::TYPES[$type] ?? Payload::class;
```

All materialization sites should call that shared path. Do not repeat
`match`, `switch`, or `if` chains in commands, polling, and HTTP callbacks.

### Input normalization

Normalize decoded object/array payload shape before lookup. Do not let some
callers read `$data->type` while others read `$data['type']` and develop
different fallback behavior.

## Current non-map families

| Surface | What it does | Why it is not a subtype map |
| --- | --- | --- |
| `AgentObservation` accessors | Read compatible field names | No class selection |
| `bot.php` command `match` | Routes subcommand names to handlers | Dispatches behavior, not payload classes |
| `VerbsTrait` methods | Build intent verb/argument payloads | One transport operation per method |
| `Endpoint` constants | Name NHA routes | Route templates, not discriminator values |
| DiscordPHP `Event::MESSAGE_CREATE` | Names a dependency event | DiscordPHP owns its event type system |

Do not rename or restructure these surfaces merely to imitate upstream
DiscordPHP `TYPES` maps.

## Materialization sites

Today the only local typed observation materialization is:

- `NHA::observe()` constructing `AgentObservation`

If polymorphism is added, inspect every place a member of that family can be
created:

1. HTTP response callbacks in `NHA`
2. nested payload accessors in `AgentObservation` or successor root classes
3. periodic polling in `bot.php`
4. command response formatters
5. JSON reload code, if the new family is deliberately persisted

Those sites should consume one factory/map, not embed their own subtype
knowledge.

## Adding a new subtype family

Follow this sequence:

1. Confirm the NHA API supplies a stable discriminator.
2. Define the root payload class and its generic fallback behavior.
3. Add public `TYPE_*` constants matching documented discriminator values.
4. Add one centralized `TYPES` map on the root class.
5. Create concrete subtype classes with a shared root contract.
6. Add one materialization helper or factory that normalizes payload shape and
   reads the map.
7. Route all HTTP, nested-payload, command, and polling materialization through
   that helper.
8. Keep unknown future values usable through the root fallback.
9. Test known, missing, and unknown discriminator values.
10. Document whether serialization preserves the original discriminator.

Do not begin with a map and search for reasons to use it.

## Builder-side mirrors

DiscordPHP-NHA has no local inbound/outbound builder type-map pair.

If a future feature has both:

- inbound NHA payload wrappers, and
- outbound builders for the same discriminator space

decide whether they truly share classes and constants. If they map to different
class hierarchies, keep separate maps and add tests that ensure both recognize
the same supported discriminator values. Do not create a builder mirror when
there is no outbound polymorphic builder.

Discord component builders used by `AgentObservation::toContainer()` are
provided by `team-reflex/discord-php`; their maps remain dependency-owned.

## Fallback behavior

Unknown NHA variants should remain inspectable rather than crashing solely
because the server added a new discriminator.

A generic root fallback should:

- preserve the raw payload
- preserve the discriminator value
- avoid claiming subtype-specific fields
- serialize without data loss

Do not silently coerce an unknown payload into a known concrete subtype.

## Constants as public vocabulary

If local `TYPE_*` constants are introduced, they become public vocabulary for
consumers.

### Naming convention

- prefix with `TYPE_`
- use uppercase snake case
- define constants on the root family class
- match the NHA API value exactly

### Alias preservation

If the NHA API renames a discriminator and compatibility is required, preserve
the old public constant as a documented deprecated alias. Remove aliases only
with an intentional compatibility break.

Do not confuse endpoint constants such as `Endpoint::OBSERVE` with subtype
constants.

## Smells

Stop if you see:

- documentation claiming a local type map already exists
- a command-routing `match` described as payload subtype dispatch
- a `TYPES` map added before concrete polymorphic payload classes exist
- the same discriminator-to-class mapping repeated in multiple files
- subtype constants defined on unrelated command or endpoint classes
- no fallback for missing or future discriminator values
- unknown payloads losing their raw data
- DiscordPHP component or event type maps copied into `src/NHA`
- array/object payload shape handled differently at separate dispatch sites

## Checklist before commit

- [ ] A real NHA discriminator and polymorphic family justify the map
- [ ] `TYPE_*` constants live on the root family class
- [ ] One centralized `TYPES` map selects concrete classes
- [ ] All materialization sites use the shared factory/map
- [ ] Missing and unknown values use a safe generic fallback
- [ ] Raw payload and discriminator data are preserved
- [ ] Builder-side maps exist only if a distinct builder family exists
- [ ] Command routing and endpoint constants remain described accurately
- [ ] DiscordPHP-owned type maps remain in the dependency
- [ ] Tests cover known, missing, and unknown discriminator values

## Bottom line

DiscordPHP-NHA has no subtype map today. Keep it that way until the NHA API
introduces a real polymorphic payload family; then centralize class selection in
one tested map rather than scattering branches or mislabeling command dispatch
as type resolution.
