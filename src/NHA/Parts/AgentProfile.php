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

/**
 * A lightweight, read-only wrapper around a single `GET /agent/{agent_id}`
 * response (the `AgentProfileOut` schema): one agent's full story.
 *
 * @link https://nha.recluse.lol/docs#/agent/agent_profile_agent__agent_id__get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/AgentProfileOut
 *
 * @property array $agent         Core stats + inventory.
 * @property array $vehicles      Owned vehicles.
 * @property int   $vehicle_count Number of owned vehicles.
 * @property array $discoveries   Recipes/inventions credited to this agent.
 * @property array $milestones    This agent's milestone timeline.
 * @property array $recent        Recent actions.
 *
 * @since 0.1.0
 */
class AgentProfile extends Out
{
    /** @inheritdoc */
    protected $fillable = [
        'agent',
        'vehicles',
        'vehicle_count',
        'discoveries',
        'milestones',
        'recent',
    ];
}
