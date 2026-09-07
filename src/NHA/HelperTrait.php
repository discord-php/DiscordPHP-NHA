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

use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;

/**
 * Small presentation helpers shared by {@see NHA} and {@see Commands} for
 * turning NHA world data into Discord output: a mention-safe
 * {@see MessageBuilder} factory, a text progress-bar renderer and the
 * project attribution footer.
 *
 * Pure formatting only — no NHA API or world-model concerns live here.
 *
 * @see \Discord\Builders\MessageBuilder Discord message builder this wraps
 *
 * @since 3.0.0
 */
trait HelperTrait
{
    /** The project source repository. */
    public const GITHUB = 'https://github.com/discord-php/DiscordPHP-NHA';

    /** GitHub Sponsors page for the maintainer. */
    public const SPONSOR = 'https://github.com/sponsors/valzargaming';

    /**
     * A {@see Separator} plus a subtle one-line footer crediting the project
     * and pointing at the repo and GitHub Sponsors. Appended to the rich
     * panels (observation, help, control panel) — not the one-line replies.
     *
     * @return array{0: Separator, 1: TextDisplay}
     */
    public static function attributionComponents(): array
    {
        return [
            Separator::new(),
            TextDisplay::new(sprintf(
                '-# 🤖 Built on [DiscordPHP-NHA](%s) · ⭐ Star it · 💜 [Sponsor](%s)',
                self::GITHUB,
                self::SPONSOR,
            )),
        ];
    }

    /**
     * Creates a new instance of MessageBuilder, optionally preventing
     * mentions in the message.
     *
     * @param bool $prevent_mentions
     *
     * @return MessageBuilder
     */
    public static function createBuilder(bool $prevent_mentions = true): MessageBuilder
    {
        $builder = MessageBuilder::new();
        if ($prevent_mentions) {
            $builder->setAllowedMentions(AllowedMentions::none());
        }

        return $builder;
    }

    /**
     * Renders a 0-1 ratio as a small text progress bar, e.g. for HP.
     *
     * @param float $current
     * @param float $max
     * @param int   $length
     *
     * @return string
     */
    public static function bar(float $current, float $max, int $length = 10): string
    {
        $max = max($max, 1);
        $filled = (int) round($length * max(0, min(1, $current / $max)));

        return str_repeat('█', $filled) . str_repeat('░', $length - $filled) . " ({$current}/{$max})";
    }
}
