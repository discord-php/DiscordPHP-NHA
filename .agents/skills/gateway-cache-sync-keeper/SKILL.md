---
name: gateway-cache-sync-keeper
description: >-
  Maintain DiscordPHP-NHA's Discord message relay, periodic NHA observation
  polling, and observation-cache coherence. Use when changing bot.php gateway
  listeners, relay behavior, or NHA::observe().
---

# Skill: gateway-cache-sync-keeper

Use this skill when work touches:

- the `Event::MESSAGE_CREATE` listener in `bot.php`
- the periodic observation timer in `bot.php`
- `src/NHA/NHA.php` observation caching
- `src/NHA/Parts/AgentObservation.php` relay-facing accessors

This is event-to-observation coherence skill. Discord gateway dispatch,
hydration, handler registration, and Discord entity repositories are owned by
the `team-reflex/discord-php` dependency, not by this repository.

## Goal

Keep the local relay as a small synchronization layer:

- Discord messages in the configured channel become NHA `say` intents
- periodic NHA observations become Discord relay messages
- every successful observation refreshes `NHA`'s local snapshot cache
- bot messages, commands, and unrelated channels do not loop into the world
- asynchronous failures remain visible and do not corrupt local state

## Read in this order

1. `bot.php` — channel relay block and error handling
2. `src/NHA/NHA.php` — `observe()`, `intent()`, and cached observations
3. `src/NHA/Parts/AgentObservation.php` — messages, threats, and rendering
4. `src/NHA/StateStore.php` — default-agent lookup used by both relay paths
5. `src/NHA/VerbsTrait.php` — `say()` forwarding
6. `src/NHA/Commands.php` — explicit observation and action paths

## Core contract

There are two directions of synchronization:

### NHA to Discord

`Loop::get()->addPeriodicTimer()` reads the default agent, calls
`NHA::observe()`, compares relay-visible content with process-local cursor
state, and posts an observation container when there is something to surface.

Because `NHA::observe()` replaces `NHA::$agents[$agent_id]` on success, the poll
loop and explicit observation commands share one last-known snapshot cache.

### Discord to NHA

The `Event::MESSAGE_CREATE` listener accepts plain user messages only from the
configured relay channel. It ignores bot-authored messages and command-prefixed
messages, resolves the default agent from `StateStore`, and sends the text as an
NHA `say` intent.

This listener is application wiring. It is not a custom gateway event class and
must not duplicate DiscordPHP's gateway internals.

## Three surfaces usually move together

When changing relay behavior, inspect all three:

1. `bot.php` — timer/listener filters and side effects
2. `src/NHA/NHA.php` — request and observation-cache semantics
3. `src/NHA/Parts/AgentObservation.php` — interpretation and presentation of
   observed messages or threats

If one changes and the others do not, make sure that is intentional.

## Dependency-owned gateway behavior

Use `Discord\WebSockets\Event::MESSAGE_CREATE` and the listener APIs exposed by
DiscordPHP. Do not add local copies of:

- `Discord\WebSockets\Event`
- `Discord\WebSockets\Handlers`
- `Discord\WebSockets\Events\MessageCreate`
- Discord channel, message, user, or guild repositories

If the defect is in DiscordPHP payload hydration, event registration, or
Discord entity cache mutation, fix or upgrade `team-reflex/discord-php`.
DiscordPHP-NHA should only own the NHA-specific relay decision.

## Hydration rules

### Prefer dependency-provided Discord parts

The message listener receives a `Discord\Parts\Channel\Message`. Use that typed
part. Do not rehydrate the gateway payload into a local message class.

### Prefer `AgentObservation` for NHA snapshots

`NHA::observe()` converts the decoded NHA response into
`AgentObservation` before caching or returning it. Do not pass raw observation
payloads through relay code when the wrapper already defines the consumed
accessors.

### Successful cache replacement

The observation cache changes only after the GET resolves and the snapshot is
constructed. Rejections must not erase or partially overwrite the previous
snapshot.

## Coherence rules by path

### Periodic polling

Preserve these decisions:

- no request when no default agent exists
- one observation request per timer tick
- observation cache updated by `NHA::observe()`
- relay cursor updated consistently with the messages just examined
- no Discord send when neither new messages nor threats need surfacing
- send through the configured channel only

If polling can overlap, reason about out-of-order resolutions before adding
more mutable cursor state. A slower older request must not make relay state move
backward.

### Message-create relay

Preserve these filters:

- exact configured channel
- human author only
- plain messages only, not command-prefixed messages
- default agent must exist

If attachments, replies, mentions, or message edits become relay inputs, define
their conversion and loop-prevention rules explicitly.

### Explicit observations and component refresh

Chat commands, slash commands, and component callbacks also call
`NHA::observe()`. They must receive the same typed snapshot and update the same
cache as periodic polling.

## Event return-shape rules

The local `Event::MESSAGE_CREATE` listener is side-effect-only and returns
`void`. Keep DiscordPHP's event listener contract intact.

`NHA::observe()` resolves with `AgentObservation`; callers use that value to
render or inspect the same snapshot written to the cache. Do not change it to a
boolean or raw transport response without updating every consumer and the
public API deliberately.

## Cache path playbook

When changing relay or polling behavior, answer:

1. What is the primary input: Discord `Message` or NHA observation?
2. Which side effect should occur: `say` intent, Discord send, or cache update?
3. Which agent id owns the observation?
4. Is the data fresh, cached, or process-local cursor state?
5. What happens if the Promise rejects?
6. Can timer ticks overlap or resolve out of order?
7. What prevents a Discord-to-NHA-to-Discord feedback loop?

If you cannot answer each, the relay path is not fully understood yet.

## Registration playbook

When adding a new local Discord gateway listener:

1. use a constant from `Discord\WebSockets\Event`
2. accept dependency-provided typed parts
3. filter to the smallest relevant channel/user/message scope
4. keep NHA-specific behavior in local code
5. attach Promise rejection handling for asynchronous side effects
6. verify the listener cannot echo its own output indefinitely
7. avoid changing dependency-owned handler registration

If DiscordPHP does not expose the event correctly, address that upstream rather
than creating a parallel local event family.

## Partial payload and intent rules

Discord message content availability depends on DiscordPHP configuration and
gateway intents. Do not assume a local listener can repair missing gateway
fields.

NHA observations may also evolve or omit fields. Use
`AgentObservation` accessors and their documented fallbacks rather than
overwriting a richer cached snapshot with hand-built partial data.

## Performance rules

- keep the timer Promise-based; never block the ReactPHP event loop
- do not add a REST fetch for data already present on the Discord `Message`
- avoid broad Discord cache scans in the relay listener
- keep polling interval configuration in `NHA_POLL_INTERVAL`
- prevent uncontrolled overlapping polls if request duration can exceed the
  interval
- send only when relay-visible state changed or requires attention

## Smells

Stop if you see:

- a local custom gateway event or handler family shadowing DiscordPHP
- raw Discord gateway payloads used instead of the provided `Message`
- raw NHA observation objects cached instead of `AgentObservation`
- a message listener without channel, bot, or command-loop filters
- an unobserved rejected Promise from `observe()`, `say()`, or `sendMessage()`
- cache state updated before an observation request succeeds
- poll cursor logic shared across multiple agent ids without an explicit key
- overlapping polls able to publish stale results after newer ones
- dependency-owned Discord repositories mutated directly from local relay code

## Checklist before commit

- [ ] `Event::MESSAGE_CREATE` remains dependency-provided
- [ ] Relay channel, bot-author, and command-prefix filters are preserved
- [ ] The default agent is resolved consistently from `StateStore`
- [ ] Periodic polling still calls `NHA::observe()`
- [ ] Successful observations update the per-agent cache exactly once
- [ ] Explicit observations and component refresh use the same cache path
- [ ] Promise rejections are handled or reach the configured rejection handler
- [ ] Timer overlap and cursor semantics are intentional
- [ ] Feedback-loop prevention still works
- [ ] No DiscordPHP gateway internals were copied locally

## Bottom line

DiscordPHP owns the gateway; DiscordPHP-NHA owns the relay. Keep
`Event::MESSAGE_CREATE`, periodic NHA polling, and `NHA::observe()` aligned so
messages flow once, successful snapshots stay coherent, and dependency
internals remain dependency-owned.
