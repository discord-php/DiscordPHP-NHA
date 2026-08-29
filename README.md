# DiscordPHP-NHA

A DiscordPHP extension + bot for the [NHA agent sandbox](https://nha.recluse.lol/?tab=Connect), modeled on
[DiscordPHP-MTG](https://github.com/discord-php/DiscordPHP-MTG).

## Layout

- `src/NHA/Http/` — `Endpoint`, `Http` and `Request` classes wired to `https://nha.recluse.lol` (unauthenticated),
  built the same way DiscordPHP itself talks to `discord.com` (see `discord-php/http`).
- `src/NHA/NHA.php` — the client. Extends `Discord\MessageCommandClient`, exposes `registerAgent()`, `observe()`,
  `intent()` and every read-only endpoint (`getWorld()`, `getMarket()`, `getRoster()`, ...).
- `src/NHA/VerbsTrait.php` — one typed convenience method per documented verb (`move`, `mine`, `attack`, `contract`, ...),
  all forwarding to `intent()`.
- `src/NHA/Parts/AgentObservation.php` — wraps a `GET /observe/:id` response and renders it as a Components V2
  `Container` (HP bar, position, inventory, threats, recent chat) with quick-action buttons.
- `src/NHA/Commands.php` — framework-agnostic handlers shared by chat commands, slash commands and buttons.
- `src/NHA/StateStore.php` — tiny JSON-backed store for the default agent id (`var/state.json`).
- `bot.php` — wires everything together:
  - **Chat commands** (`MessageCommandClient`): `!nha <register|observe|act|move|mine|chop|gather|say|tell|world|map|market|roster|rules|contracts|agent>`,
    plus standalone `!observe`, `!say`, `!act` aliases.
  - **Slash commands**: `/nha <same sub-commands>`, plus standalone `/observe` and `/say`.
  - **Components**: every observation renders with move/refresh buttons wired via `Button::setListener()`.
  - **Channel relay**: polls `/observe` for the default agent and posts new world chat/threats into `NHA_CHANNEL_ID`;
    plain messages posted in that channel are relayed into the world as `say` intents.

## Setup

```
composer install
cp .env.example .env   # fill in TOKEN and NHA_CHANNEL_ID
php bot.php
```

Run `!nha register <name> <metal> <credits>` (or `/nha register`) once to create and remember your default agent.
