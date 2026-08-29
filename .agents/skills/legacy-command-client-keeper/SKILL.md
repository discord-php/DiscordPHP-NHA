---
name: legacy-command-client-keeper
description: >-
  Maintain the prefix-command layer built on MessageCommandClient — NHA
  subcommands, aliases, parsing adapters, help metadata, and shared command
  dispatch. Use when touching message-based command handling.
---

# Skill: legacy-command-client-keeper

Use this skill when work touches:

- `src/NHA/NHA.php` as a `MessageCommandClient` subclass
- prefix-command registration in `bot.php`
- top-level aliases or NHA subcommands
- message reply behavior
- command metadata shown by the inherited help system

This is optional-prefix-command skill. Load it when maintaining the message-command layer built on top of DiscordPHP.

## Goal

Keep prefix-command behavior as a clean application layer:

- built on top of DiscordPHP's `MessageCommandClient`
- driven by Discord message events upstream
- declaring local command names, arguments, aliases, and help metadata in `bot.php`
- delegating actual NHA behavior to `Commands`
- separate from slash-command interaction mechanics

## Read in this order

1. `src/NHA/NHA.php`
2. Prefix-command section of `bot.php`
3. `src/NHA/Commands.php`
4. `src/NHA/StateStore.php`
5. `README.md`
6. Installed DiscordPHP `MessageCommandClient` and command object only if parsing/help behavior must be confirmed
7. Interaction section of `bot.php` only when checking public command parity

## Core contract

`NHA` extends `MessageCommandClient`, but local code does not replace the dependency's command architecture. It layers:

- a configured `!` prefix in the executable
- one top-level `nha` command
- subcommands registered on that command object
- standalone shortcuts for common actions
- callbacks that parse local arguments and call `Commands`
- response delivery through `Message::channel->sendMessage()`

The command layer should stay message-driven and thin.

## Main responsibilities by class

### DiscordPHP `MessageCommandClient` dependency

Owns:

- prefix detection
- quoted-argument parsing
- command and alias registries
- subcommand command objects
- cooldown/help machinery
- message event routing

### `NHA`

Owns:

- subclass identity
- NHA API and typed verb behavior
- local NHA HTTP client

It should not absorb individual bot command callbacks.

### `bot.php`

Owns:

- command names and metadata
- conversion from string args to local PHP values
- prefix response delivery and user-facing errors
- parity decisions with slash commands

### `Commands`

Owns:

- reusable NHA operations
- default-agent resolution
- Promise-returning `MessageBuilder` responses

If code does not clearly belong to one of these, re-check the boundary.

## Option rules

The executable passes command-client options including:

- `prefix`
- Discord token
- logger

Other command-client options such as aliases, case sensitivity, cooldown behavior, and rejected-Promise handling are upstream DiscordPHP capabilities. Configure them through supported parent options rather than creating local parallel state.

Do not treat command-client options as NHA API options.

## Parsing rules

Message-command flow:

1. DiscordPHP recognizes the prefix and command.
2. The registered callback receives `Message` and parsed argument array.
3. The local adapter casts or combines arguments for one `Commands` method.
4. `$replyToMessage` delivers the resolved builder or a safe error builder.

Local adapters currently handle:

- optional registration name/materials
- optional observation agent id
- raw verb plus joined JSON arguments
- movement deltas
- optional gather counts
- joined `say` and `tell` text
- read-only endpoint commands
- agent lookup id

If changing parsing, preserve the dependency's quoted-argument behavior. Do not replace parsed args with `explode(' ', $message->content)`.

## Help-system rules

The inherited default help behavior consumes local metadata:

- `description`
- `usage`
- `aliases`
- `showHelp`

Preserve:

- useful top-level `nha` description and usage
- per-subcommand descriptions
- usage strings matching actual parsing
- hidden standalone shortcuts where `showHelp` is false

If help behavior itself is wrong, confirm whether the fix belongs upstream in DiscordPHP rather than copying help rendering locally.

## Alias and subcommand rules

The primary command is `nha`. Common top-level aliases forward to the matching subcommand through:

```php
$nha_cmd->handle($message, array_merge([$alias], $args));
```

This preserves one prefix implementation for the operation.

Rules:

- register canonical behavior once
- forward aliases rather than duplicate callbacks
- keep subcommand names aligned with `Commands` methods where practical
- update slash parity deliberately, not automatically

## Boundaries with interaction commands

This layer is not the same as application commands.

Keep these separate:

- prefix commands use `MessageCommandClient` command registration and `Message`
- slash commands use `listenCommand()` and `Interaction`
- both call `Commands`

Shared business execution is current design. Shared parser, acknowledgement path, or help renderer is not.

## Error-handling rules

`$replyToMessage` attaches both fulfillment and rejection handlers.

Preserve:

- success sends the resolved builder
- rejection sends a safe builder with the error message
- callbacks do not block
- validation failures from `Commands` reach the reply adapter
- fatal/unhandled failures remain covered by the global rejection handler

Do not add silent catches around command Promises.

## Existing patterns worth copying

- top-level registration through `$nha->registerCommand()`
- subcommand registration on `$nha_cmd`
- loops for structurally identical commands such as mine/chop/gather
- alias forwarding through `$nha_cmd->handle()`
- one `$replyToMessage` delivery adapter
- shared semantics in `Commands`

## Dependency boundary

The command object implementation, prefix tokenizer, alias maps, cooldowns, help rendering, and message-event hookup live in DiscordPHP. Local code configures and consumes them. Changes to those internals are upstream-only work.

## Smells

Stop if you see:

- prefix parsing reimplemented from raw message content
- command callbacks performing NHA formatting already available in `Commands`
- alias callbacks duplicating canonical command behavior
- slash acknowledgement logic added to prefix callbacks
- `Message` passed into `Commands`
- command-client state stored in `AgentObservation` or `StateStore`
- local cooldown/help engine created around the upstream one
- raw string success responses replacing shared `MessageBuilder` responses
- silent Promise rejection handling

## Checklist before commit

- `NHA` still cleanly subclasses `MessageCommandClient`
- prefix and command name remain intentional
- canonical subcommand callback parses only its adapter concerns
- callback delegates to one `Commands` method
- aliases forward to canonical behavior
- descriptions and usage match actual arguments
- reply adapter handles fulfillment and rejection
- no blocking work occurs in callbacks
- no leakage into slash interaction acknowledgement or routing
- slash parity and README command list reviewed when public commands change
- upstream command-client internals remain a dependency boundary

## Bottom line

The prefix command client is a convenience adapter, not the NHA core and not the slash-command path. Keep it thin, metadata-rich, Promise-aware, and backed by the shared `Commands` service.
