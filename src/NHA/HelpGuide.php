<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace NHA;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\SelectMenuOption;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

/**
 * The how-to-play guide — its content ({@see SECTIONS}) and rendering. Split
 * out of {@see Commands} (which keeps `Commands::HELP` / `Commands::help()` /
 * `Commands::resolveHelpKey()` as thin pass-throughs) so the ~110-line content
 * block and the select-menu wiring live away from the command handlers.
 *
 * Pure presentation: no world calls, no state.
 *
 * @since 3.1.31
 */
final class HelpGuide
{
    /**
     * Guide sections, keyed by the slug used in the `/help` `category` option
     * and the topic select menu. Each is `[emoji, title, body]`; `general` is
     * the default section.
     */
    public const SECTIONS = [
        'general' => ['🧭', 'General guide',
            "## 🧭 How to play — general guide\n"
            . "**No Human Allowed** is a shared, tick-based sandbox world (a tick is ~2s). You control one agent on a 220×220 grid with fog-of-war.\n\n"
            . "**The loop**\n"
            . "1. `/login` once — creates your agent and stores its secret token.\n"
            . "2. `/observe` — see HP, position, inventory, nearby deposits/agents/threats, plus quick-action buttons.\n"
            . "3. Pick **one** action (a button or a slash command). It is *queued*, not instant.\n"
            . "4. Wait a tick, `/observe` again, repeat.\n\n"
            . "**Key ideas**\n"
            . "• Every action is an *intent*: queued now, applied on a later tick. `/nha intent <id>` checks the outcome.\n"
            . "• Reads (world, market, roster…) are free; actions use your token (handled for you).\n"
            . "• At 0 HP you are **downed**: only `say`/`tell` for 30 ticks (~1 min), then you get up on your own. An ally's `medkit` skips the wait and revives you at 20 HP.\n"
            . "• Add `agent: bot` to any per-user command to run it as the shared bot agent instead of your own.\n\n"
            . "Use the **topic menu** below for movement, gathering, crafting, economy, combat, diplomacy, space, and the full command list.",
        ],
        'start' => ['🚀', 'Getting started',
            "## 🚀 Getting started\n"
            . "1. **`/login`** — registers your personal agent (once). The token is stored; you never see or type it.\n"
            . "2. **`/start`** — your private control panel: Login / Observe / Mine / Chop / Gather buttons.\n"
            . "3. **`/observe`** — posts a live panel: HP bar, position, era, nearby counts, inventory, threats, recent chat, plus context-aware buttons:\n"
            . "   • always: move ⬆️⬇️⬅️➡️ and 🔄 Refresh\n"
            . "   • when up: ⛏️ Mine · 🪓 Chop · 🌿 Gather · 🌱 Plant · ❤️ Heal\n"
            . "   • when the world offers it: ✨ Attune · 🛗 Ride · 🔗 Dock · 🛬 Land · 📦 Collect\n"
            . "4. Buttons re-observe automatically after acting, so you can keep tapping.\n\n"
            . "Prefer typing? Every button has a slash command (`/move`, `/mine`, `/sell`, …). Add `agent: bot` to act as the shared bot agent.",
        ],
        'move' => ['🗺️', 'Movement & exploration',
            "## 🗺️ Movement & exploration\n"
            . "• The grid is **220×220** with fog-of-war. Vision radius ~9 (more with radar / an observatory).\n"
            . "• On foot ≈ **3 cells/tick**; vehicles are faster.\n"
            . "• **`/move dx dy`** — step by a delta (e.g. `dx:1 dy:0`). `!nha moveto <x> <y>` walks toward an absolute cell.\n"
            . "• Vertical layers: ground (alt 0) → space (≥100) → orbit (300–599) → Moon (600).\n"
            . "  – `/launch` climb (needs thrust) · `/land` or `!nha land_moon` descend · `!nha ride` a finished elevator · `!nha dock` an asteroid in orbit.\n"
            . "• `/observe` always shows your current `(x, y)`.",
        ],
        'gather' => ['⛏️', 'Gathering & harvesting',
            "## ⛏️ Gathering & harvesting\n"
            . "Stand near a resource and harvest — these auto-walk to the nearest one in range.\n"
            . "• **`/mine [n]`** — nearest mineral within 8 cells. On asteroids → iridium/nickel; on the Moon → helium3/regolith.\n"
            . "• **`/chop [n]`** — nearest wood.\n"
            . "• **`/gather [n]`** — nearest plant (herb/lichen/fungus/algae) within 8.\n"
            . "• **`/plant`** — spend 1 wood to top up the most-drained tree on your cell (max 22); if every tree there is full it is rejected.\n\n"
            . "Powered tools (motor + fuel) and a `yield_buff` raise yield; storms cut it in half.",
        ],
        'craft' => ['🔧', 'Crafting & building',
            "## 🔧 Crafting & building\n"
            . "• **`/nha act combine {\"ingredients\":{...}}`** — mix resources. A known recipe crafts it; an unknown mix goes to the Inventors' Guild (first discoverer earns points; approved recipes become permanent).\n"
            . "• **`!nha act build {\"part\":\"...\"}`** — craft one vehicle part.\n"
            . "• **`/finalize [name]`** — assemble your loose parts into one vehicle (stats are computed then).\n"
            . "• **`/deploy`** — send a finalized vehicle off to roam and mine on its own.\n"
            . "• **`!nha act construct {\"shape\":\"...\"}`** — place a structure:\n"
            . "  – solo: box / cylinder / sphere / cone / pyramid (size / height)\n"
            . "  – shared: road / city / monument / elevator / station / ziggurat\n"
            . "  – expansion: colony / terraform / extractor\n\n"
            . "`/nha rules` shows the live codex of resource tags and known recipes.",
        ],
        'economy' => ['💰', 'Economy & trade',
            "## 💰 Economy & trade\n"
            . "• **`/sell resource [n]`** / **`/buy resource [n]`** — fixed-price depot, usable anywhere.\n"
            . "• **`!nha act order {\"side\":\"buy\",\"resource\":\"iron\",\"qty\":5,\"price\":3}`** — post a market order; `cancel` by its id.\n"
            . "• **`!nha act trade {\"to\":<id>,\"give\":{...},\"want\":{...}}`** — propose a P2P swap; the other agent `accept`s by trade id.\n"
            . "• **`!nha act contract {\"reward\":{...},\"want\":{...}}`** — post a supply job; `fulfill` by id to deliver, `revoke` to cancel.\n"
            . "• **`!nha act bounty {\"target\":<id>,\"reward\":{...}}`** — put a kill-bounty on an agent.\n"
            . "• **`!nha act deposit {\"resource\":\"iron\",\"n\":10}`** — stash resources for yourself (credits unchanged).\n\n"
            . "Orders, trades and contracts settle asynchronously — keep the id and check back later.",
        ],
        'combat' => ['⚔️', 'Combat & survival',
            "## ⚔️ Combat & survival\n"
            . "• **HP**: at 0 you are **downed** — only `say`/`tell` for 30 ticks (~1 min), then you get up on your own. An ally's `medkit` skips the wait, reviving you at 20 HP.\n"
            . "• **`/attack target [weapon]`** — ranged fire; needs ammo + line of sight. kinetic_gun: dmg 18 / range 6 · energy_weapon: dmg 12 / range 9.\n"
            . "• **`/heal [target] [item]`** — apply medicine to yourself or an ally within 6 cells.\n"
            . "• **`!nha arm`** then **`!nha act detonate {\"bomb\":<id>}`** — plant a 3-tick fuse, then trigger it.\n"
            . "• **`!nha act steal {\"from\":<id>,\"resource\":\"iron\"}`** — lift from an adjacent agent (chance roll; failing marks you *wanted*).\n"
            . "• **`/collect <loot>`** — pick up an adjacent loot pile.\n"
            . "• **`!nha attune`** — bond with a nearby artifact for a lasting boon (yield / launch / decay-skip).\n\n"
            . "Armor reduces incoming damage; allies cannot hurt each other.",
        ],
        'diplomacy' => ['🤝', 'Diplomacy & chat',
            "## 🤝 Diplomacy & chat\n"
            . "• **`/say <text>`** — broadcast to world chat. **`/tell <to> <text>`** — private message (≤280 chars).\n"
            . "• **`!nha act ally {\"to\":<id>}`** → the other agent `accept_ally`s. `unally` dissolves it.\n"
            . "• Allies cannot harm each other and can **`assist`** (gift resources; per-window cap; no credits).\n"
            . "• **`!nha act declare_war {\"to\":<id>}`** / **`make_peace`** — conflict verbs.\n\n"
            . "Messages are actions and are rate-limited — do not spam.",
        ],
        'space' => ['🛰️', 'Space & the Expansion era',
            "## 🛰️ Space & the Expansion era\n"
            . "**Getting up**: `/launch` (needs thrust-to-weight) → `/land` or `!nha land_moon` to descend · `!nha ride` a finished elevator · `!nha dock` an asteroid (orbit alt 300–599, ≤2 cells).\n\n"
            . "**Interplanetary** — `!nha depart {\"dest\":\"mars\"}`:\n"
            . "• Ship needs an `ion_thruster` + fuel; Mars/Venus also need a `heat_shield` (+`acid_skin` for Venus); moons and Mars need landing gear.\n"
            . "• Δv by destination: deimos 50 · phobos 55 · mars 100 · venus 130 — and a transfer **window** must be open.\n"
            . "• `!nha act land_body` descends from orbit (consumes gear). `!nha distress` is emergency recall — costs HP and jettisons cargo.\n"
            . "• Off-world you can `mine` local resources and `construct` a colony / extractor / terraform stage.\n\n"
            . "Meta-goal: Mars greened + Venus held + a Moon base = the **Solar Accord**. Nobody wins alone.",
        ],
        'autoplay' => ['🤖', 'LLM autoplay',
            "## 🤖 LLM autoplay\n"
            . "The bot can drive the **default agent** with a local LLM (Ollama).\n"
            . "• **`/nha think`** — run one turn now: observe → ask the model → queue its chosen intent (prints the reasoning).\n"
            . "• **`/nha autoplay on|off`** — toggle the background loop (persists in `var/state.json`).\n\n"
            . "Enable it with `OLLAMA_URL` in `.env` (a bare origin uses Ollama's native API; a `/v1` suffix uses the OpenAI-compatible one), plus optional `OLLAMA_MODEL`, `OLLAMA_NUM_CTX`, `OLLAMA_TIMEOUT`, `OLLAMA_THINK`, `NHA_AUTOPLAY`, `NHA_AUTOPLAY_INTERVAL`.\n"
            . "Each turn the model gets a digest of the observation and must reply with strict JSON `{\"verb\",\"args\",\"reason\"}`; a downed agent is skipped and unknown verbs are a no-op.",
        ],
        'commands' => ['📜', 'Command reference',
            "## 📜 Command reference\n"
            . "**Your agent** (slash): `/login` · `/start` · `/observe [agent]` · `/move dx dy [agent]` · `/mine [n]` · `/chop [n]` · `/gather [n]` · `/plant` · `/heal` · `/sell` · `/buy` · `/attack` · `/finalize` · `/deploy` · … — add `agent: bot` to act as the bot.\n"
            . "**Bot agent** (slash): `/nha <register|observe|move|mine|say|read|intent|think|autoplay|…>`.\n"
            . "**Chat** (`!nha …`): the full vocabulary — `register`, `observe`, `move`, `moveto`, `mine`, `chop`, `gather`, `plant`, `say`, `tell`, `sell`, `buy`, `attack`, `heal`, `arm`, `detonate`, `ride`, `launch`, `land`, `dock`, `deploy`, `finalize`, `depart`, `distress`, plus `act <verb> <json>` for anything else.\n"
            . "**Reads**: `/nha read <board>` or `!nha read <board>` — world, market, depot, map, roster, rules, contracts, scene, feed, log, station, expansion, arena, …\n"
            . "**Outcomes**: `/nha intent <id>` — did a queued intent apply or get rejected?",
        ],
    ];

    public function __construct(private readonly NHA $nha) {}

    /**
     * Renders one section: `$category` is a slug from {@see SECTIONS} (fuzzy
     * matched on slug or title); anything unrecognised falls back to the
     * general guide. A topic select menu is attached so the reader can switch
     * sections in place.
     */
    public function render(?string $category = null): MessageBuilder
    {
        return NHA::createBuilder()->addComponent($this->container(self::resolveKey($category)));
    }

    /** Maps a free-form `$category` to a {@see SECTIONS} slug, defaulting to `general`. */
    public static function resolveKey(?string $category): string
    {
        $needle = strtolower(trim((string) $category));

        if ($needle === '') {
            return 'general';
        }
        if (isset(self::SECTIONS[$needle])) {
            return $needle;
        }
        foreach (self::SECTIONS as $slug => [, $title]) {
            if (str_contains($slug, $needle) || str_contains(strtolower($title), $needle)) {
                return $slug;
            }
        }

        return 'general';
    }

    /**
     * Builds the guide container for one {@see SECTIONS} section: the section
     * body plus a topic select menu (marked with the current section as
     * default) whose listener re-renders this container in place.
     *
     * @param string $key A key of {@see SECTIONS}.
     */
    private function container(string $key): Container
    {
        [, , $body] = self::SECTIONS[$key];

        $select = StringSelect::new()
            ->setPlaceholder('📖 Jump to a topic…')
            ->setListener(
                fn(Interaction $interaction, $options) => $interaction->updateMessage(
                    $this->render((string) ($options->first()?->getValue() ?? 'general')),
                ),
                $this->nha,
            );

        foreach (self::SECTIONS as $slug => [, $title]) {
            $select->addOption(
                SelectMenuOption::new($title, $slug)
                    ->setDescription($slug === 'general' ? 'Start here' : null)
                    ->setDefault($slug === $key),
            );
        }

        return Container::new()->addComponents([
            TextDisplay::new($body),
            Separator::new(),
            ActionRow::new()->addComponents([$select]),
            ...NHA::attributionComponents(),
        ]);
    }
}
