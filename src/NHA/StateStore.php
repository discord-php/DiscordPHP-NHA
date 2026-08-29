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

/**
 * Tiny JSON-file backed store for the default agent id, so chat/slash
 * commands and the relay loop can omit `agent_id` once an agent has been
 * registered.
 *
 * @since 0.1.0
 */
class StateStore
{
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode(file_get_contents($path), true) : [];
    }

    public function getDefaultAgent(): ?int
    {
        return isset($this->data['default_agent']) ? (int) $this->data['default_agent'] : null;
    }

    public function setDefaultAgent(int $agent_id): void
    {
        $this->data['default_agent'] = $agent_id;
        $this->save();
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT));
    }
}
