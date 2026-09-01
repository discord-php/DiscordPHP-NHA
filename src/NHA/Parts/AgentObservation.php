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
 * response. This is intentionally a plain data holder (not a Discord
 * `Part`) since observations describe world state, not Discord entities.
 *
 * @since 0.1.0
 */
class AgentObservation implements JsonSerializable
{
    use HelperTrait;

    /** Raw, decoded JSON body as returned by the world. */
    public readonly array $raw;

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

    public function getHp(): ?float
    {
        return $this->get('hp') ?? $this->get('health');
    }

    public function getMaxHp(): float
    {
        return (float) ($this->get('max_hp') ?? $this->get('hp_max') ?? 100);
    }

    public function getPosition(): ?array
    {
        return $this->get('position') ?? $this->get('pos');
    }

    public function getInventory(): array
    {
        return (array) ($this->get('inventory') ?? []);
    }

    public function getVision(): mixed
    {
        return $this->get('vision') ?? $this->get('sight_radius');
    }

    public function getMessages(): array
    {
        return (array) ($this->get('messages') ?? []);
    }

    public function getThreats(): array
    {
        return (array) ($this->get('threats') ?? $this->get('threat_alerts') ?? []);
    }

    public function getContracts(): array
    {
        return (array) ($this->get('contracts') ?? []);
    }

    public function getBounties(): array
    {
        return (array) ($this->get('bounties') ?? []);
    }

    public function getNearbyAgents(): array
    {
        return (array) ($this->get('nearby.agents') ?? $this->get('agents') ?? []);
    }

    /**
     * Builds a Components V2 container summarizing this observation, with
     * quick-action buttons (move, mine, chop, gather, refresh) wired via
     * `Button::setListener()` so they work regardless of how the
     * observation was originally requested (chat command, slash command,
     * or another button).
     *
     * Kept intentionally compact to respect Discord's component limits
     * (max 5 buttons per action row, max 5 action rows per message).
     *
     * @param NHA $nha
     *
     * @return Container
     */
    public function toContainer(NHA $nha): Container
    {
        $position = $this->getPosition();
        $positionText = $position ? sprintf('`(%s, %s)`', $position['x'] ?? '?', $position['y'] ?? '?') : 'unknown';

        $vision = $this->getVision();
        $visionText = is_scalar($vision) && $vision !== '' ? " · Vision: {$vision}" : '';

        $lines = [
            "### Agent #{$this->agentId}",
            'HP: ' . self::bar((float) ($this->getHp() ?? 0), $this->getMaxHp()),
            "Position: {$positionText}{$visionText}",
        ];

        if ($inventory = $this->getInventory()) {
            $summary = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($inventory), $inventory));
            $lines[] = "Inventory: {$summary}";
        }

        if ($threats = $this->getThreats()) {
            $lines[] = '⚠️ Threats: ' . count($threats) . ' recent alert(s)';
        }

        if ($bounties = $this->getBounties()) {
            $lines[] = '💀 Bounties: ' . count($bounties) . ' open';
        }

        if ($messages = array_slice($this->getMessages(), -3)) {
            $lines[] = '**Recent messages:**';
            foreach ($messages as $message) {
                $from = is_array($message) ? ($message['from'] ?? '?') : ($message->from ?? '?');
                $text = is_array($message) ? ($message['text'] ?? '') : ($message->text ?? '');
                $lines[] = "> **{$from}:** {$text}";
            }
        }

        // After any quick-action, re-observe and refresh the message in place.
        $refresh = fn($interaction) => $nha->observe($this->agentId)->then(
            fn(self $obs) => $interaction->updateMessage(NHA::createBuilder()->addComponent($obs->toContainer($nha))),
        );

        $container = Container::new()->addComponents([
            TextDisplay::new(implode("\n", $lines)),
            Separator::new(),
            ActionRow::new()->addComponents([
                Button::secondary()->setLabel('⬆️')->setListener(fn($i) => $nha->move($this->agentId, 0, -1)->then(fn() => $refresh($i)), $nha),
                Button::secondary()->setLabel('⬇️')->setListener(fn($i) => $nha->move($this->agentId, 0, 1)->then(fn() => $refresh($i)), $nha),
                Button::secondary()->setLabel('⬅️')->setListener(fn($i) => $nha->move($this->agentId, -1, 0)->then(fn() => $refresh($i)), $nha),
                Button::secondary()->setLabel('➡️')->setListener(fn($i) => $nha->move($this->agentId, 1, 0)->then(fn() => $refresh($i)), $nha),
                Button::primary()->setLabel('🔄 Refresh')->setListener(fn($i) => $refresh($i), $nha),
            ]),
        ]);

        return $container;
    }

    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
