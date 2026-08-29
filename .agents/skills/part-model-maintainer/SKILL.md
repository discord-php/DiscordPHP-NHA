---
name: part-model-maintainer
description: >-
  Maintain the AgentObservation model — read-only NHA payload access,
  fallback fields, serialization, and Components V2 rendering. Use when
  changing src/NHA/Parts, while preserving that it is not a Discord Part.
---

# Skill: part-model-maintainer

Use this skill when work touches `src/NHA/Parts/AgentObservation.php`.

This is not a Discord Part syntax guard. This is the NHA observation-model guard. Load it when changing what one observation exposes, how raw NHA payload fields are interpreted, or how the observation renders into Discord.

## Goal

Keep `AgentObservation` as the canonical local, read-only view of one `GET /observe/:id` response:

- raw NHA-shaped data remains available unchanged
- typed convenience access comes through focused methods
- payload variants are handled through explicit fallback keys
- Discord rendering uses builders without turning the model into a Discord resource
- quick actions remain Promise-based and refresh through `NHA::observe()`

## Read in this order

1. `src/NHA/Parts/AgentObservation.php`
2. `src/NHA/NHA.php` — `observe()` construction and cache ownership
3. `src/NHA/Commands.php` — observation response usage
4. `bot.php` — polling and interaction/button delivery
5. `tests/Parts/AgentObservationTest.php`
6. `src/NHA/HelperTrait.php` — shared builder and bar helpers

Do not start from DiscordPHP's `Part` base classes. This local class is intentionally not in that hierarchy.

## Core contract

`AgentObservation`:

- is a plain PHP class implementing `JsonSerializable`
- has readonly `agentId`
- stores the original decoded body in readonly `raw`
- is constructed with `(int $agentId, array $raw)`
- reads nested data through `get(string $path, $default = null)`
- exposes small convenience accessors for known observation concepts
- serializes back to the original raw array
- can render itself as a DiscordPHP Components V2 `Container`

It does **not**:

- extend `Discord\Parts\Part`
- use `$fillable`, `$attributes`, `$repositories`, or `$created`
- use the DiscordPHP factory
- save, fetch, or delete itself
- represent a Discord API entity

If a change introduces one of those assumptions, stop and re-check layer ownership.

## Meaning of common properties

### `agentId`

The integer identity supplied by the caller to `NHA::observe()`. It is used for display, cache keys, and button actions. It is not inferred from a possibly inconsistent response body.

### `raw`

The complete decoded NHA response as an array. Preserve it unchanged so callers can access fields not yet covered by convenience methods and `jsonSerialize()` remains lossless.

Do not replace raw payload keys with normalized aliases in storage. Normalization belongs in accessors.

## Core methods and what they mean

### `get(string $path, $default = null)`

Reads a possibly nested, dot-separated path. It supports arrays and object values encountered along the path and returns the supplied default when a segment is absent.

Keep this method generic and side-effect free. Do not add transport, caching, or Discord lookup behavior.

### Convenience accessors

Current accessors encode real payload compatibility:

- HP: `hp` then `health`
- max HP: `max_hp` then `hp_max`, default `100`
- position: `position` then `pos`
- vision: `vision` then `sight_radius`
- threats: `threats` then `threat_alerts`
- nearby agents: `nearby.agents` then `agents`

Array-valued accessors return empty arrays when absent. Preserve documented fallback order when extending them.

### `toContainer(NHA $nha)`

Produces a compact Components V2 summary:

- agent id
- HP bar
- position and optional vision
- inventory
- threat and bounty counts
- recent messages
- one action row with move and refresh buttons

Listeners queue NHA actions, re-observe, then update the original interaction message. This is rendering plus UI behavior, not persistence.

### `jsonSerialize()`

Returns `raw` exactly. Do not serialize the rendered container, computed fallbacks, or cached Discord objects.

## Helper methods to prefer

### `get()`

Use it for all raw payload traversal so fallback/default behavior remains consistent.

### `HelperTrait::bar()`

Use the shared text bar for HP rendering rather than duplicating bar math.

### DiscordPHP component builders

Use:

- `Container`
- `TextDisplay`
- `Separator`
- `ActionRow`
- `Button`

These are dependency builders. Follow their public APIs; do not duplicate their serialized array shape locally.

## Observation design patterns already used in repo

### Raw key plus compatibility fallback

Expose one semantic method while retaining all raw payload data:

```php
public function getPosition(): ?array
{
    return $this->get('position') ?? $this->get('pos');
}
```

Add a fallback only when the NHA API actually emits both shapes.

### Empty collection defaults

Inventory, messages, threats, contracts, bounties, and nearby agents should be easy to iterate. Return `[]`, not `null`, when absent.

### Read-only model, mutable remote world

An observation instance is a snapshot. A button action does not mutate it in place; it queues an intent and calls `NHA::observe()` for a fresh snapshot.

### Rendering stays bounded

Use only the most useful fields and a small number of recent messages. Keep within Discord component limits: at most five buttons in the current action row and no oversized Text Display content.

## Model change playbook

When adding or changing an observation concept:

1. Confirm the actual NHA payload key and type.
2. Preserve `raw` unchanged.
3. Add a focused accessor with explicit fallback/default behavior.
4. Decide whether the concept belongs in the compact Discord rendering.
5. If rendered, keep text and component limits in mind.
6. Add unit tests for primary key, fallback key, and missing value where meaningful.
7. Inspect polling and button flows for assumptions about the accessor.

## Nested data playbook

When new observation data is nested:

1. Prefer `get('parent.child')`.
2. Return an array or scalar matching the NHA domain.
3. Do not create a Discord Part for NHA world objects.
4. Add a dedicated local value object only if behavior and invariants justify it.
5. Keep `jsonSerialize()` anchored to the raw response.

## Rendering playbook

When changing `toContainer()`:

1. Build with DiscordPHP Components V2 classes.
2. Keep the content compact.
3. Escape or constrain untrusted world text where Discord formatting or mentions matter.
4. Keep one action row at no more than five buttons.
5. Return the builder object, not a serialized array.
6. Keep button callbacks Promise-based.
7. Re-observe before updating the message after actions.
8. Test semantic output where unit-safe.

## Dependency boundary

DiscordPHP Part mechanics, repositories, type maps, gateway hydration, and component builder validation live upstream. `AgentObservation` may consume builders but must not pretend to participate in those Discord resource systems.

## Smells

Stop if you see:

- `AgentObservation extends Part`
- `$fillable`, `$repositories`, `save()`, or factory hydration added
- raw payload rewritten into normalized storage
- an accessor performing HTTP or Discord cache work
- missing array fields returning inconsistent non-iterable values
- button actions mutating the old snapshot instead of re-observing
- more than five buttons in the action row
- raw component arrays replacing DiscordPHP builders
- `jsonSerialize()` returning computed or Discord-specific data instead of `raw`

## Checklist before commit

- class remains a plain read-only NHA model
- `agentId` and `raw` remain readonly
- raw response remains lossless
- new accessor has intentional type, fallback, and default behavior
- collection-like accessors return arrays
- `jsonSerialize()` still returns the raw payload
- rendering uses DiscordPHP builders
- component/text limits remain respected
- button listeners return Promise-driven work and refresh through `observe()`
- `NHA::observe()` cache behavior remains coherent
- unit tests cover the changed semantics

## Bottom line

`AgentObservation` is not a Discord Part. It is a read-only NHA snapshot with predictable accessors and a Discord builder view. Keep the world model plain, lossless, and separate from Discord resource persistence.
