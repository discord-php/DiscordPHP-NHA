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

use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Client as DiscordClient;
use Discord\Repository\EmojiRepository;
use Discord\Repository\GuildRepository;
use Discord\Repository\PrivateChannelRepository;
use Discord\Repository\SoundRepository;
use Discord\Repository\StickerPackRepository;
use Discord\Repository\UserRepository;
use NHA\Repository\AgentRepository;
use NHA\Repository\DepositsRepository;
use NHA\Repository\EconomyRepository;
use NHA\Repository\HistoryRepository;
use NHA\Repository\IntentRepository;
use NHA\Repository\MetaRepository;
use NHA\Repository\SocialRepository;
use NHA\Repository\WorldRepository;

/**
 * The NHA Client class — a DiscordPHP {@see DiscordClient} whose repository map
 * additionally exposes the NHA world read endpoints, grouped by OpenAPI tag.
 *
 * @link https://nha.recluse.lol/docs Interactive API documentation
 * @link https://nha.recluse.lol/openapi.json Machine-readable API contract
 *
 * @property EmojiRepository          $emojis
 * @property GuildRepository          $guilds
 * @property PrivateChannelRepository $private_channels
 * @property SoundRepository          $sounds
 * @property StickerPackRepository    $sticker_packs
 * @property UserRepository           $users
 * @property AgentRepository          $agents
 * @property DepositsRepository       $deposits
 * @property EconomyRepository        $economy
 * @property HistoryRepository        $history
 * @property IntentRepository         $intents
 * @property MetaRepository           $meta
 * @property SocialRepository         $social
 * @property WorldRepository          $world
 */
class Client extends DiscordClient
{
    /**
     * @inheritDoc
     */
    protected $repositories = [
        'emojis' => EmojiRepository::class,
        'guilds' => GuildRepository::class,
        'private_channels' => PrivateChannelRepository::class,
        'sounds' => SoundRepository::class,
        'sticker_packs' => StickerPackRepository::class,
        'users' => UserRepository::class,
        'agents' => AgentRepository::class,
        'deposits' => DepositsRepository::class,
        'economy' => EconomyRepository::class,
        'history' => HistoryRepository::class,
        'intents' => IntentRepository::class,
        'meta' => MetaRepository::class,
        'social' => SocialRepository::class,
        'world' => WorldRepository::class,
    ];

    /**
     * Discord's own bootstrap `Discord\Parts\User\Client` (created before NHA
     * upgrades `$discord->client` to this class) already issues the one
     * `GET applications/@me` request; fetching it again here would race that
     * request and intermittently leave `application` unresolved when this
     * instance is read. `NHA::ensureClient()` copies the resolved Application
     * over once it is actually ready.
     */
    public function afterConstruct(): void
    {
        $this->application = $this->factory->part(Application::class, [], true);
    }
}
