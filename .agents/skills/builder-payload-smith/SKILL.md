---
name: builder-payload-smith
description: >-
  Maintain DiscordPHP builder usage in DiscordPHP-NHA — message responses,
  Components V2 observations, application command definitions, validation,
  and shared payload construction. Use when changing outbound Discord data.
---

# Skill: builder-payload-smith

Use this skill when work touches:

- `src/NHA/HelperTrait.php`
- response construction in `src/NHA/Commands.php`
- component rendering in `src/NHA/Parts/AgentObservation.php`
- application command builders or interaction responses in `bot.php`

This is outbound-shape skill. Load it when a change affects how local callers build data to send to Discord.

## Goal

Keep DiscordPHP builders as the repo's safe way to author outbound Discord payloads:

- `MessageBuilder` is the common response currency
- Components V2 output is composed from typed component builders
- application command definitions use `CommandBuilder` and typed `Option` Parts
- allowed mentions are prevented by default through one helper
- prefix, interaction, and button paths reuse the same built responses

## Read in this order

1. `src/NHA/HelperTrait.php`
2. `src/NHA/Commands.php`
3. `src/NHA/Parts/AgentObservation.php`
4. `bot.php`
5. Relevant tests under `tests/`
6. The public builder APIs in the installed DiscordPHP dependency when validation or serialization details matter

Do not edit or describe upstream DiscordPHP builder internals as locally owned.

## Core contract

DiscordPHP-NHA does not define its own builder hierarchy. It consumes DiscordPHP builders.

Local builder rules:

- start message responses with `NHA::createBuilder()` where practical
- default to `AllowedMentions::none()`
- return `MessageBuilder` from shared command behavior
- use `Container`, `TextDisplay`, `Separator`, `ActionRow`, and `Button` for Components V2
- use `CommandBuilder` for slash command definitions
- pass builders to DiscordPHP send/update/save APIs rather than pre-serializing them

If local code starts recreating builder serialization or validation, wrong layer.

## Base patterns to preserve

### `HelperTrait::createBuilder()`

This is the local message-builder factory:

```php
$builder = MessageBuilder::new();
$builder->setAllowedMentions(AllowedMentions::none());
```

Callers may opt out explicitly, but safe mention behavior is the default.

### Shared command response

`Commands` methods return `PromiseInterface` resolving to a `MessageBuilder`. This lets:

- prefix commands send the builder to a channel
- slash commands update the original response
- button callbacks update the existing message

Do not return different response shapes for each adapter.

### Components V2 container

Small text responses use:

```php
Container::new()->addComponents([
    TextDisplay::new($text),
]);
```

Rich observations add separators and one action row of buttons.

### Application command definition

`bot.php` builds `/nha` with `CommandBuilder`, `Command` type constants, and `Option` Parts created through the DiscordPHP factory. The builder is then created in the global command repository and saved.

This uses upstream builder/Part/repository APIs; local code owns only the command definition and dispatch mapping.

## Validation rules

Validation should happen before Discord receives the payload.

Local responsibilities include:

- JSON command arguments must decode to an array/object shape
- Text Display output must be truncated before its limit
- action rows must not exceed Discord component counts
- required slash options must match dispatch assumptions
- button callbacks must have the client context required by `setListener()`
- user/world text must not accidentally enable unwanted mentions

DiscordPHP builder-level limits and enum checks belong to the dependency. Use those public setters rather than duplicating their checks.

## Serialization rules

### Builders remain objects until the boundary

Pass builders to:

- `sendMessage()`
- `updateMessage()`
- `updateOriginalResponse()`
- builder `create()` helpers and repository `save()`

Do not call `jsonSerialize()` early unless a test specifically verifies shape.

### Omit unset optionals

When constructing command options, only add values that are intentionally supported. Do not add null placeholders to outbound definitions.

### Normalize arbitrary NHA data before display

`Commands::jsonMessage()` JSON-encodes read-only endpoint data, truncates it, and embeds it in a Text Display. Keep arbitrary NHA payloads out of raw Discord message arrays.

## Boundaries with local models and services

### Builder vs observation model

- `AgentObservation` owns NHA snapshot semantics
- DiscordPHP component builders own output shape

Rendering belongs on the observation because it is a view of that snapshot, but the observation remains a plain NHA model.

### Builder vs command service

- `Commands` owns reusable response content and asynchronous business flow
- `bot.php` owns delivery to `Message` or `Interaction`

Do not make `Commands` accept transport-specific Discord event objects.

### Builder vs DiscordPHP dependency

Message, command, modal, component, and builder serialization internals are upstream. Local changes should use supported public methods, not patch around them.

## Component-specific rules

The current observation UI uses Components V2:

- one `Container`
- one or more `TextDisplay`/`Separator` components
- one `ActionRow`
- four directional buttons plus one refresh button

Preserve:

- at most five buttons in the action row
- Promise-based listeners
- action then re-observe then update-message ordering
- the same `NHA` instance supplied to `Button::setListener()`

If a new local component type is needed, first confirm DiscordPHP already supplies the builder. Component type maps and inbound component Parts belong upstream.

## Builder selection guide

Use a DiscordPHP builder when:

- Discord payload has nested structure
- component or command limits matter
- the response is shared by multiple delivery paths
- the dependency already exposes a typed API

A local scalar or array is fine when:

- it is NHA intent arguments sent to the NHA API
- it is internal option-flattening data
- it is the raw observation payload

Do not confuse NHA request arrays with Discord outbound payload arrays.

## Existing patterns worth copying

### Safe message builder

`HelperTrait::createBuilder()` for allowed-mention defaults.

### Compact text response

`Commands::textContainer()` and `Commands::jsonMessage()`.

### Rich interactive response

`AgentObservation::toContainer()`.

### Slash command definition

The `$opt`, `$sub`, and `CommandBuilder` flow in `bot.php`.

### Delivery adapters

`$replyToMessage` and `$replyToInteraction` in `bot.php`.

## Smells

Stop if you see:

- raw Discord payload arrays where a builder exists
- `Commands` returning strings for one adapter and builders for another
- mention prevention bypassed accidentally
- NHA model state stored inside a Discord builder
- button count or Text Display limit ignored
- interaction response builder serialized before update
- complex payload validation deferred until after a Discord API call
- local reimplementation of DiscordPHP builder internals
- a component type-map change attempted in this repository

## Builder change checklist

- response still resolves to `MessageBuilder`
- allowed mentions remain intentionally configured
- validation occurs before send/update
- Text Display content stays within limits
- action rows and buttons stay within component limits
- Promise-based listeners return or chain their work
- slash option definitions match dispatch keys and types
- optional command fields are added only when set
- builders are passed as objects to DiscordPHP APIs
- tests cover invalid and valid boundaries where local logic exists
- README examples updated if preferred public usage changed

## Bottom line

Builders in this repo are a dependency-backed boundary that keeps Discord payload rules out of command and NHA transport code. If local callers have to memorize Discord payload trivia instead of composing builders, the design is drifting.
