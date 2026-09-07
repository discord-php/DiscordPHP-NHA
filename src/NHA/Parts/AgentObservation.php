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

namespace NHA\Parts;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use NHA\HelperTrait;
use NHA\NHA;
use JsonSerializable;

/**
 * A lightweight, read-only wrapper around a single `GET /observe/:id`
 * response (the `ObserveOut` schema). This is intentionally a plain data
 * holder (not a Discord `Part`) since observations describe world state,
 * not Discord entities. Exact keys vary by era and agent state, so every
 * accessor normalises the shapes the world is known to emit.
 *
 * @link https://nha.recluse.lol/docs#/agent/observe_ep_observe__agent_id__get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/ObserveOut
 *
 * @since 3.0.0
 */
class AgentObservation implements JsonSerializable
{
    use HelperTrait;

    /** Raw, decoded JSON body as returned by the world. */
    public readonly array $raw;

    /**
     * @param int   $agentId The agent this observation belongs to.
     * @param array $raw     The decoded `GET /observe/:id` body, kept verbatim.
     */
    public function __construct(public readonly int $agentId, array $raw)
    {
        $this->raw = $raw;
    }

    /**
     * Reads a (possibly nested, dot-separated) key from the raw payload.
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $value = $this->raw;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } else {
                return $default;
            }
        }

        return $value;
    }

    /** Current HP (`hp`, falling back to `health`), or null when absent. */
    public function getHp(): ?float
    {
        return $this->get('hp') ?? $this->get('health');
    }

    /** Maximum HP (`max_hp`/`hp_max`), defaulting to 100. */
    public function getMaxHp(): float
    {
        return (float) ($this->get('max_hp') ?? $this->get('hp_max') ?? 100);
    }

    /**
     * The agent's world position, always normalised to `['x' => int, 'y' => int]`
     * (or `null` when the payload carries none).
     *
     * `GET /observe/:id` returns `position` as a positional `[x, y]` pair; older
     * shapes used a `{x, y}` object under `position`/`pos`, and some payloads put
     * flat `x`/`y` scalars at the top level. All three are accepted here so
     * callers never have to care which one the world sent.
     */
    public function getPosition(): ?array
    {
        $pos = $this->get('position') ?? $this->get('pos');

        if (is_object($pos)) {
            $pos = (array) $pos;
        }

        if (is_array($pos)) {
            if (isset($pos['x'], $pos['y'])) {
                return ['x' => (int) $pos['x'], 'y' => (int) $pos['y']];
            }
            if (array_key_exists(0, $pos) && array_key_exists(1, $pos)) {
                return ['x' => (int) $pos[0], 'y' => (int) $pos[1]];
            }
        }

        $x = $this->get('x');
        $y = $this->get('y');
        if (is_numeric($x) && is_numeric($y)) {
            return ['x' => (int) $x, 'y' => (int) $y];
        }

        return null;
    }

    /** The agent's inventory as a `{resource: qty}` map (empty when absent). */
    public function getInventory(): array
    {
        return (array) ($this->get('inventory') ?? []);
    }

    /** The agent's vision radius (`vision`/`sight_radius`), or null when absent. */
    public function getVision(): mixed
    {
        return $this->get('vision') ?? $this->get('sight_radius');
    }

    /** Recent world/chat messages visible to the agent (empty when absent). */
    public function getMessages(): array
    {
        return (array) ($this->get('messages') ?? []);
    }

    /** Recent alerts/threats against the agent (`alerts`/`threats`/`threat_alerts`). */
    public function getThreats(): array
    {
        return (array) ($this->get('alerts') ?? $this->get('threats') ?? $this->get('threat_alerts') ?? []);
    }

    /** Supply contracts visible to the agent (empty when absent). */
    public function getContracts(): array
    {
        return (array) ($this->get('contracts') ?? []);
    }

    /** Bounty offers visible to the agent (empty when absent). */
    public function getBounties(): array
    {
        return (array) ($this->get('bounties') ?? []);
    }

    /** Other agents near this one (`nearby_agents`, falling back to `nearby.agents`/`agents`). */
    public function getNearbyAgents(): array
    {
        return (array) ($this->get('nearby_agents') ?? $this->get('nearby.agents') ?? $this->get('agents') ?? []);
    }

    /** True when the agent is downed (0 HP) at the tick this observation reflects. */
    public function isDowned(): bool
    {
        $downedUntil = (int) ($this->get('downed_until') ?? 0);
        $tick = (int) ($this->get('tick') ?? 0);

        return $downedUntil > $tick || ($this->getHp() !== null && $this->getHp() <= 0);
    }

    /** Count of a nearby-list key (`nearby_deposits`, `loot`, `artifacts`, …). */
    private function count(string $key): int
    {
        return count((array) ($this->get($key) ?? []));
    }

    /**
     * Builds a Components V2 container summarising this observation, with
     * context-aware quick-action buttons wired via `Button::setListener()` so
     * they work from any entry point (chat command, slash command, button).
     *
     * Row 1 is always movement + refresh. Row 2 is the harvest/interact loop
     * (mine/chop/gather/plant/heal). Row 3 appears only when the world offers
     * something to interact with (loot / artifact / elevator / asteroid / sky).
     * While downed, only chat is allowed, so the action rows are hidden.
     *
     * `$token` is the acting agent's own NHA token. Pass it for a per-user
     * agent (the `/start` / `/observe` flow) so the buttons submit intents with
     * that token instead of the bot's ambient default-agent token; leave it
     * null for the default agent, whose token is already configured on `$nha`.
     *
     * Respects Discord's limits: ≤5 buttons per row, ≤5 rows per message.
     */
    public function toContainer(NHA $nha, ?string $token = null): Container
    {
        $position = $this->getPosition();
        $positionText = $position ? sprintf('`(%s, %s)`', $position['x'] ?? '?', $position['y'] ?? '?') : 'unknown';

        $vision = $this->getVision();
        $visionText = is_scalar($vision) && $vision !== '' ? " · 👁 {$vision}" : '';

        $hp = (float) ($this->getHp() ?? 0);
        $lines = [
            "### Agent #{$this->agentId}" . ($this->isDowned() ? ' · 💀 **DOWNED**' : ''),
            'HP: ' . self::bar($hp, $this->getMaxHp()),
            "📍 {$positionText}{$visionText}" . $this->contextSuffix(),
        ];

        if ($inventory = $this->getInventory()) {
            $summary = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($inventory), $inventory));
            $lines[] = "🎒 {$summary}";
        }

        if ($looseParts = $this->count('loose_parts')) {
            $lines[] = "🔩 {$looseParts} loose part(s) — `finalize` to build a vehicle";
        }
        if ($vehicles = $this->count('vehicles')) {
            $lines[] = "🚗 {$vehicles} vehicle(s)";
        }
        if ($threats = $this->getThreats()) {
            $lines[] = '⚠️ ' . count($threats) . ' recent alert(s) against you';
        }
        if ($bounties = $this->getBounties()) {
            $lines[] = '💰 ' . count($bounties) . ' bounty offer(s)';
        }
        if ($offers = $this->count('trade_offers')) {
            $lines[] = "🤝 {$offers} incoming trade offer(s)";
        }

        if ($messages = array_slice($this->getMessages(), -3)) {
            $lines[] = '**Recent messages:**';
            foreach ($messages as $message) {
                $from = is_array($message) ? ($message['sender_name'] ?? $message['from'] ?? '?') : ($message->sender_name ?? $message->from ?? '?');
                $text = is_array($message) ? ($message['text'] ?? '') : ($message->text ?? '');
                $lines[] = "> **{$from}:** {$text}";
            }
        }

        // Submit a verb for this agent. With a per-user token, go through
        // intentWithToken(); otherwise fall back to the ambient-token wrapper.
        $submit = ($token !== null && $token !== '')
            ? fn(string $verb, array $args = []) => $nha->intentWithToken($this->agentId, $token, $verb, $args)
            : fn(string $verb, array $args = []) => $nha->intent($this->agentId, $verb, $args);

        // After any quick-action, re-observe and refresh the message in place.
        $refresh = fn($interaction) => $nha->observe($this->agentId)->then(
            fn(self $obs) => $interaction->updateMessage(NHA::createBuilder()->addComponent($obs->toContainer($nha, $token))),
        );
        $act = fn(callable $verb) => fn($i) => $verb($i)->then(fn() => $refresh($i));

        $components = [TextDisplay::new(implode("\n", $lines)), Separator::new()];

        $components[] = ActionRow::new()->addComponents([
            Button::secondary()->setLabel('⬆️')->setListener($act(fn($i) => $submit('move', ['dx' => 0, 'dy' => -1])), $nha),
            Button::secondary()->setLabel('⬇️')->setListener($act(fn($i) => $submit('move', ['dx' => 0, 'dy' => 1])), $nha),
            Button::secondary()->setLabel('⬅️')->setListener($act(fn($i) => $submit('move', ['dx' => -1, 'dy' => 0])), $nha),
            Button::secondary()->setLabel('➡️')->setListener($act(fn($i) => $submit('move', ['dx' => 1, 'dy' => 0])), $nha),
            Button::primary()->setLabel('🔄 Refresh')->setListener(fn($i) => $refresh($i), $nha),
        ]);

        if (! $this->isDowned()) {
            $components[] = ActionRow::new()->addComponents([
                Button::secondary()->setLabel('⛏️ Mine')->setListener($act(fn($i) => $submit('mine', ['n' => 1])), $nha),
                Button::secondary()->setLabel('🪓 Chop')->setListener($act(fn($i) => $submit('chop', ['n' => 1])), $nha),
                Button::secondary()->setLabel('🌿 Gather')->setListener($act(fn($i) => $submit('gather', ['n' => 1])), $nha),
                Button::secondary()->setLabel('🌱 Plant')->setListener($act(fn($i) => $submit('plant')), $nha),
                Button::success()->setLabel('❤️ Heal')->setListener($act(fn($i) => $submit('heal')), $nha),
            ]);

            if ($contextRow = $this->contextRow($nha, $act, $submit)) {
                $components[] = $contextRow;
            }
        }

        return Container::new()->addComponents($components);
    }

    /** One short line of world context appended to the position line. */
    private function contextSuffix(): string
    {
        $bits = [];
        if ($era = $this->get('expansion.era')) {
            $where = $this->get('expansion.location');
            $bits[] = '🌍 ' . $era . ($where ? " ({$where})" : '');
        }
        if (($alt = (int) ($this->get('altitude') ?? 0)) > 0 || $this->get('in_space')) {
            $bits[] = '🚀 alt ' . $alt . ($this->get('in_space') ? ' · in space' : '');
        }
        foreach (['nearby_deposits' => '⛏', 'nearby_plants' => '🌿', 'nearby_agents' => '👥', 'loot' => '📦', 'artifacts' => '✨', 'asteroids' => '☄'] as $key => $glyph) {
            if ($n = $this->count($key)) {
                $bits[] = "{$glyph}{$n}";
            }
        }

        return $bits ? "\n" . implode(' · ', $bits) : '';
    }

    /**
     * The third button row — only built when the world offers something to
     * interact with right here.
     *
     * @param callable(callable): callable    $act    Wraps a listener so it refreshes the message after acting.
     * @param callable(string, array=): mixed $submit Submits a verb for this agent (token-aware).
     */
    private function contextRow(NHA $nha, callable $act, callable $submit): ?ActionRow
    {
        $buttons = [];

        if ($this->count('artifacts')) {
            $buttons[] = Button::secondary()->setLabel('✨ Attune')->setListener($act(fn($i) => $submit('attune')), $nha);
        }
        if ($this->count('elevators')) {
            $buttons[] = Button::secondary()->setLabel('🛗 Ride')->setListener($act(fn($i) => $submit('ride')), $nha);
        }
        if ($this->count('asteroids')) {
            $buttons[] = Button::secondary()->setLabel('🔗 Dock')->setListener($act(fn($i) => $submit('dock')), $nha);
        }
        if ($this->get('in_space') || (int) ($this->get('altitude') ?? 0) > 0) {
            $buttons[] = Button::secondary()->setLabel('🛬 Land')->setListener($act(fn($i) => $submit('land')), $nha);
        } elseif ($this->count('vehicles')) {
            $buttons[] = Button::secondary()->setLabel('🛫 Launch')->setListener($act(fn($i) => $submit('launch')), $nha);
        }
        if ($loot = (array) ($this->get('loot') ?? [])) {
            $first = is_array($loot[0] ?? null) ? ($loot[0]['id'] ?? null) : ($loot[0] ?? null);
            if ($first !== null) {
                $buttons[] = Button::secondary()->setLabel('📦 Collect')->setListener($act(fn($i) => $submit('collect', ['loot' => $first])), $nha);
            }
        }

        return $buttons ? ActionRow::new()->addComponents(array_slice($buttons, 0, 5)) : null;
    }

    /**
     * @inheritDoc
     *
     * Returns the raw observation payload unchanged.
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
