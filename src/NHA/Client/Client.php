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

namespace NHA\Client;

use Discord\Parts\User\Client as DiscordClient;
use NHA\Repository\AgentRepository;
use NHA\Repository\DiscoveryRepository;
use NHA\Repository\EconomyRepository;
use NHA\Repository\IntentRepository;
use NHA\Repository\SocialRepository;
use NHA\Repository\WorldRepository;

/**
 * The NHA Client class.
 *
 * @property AgentRepository $agents
 * @property DiscoveryRepository $discovery
 * @property EconomyRepository $economy
 * @property IntentRepository $intents
 * @property SocialRepository $social
 * @property WorldRepository $world
 */
class Client extends DiscordClient
{
    /**
     * @inheritDoc
     */
    protected $repositories = [
        'agents' => AgentRepository::class,
        'discovery' => DiscoveryRepository::class,
        'economy' => EconomyRepository::class,
        'intents' => IntentRepository::class,
        'social' => SocialRepository::class,
        'world' => WorldRepository::class,
    ];
}
