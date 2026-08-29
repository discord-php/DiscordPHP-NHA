---
name: voice-subsystem-keeper
description: >-
  Guard DiscordPHP-NHA's voice dependency boundary. Discord voice transport,
  encryption, packets, and audio streaming belong to team-reflex/discord-php;
  use when voice work is proposed or NHA-specific voice behavior is introduced.
---

# Skill: voice-subsystem-keeper

Use this skill when work mentions Discord voice channels, audio streaming,
voice gateway protocol, encryption, packets, codecs, FFmpeg, Opus, UDP, or
voice-related behavior for an NHA agent.

DiscordPHP-NHA has no local voice subsystem. That absence is intentional.

## Architecture overview

Voice responsibilities are split by ownership. Understand the boundary before
touching any of them:

| Location | What lives here |
| --- | --- |
| `team-reflex/discord-php` dependency | Discord gateway integration, voice protocol integration, and the public Discord client surface |
| DiscordPHP's voice dependencies | Audio transport, stream management, codecs, and related platform tooling selected by DiscordPHP |
| DiscordPHP-NHA | NHA HTTP calls, agent observations/intents, Discord commands, components, and relay policy |

**Rule:** Discord voice mechanics do not belong in this repository. Direct
transport, crypto, packet, codec, and stream changes to
`team-reflex/discord-php` or its owning dependency.

## Read in this order

1. `composer.json` — confirms the `team-reflex/discord-php` dependency
2. `src/NHA/NHA.php` — the local client boundary layered on DiscordPHP
3. `src/NHA/Http/Http.php` — NHA HTTP transport, not voice transport
4. `src/NHA/Parts/AgentObservation.php` — current NHA presentation behavior
5. `bot.php` — local Discord wiring and relay policy
6. the installed `team-reflex/discord-php` version's voice documentation and
   source, if actual Discord voice work is required

Do not assume upstream voice paths, classes, or APIs from a different
DiscordPHP revision. Inspect the installed dependency before proposing a call.

## Core concepts

### Discord voice transport is dependency-owned

The separate voice gateway, UDP transport, RTP packets, encryption mode
negotiation, heartbeat behavior, Opus handling, and audio streams are
DiscordPHP concerns. Do not copy those classes into the local `NHA` namespace.

### NHA behavior is application-owned

A deliberately introduced NHA-specific voice feature may own semantics such as:

- which NHA agent is represented in a voice channel
- when an NHA observation or message should trigger audio
- how recognized speech becomes an NHA `say`, `tell`, or other intent
- configuration linking an NHA agent to a Discord guild/channel

Those are orchestration and policy. They must still call dependency APIs for
all Discord voice mechanics.

### NHA HTTP is not an audio transport

`src/NHA/Http/Http.php` implements NHA world requests through
`discord-php/http` contracts. Do not push audio frames, UDP behavior, or voice
gateway state through this client unless the NHA API itself adds a documented
HTTP endpoint with an application-level payload.

### Async behavior is mandatory

DiscordPHP-NHA runs on ReactPHP. Any local voice orchestration must remain
non-blocking and Promise/event-driven. Never run long blocking codec, process,
or stream operations on the event loop.

## Dependency boundary

### Change `team-reflex/discord-php` when

- joining or leaving voice channels is broken
- voice state/server gateway wiring is wrong
- encryption modes or packet formats need updates
- audio send/receive streams need transport changes
- Opus, FFmpeg, sodium, UDP, or codec detection needs changes

### Change DiscordPHP-NHA when

- an NHA-specific policy decides when to use an existing DiscordPHP voice API
- an NHA endpoint or intent carries application-level voice metadata
- commands/configuration connect an NHA agent to voice behavior
- observation data is transformed into text or audio content without
  reimplementing the voice stack

If the change can be stated without mentioning NHA agents, observations,
intents, or relay policy, it almost certainly belongs upstream.

## Companion surfaces

When deliberately adding NHA-specific voice behavior, inspect:

| Touching | Also inspect |
| --- | --- |
| agent selection | `src/NHA/StateStore.php`, `src/NHA/Commands.php` |
| NHA speech intent | `src/NHA/VerbsTrait.php`, `src/NHA/NHA.php` |
| observation-triggered output | `src/NHA/Parts/AgentObservation.php`, polling in `bot.php` |
| Discord voice API calls | installed `team-reflex/discord-php` docs/source and version constraints in `composer.json` |
| external processes or streams | ReactPHP non-blocking behavior and dependency-provided abstractions |

Do not add a local `NHA\Voice` namespace merely to mirror DiscordPHP.

## Playbook: fixing Discord voice mechanics

1. Reproduce against the installed `team-reflex/discord-php` version.
2. Identify whether the defect is in DiscordPHP or one of its voice
   dependencies.
3. Make the transport/protocol fix in the owning upstream repository.
4. Add upstream tests for the handshake, packet, stream, or error behavior.
5. Update DiscordPHP-NHA's dependency constraint only if the fix requires a new
   release or commit.
6. Keep local changes limited to compatibility wiring, if any.

Do not land a shadow implementation in DiscordPHP-NHA as a shortcut.

## Playbook: adding deliberate NHA-specific voice behavior

1. Write down the NHA-specific use case and why text relay is insufficient.
2. Confirm the installed DiscordPHP dependency exposes the required voice API.
3. Keep agent selection and durable mapping explicit.
4. Reuse `NHA`/`VerbsTrait` for NHA requests and intents.
5. Reuse DiscordPHP for joining, leaving, sending, receiving, and lifecycle
   handling.
6. Keep conversion/stream work asynchronous and bounded.
7. Surface missing optional dependencies clearly; do not silently degrade.
8. Add tests around local policy without mocking an entire voice protocol.
9. Document operational requirements such as codecs or binaries.

## Playbook: adding an NHA voice endpoint

Only follow this if the NHA API itself documents such an endpoint:

1. add a named route to `src/NHA/Http/Endpoint.php`
2. add a Promise-based method to the appropriate NHA client surface
3. keep request construction in `src/NHA/Http/Http.php` contracts
4. model application payloads without importing Discord RTP/crypto concepts
5. add focused endpoint and client tests

An NHA endpoint does not make Discord voice transport local.

## Design tripwires

- adding voice gateway opcodes, encryption, RTP packets, UDP, Opus, or FFmpeg
  implementations under the local `NHA` namespace
- creating local copies of DiscordPHP voice classes
- assuming a voice API without checking the installed dependency revision
- putting audio bytes through `NHA\Http\Http` without a documented NHA API
- blocking the ReactPHP event loop with process or stream I/O
- swallowing missing-codec, missing-sodium, or transport failures
- persisting volatile voice session secrets or transport state in
  `StateStore`
- mixing NHA agent policy with reusable Discord voice mechanics

## Checklist before commit

- [ ] The change is explicitly NHA-specific; otherwise it is directed upstream
- [ ] No Discord voice transport, crypto, packet, or codec code was copied
- [ ] The installed `team-reflex/discord-php` API was verified
- [ ] Local code uses dependency APIs rather than shadowing them
- [ ] ReactPHP remains non-blocking and Promise/event-driven
- [ ] Agent/channel mapping ownership and persistence are explicit
- [ ] Optional runtime dependencies fail clearly
- [ ] NHA HTTP endpoints remain application-level
- [ ] Tests cover local orchestration and policy
- [ ] Upstream dependency changes are versioned or documented when required

## Reference files

- `composer.json` — DiscordPHP dependency and supported commands
- `src/NHA/NHA.php` — NHA client and DiscordPHP integration point
- `src/NHA/Http/Http.php` — NHA HTTP transport boundary
- `src/NHA/Http/Endpoint.php` — NHA HTTP route templates
- `src/NHA/StateStore.php` — small durable application configuration
- `src/NHA/VerbsTrait.php` — NHA action methods, including speech intents
- `bot.php` — local Discord event and relay wiring

## Bottom line

DiscordPHP-NHA does not own a voice subsystem. Fix Discord voice mechanics in
`team-reflex/discord-php` or its owning voice dependency; add local code only
when the behavior is deliberately about NHA agents, and keep that code as thin
asynchronous orchestration over the dependency.
