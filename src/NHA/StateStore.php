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

use NHA\Parts\AgentObservation;

/**
 * Tiny JSON-file backed store for data that must survive a bot restart: the
 * default agent id + token, the per-Discord-user identity map, each agent's
 * last-known world position, the autoplay flag, the brain's last decision and
 * the registered slash-command signatures. Volatile per-tick world state
 * (market, scene, feed, …) is deliberately NOT stored here — it is re-fetched
 * live every time. Writes are atomic (temp file + rename).
 *
 * No NHA API schema backs this; it is local bot state only. Attached to the
 * client via {@see NHA::setStateStore()} so {@see NHA::observe()} can snapshot
 * position for every caller.
 *
 * @since 0.1.0
 */
class StateStore
{
    protected array $data;

    /**
     * Loads the store from `$path` (an empty state when the file is missing)
     * and sweeps any `.tmp` file left behind by a crash mid-{@see save()}.
     *
     * @param string $path Absolute path to the JSON state file.
     */
    public function __construct(protected readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode(file_get_contents($path), true) : [];

        // Sweep any temp file orphaned by a previous crash mid-{@see save()}.
        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /** The stored default agent id, or null when none has been registered. */
    public function getDefaultAgent(): ?int
    {
        return isset($this->data['default_agent']) ? (int) $this->data['default_agent'] : null;
    }

    /**
     * Sets the default agent id and, when known, its NHA action token.
     *
     * Passing `null` for `$token` leaves any previously stored token intact,
     * so callers that only know the agent id don't clobber it.
     */
    public function setDefaultAgent(int $agent_id, ?string $token = null): void
    {
        $this->data['default_agent'] = $agent_id;
        if (null !== $token) {
            $this->data['default_agent_token'] = $token;
        }
        $this->save();
    }

    /**
     * Gets the NHA action token for the default agent, if known.
     */
    public function getDefaultAgentToken(): ?string
    {
        return isset($this->data['default_agent_token']) ? (string) $this->data['default_agent_token'] : null;
    }

    /**
     * Gets the NHA identity assigned to a Discord user.
     *
     * @return array{agent_id: int, name: string, token: string}|null
     */
    public function getDiscordUserAgent(string $discord_user_id): ?array
    {
        $agent = $this->data['discord_users'][$discord_user_id] ?? null;
        if (! is_array($agent) || ! isset($agent['agent_id'], $agent['name'], $agent['token'])) {
            return null;
        }

        return [
            'agent_id' => (int) $agent['agent_id'],
            'name' => (string) $agent['name'],
            'token' => (string) $agent['token'],
        ];
    }

    /**
     * Saves a Discord user's NHA identity for later turns.
     */
    public function setDiscordUserAgent(string $discord_user_id, int $agent_id, string $name, string $token): void
    {
        $this->data['discord_users'][$discord_user_id] = [
            'agent_id' => $agent_id,
            'name' => $name,
            'token' => $token,
        ];
        $this->save();
    }

    /**
     * Snapshots the position + tick from a fresh observation. No-op when the
     * payload carries no position. Called for every {@see NHA::observe()} once
     * the store is attached via {@see NHA::setStateStore()}.
     */
    public function recordObservation(int $agent_id, AgentObservation $observation): void
    {
        $position = $observation->getPosition();
        if ($position === null) {
            return;
        }

        $tick = $observation->get('tick');
        $this->setAgentPosition(
            $agent_id,
            $position['x'],
            $position['y'],
            is_numeric($tick) ? (int) $tick : null,
        );
    }

    /**
     * Records an agent's last-known world position (from `GET /observe/:id`),
     * so a later turn can show it without a fresh fetch or detect that the
     * agent has moved. Written under `agent_positions` keyed by agent id.
     *
     * @param int      $agent_id
     * @param int      $x
     * @param int      $y
     * @param int|null $tick     The observation tick, when known.
     */
    public function setAgentPosition(int $agent_id, int $x, int $y, ?int $tick = null): void
    {
        $entry = ['x' => $x, 'y' => $y, 'updated_at' => time()];
        if (null !== $tick) {
            $entry['tick'] = $tick;
        }

        $this->data['agent_positions'][(string) $agent_id] = $entry;
        $this->save();
    }

    /**
     * Gets an agent's last-known position, if one has been recorded.
     *
     * @return array{x: int, y: int, tick?: int, updated_at: int}|null
     */
    public function getAgentPosition(int $agent_id): ?array
    {
        $entry = $this->data['agent_positions'][(string) $agent_id] ?? null;
        if (! is_array($entry) || ! isset($entry['x'], $entry['y'])) {
            return null;
        }

        $position = [
            'x' => (int) $entry['x'],
            'y' => (int) $entry['y'],
            'updated_at' => (int) ($entry['updated_at'] ?? 0),
        ];
        if (isset($entry['tick'])) {
            $position['tick'] = (int) $entry['tick'];
        }

        return $position;
    }

    /**
     * Whether the autonomous LLM play loop is currently enabled. Persisted so a
     * `!nha autoplay on` survives a restart.
     */
    public function isAutoplayEnabled(): bool
    {
        return (bool) ($this->data['autoplay'] ?? false);
    }

    /**
     * Turns the autonomous LLM play loop on or off.
     */
    public function setAutoplay(bool $enabled): void
    {
        $this->data['autoplay'] = $enabled;
        $this->save();
    }

    /**
     * Takes (or renews) the autoplay driver lease for `$holder`.
     *
     * Only one process should drive an agent's autoplay loop at a time —
     * `bot.php`'s in-process loop and the standalone `autoplay.php` runner both
     * use {@see \NHA\Brain\AutoPlayer} and, left unguarded, submit two intents
     * per interval from one token. Each loop passes a stable per-process holder
     * id; the first to acquire the lease drives, the others skip their turn
     * until it expires (a crashed driver frees it within `$ttl` seconds).
     *
     * The acquire/release is done under an exclusive OS lock (see
     * {@see withLeaseLock()}), so two runners starting at the same instant
     * cannot both observe "no lease" and both claim it — it is a real
     * compare-and-swap, not just an atomic file write.
     *
     * @param string   $holder   A stable id for the calling loop, e.g. `"bot.php:1234"`.
     * @param int|null $interval The loop's turn interval in seconds. The lease TTL is
     *                           derived from it ({@see leaseTtlForInterval()}) so the
     *                           lease outlives the gap between turns; null → the 45s floor.
     *
     * @return bool True when `$holder` now holds the lease (it was free, expired,
     *              or already theirs); false when another holder's lease is live.
     */
    public function acquireAutoplayLease(string $holder, ?int $interval = null): bool
    {
        $ttl = self::leaseTtlForInterval($interval);

        return (bool) $this->withLeaseLock(function () use ($holder, $ttl): bool {
            $lease = $this->data['autoplay_lease'] ?? null;
            $now = time();

            if (is_array($lease)
                && (string) ($lease['holder'] ?? '') !== $holder
                && (int) ($lease['expires'] ?? 0) > $now
            ) {
                return false;
            }

            $this->data['autoplay_lease'] = ['holder' => $holder, 'expires' => $now + $ttl];
            $this->save();

            return true;
        });
    }

    /**
     * The lease TTL for a given autoplay interval: three intervals, floored at
     * 45 seconds. A fixed TTL shorter than the interval expires in the gap
     * between turns, so lease ownership ping-pongs between the two runners
     * (harmless flapping, but noisy). Deriving it from the interval keeps the
     * lease alive across the quiet stretch.
     */
    public static function leaseTtlForInterval(?int $interval): int
    {
        return max(45, 3 * max(0, (int) $interval));
    }

    /** Drops the lease if `$holder` currently holds it (call on clean shutdown). */
    public function releaseAutoplayLease(string $holder): void
    {
        $this->withLeaseLock(function () use ($holder): void {
            if ((string) ($this->data['autoplay_lease']['holder'] ?? '') === $holder) {
                unset($this->data['autoplay_lease']);
                $this->save();
            }
        });
    }

    /**
     * Runs `$fn` while holding an exclusive OS lock on a sibling `.lease.lock`
     * file, with `$this->data` first re-read from disk so `$fn` sees the lease
     * exactly as other processes last left it — and, on {@see save()}, does not
     * clobber unrelated keys another process wrote in the meantime.
     *
     * Degrades to running `$fn` unlocked (the old best-effort read-modify-write)
     * when the lock file can't be opened: a rare doubled interval beats a loop
     * that never drives.
     *
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    private function withLeaseLock(callable $fn): mixed
    {
        $lock = @fopen($this->path . '.lease.lock', 'c');
        if ($lock === false) {
            return $fn();
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return $fn();
            }

            if (is_file($this->path)) {
                $this->data = (array) json_decode((string) file_get_contents($this->path), true);
            }

            return $fn();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** The id of the process currently holding a live autoplay lease, or null. */
    public function autoplayLeaseHolder(): ?string
    {
        $lease = $this->data['autoplay_lease'] ?? null;

        return (is_array($lease) && (int) ($lease['expires'] ?? 0) > time())
            ? (string) $lease['holder']
            : null;
    }

    /**
     * Records the brain's most recent decision for an agent (verb, args, the
     * one-line rationale and the `queued_intent` id it produced), so a later
     * command can show "what did the bot last do, and did it land?".
     *
     * @param int                  $agent_id
     * @param array<string, mixed> $decision Expects keys: verb, args, reason, queued_intent, tick.
     */
    public function recordDecision(int $agent_id, array $decision): void
    {
        $entry = [
            'verb' => (string) ($decision['verb'] ?? ''),
            'args' => (array) ($decision['args'] ?? []),
            'reason' => (string) ($decision['reason'] ?? ''),
            'queued_intent' => isset($decision['queued_intent']) ? (int) $decision['queued_intent'] : null,
            'tick' => isset($decision['tick']) ? (int) $decision['tick'] : null,
            'at' => time(),
        ];

        $this->data['agent_decisions'][(string) $agent_id] = $entry;

        // Also keep a short rolling history so the brain can see it is looping
        // (or has already tried a `combine` pair) beyond just the last turn.
        $log = (array) ($this->data['agent_decision_log'][(string) $agent_id] ?? []);
        $log[] = ['verb' => $entry['verb'], 'args' => $entry['args'], 'tick' => $entry['tick']];
        $this->data['agent_decision_log'][(string) $agent_id] = array_slice($log, -15);

        $this->save();
    }

    /**
     * Gets the brain's last recorded decision for an agent, if any.
     *
     * @return array{verb: string, args: array, reason: string, queued_intent: ?int, tick: ?int, at: int}|null
     */
    public function getLastDecision(int $agent_id): ?array
    {
        $entry = $this->data['agent_decisions'][(string) $agent_id] ?? null;
        if (! is_array($entry) || ! isset($entry['verb'])) {
            return null;
        }

        return [
            'verb' => (string) $entry['verb'],
            'args' => (array) ($entry['args'] ?? []),
            'reason' => (string) ($entry['reason'] ?? ''),
            'queued_intent' => isset($entry['queued_intent']) ? (int) $entry['queued_intent'] : null,
            'tick' => isset($entry['tick']) ? (int) $entry['tick'] : null,
            'at' => (int) ($entry['at'] ?? 0),
        ];
    }

    /**
     * The agent's last few decisions, oldest first — a rolling window so the
     * brain can detect a loop or an already-tried `combine` pair. Each entry is
     * `{verb, args, tick}`; older/other fields are not kept here.
     *
     * @return list<array{verb: string, args: array, tick: ?int}>
     */
    public function getRecentDecisions(int $agent_id, int $limit = 10): array
    {
        $log = (array) ($this->data['agent_decision_log'][(string) $agent_id] ?? []);
        $out = [];
        foreach (array_slice($log, -max(1, $limit)) as $e) {
            if (! is_array($e) || ! isset($e['verb'])) {
                continue;
            }
            $out[] = [
                'verb' => (string) $e['verb'],
                'args' => (array) ($e['args'] ?? []),
                'tick' => isset($e['tick']) ? (int) $e['tick'] : null,
            ];
        }

        return $out;
    }

    /**
     * The signature of the last successfully registered slash command by name.
     * Used to decide whether a command's definition changed since the last boot
     * and needs pushing to Discord (a rate-limited write).
     *
     * @return array<string, string> `command name => sha1(definition)`
     */
    public function getCommandSignatures(): array
    {
        return array_map('strval', (array) ($this->data['command_signatures'] ?? []));
    }

    /** Records that `$name` was registered with definition signature `$hash`. */
    public function setCommandSignature(string $name, string $hash): void
    {
        $this->data['command_signatures'][$name] = $hash;
        $this->save();
    }

    /**
     * Atomically persists the current state: writes a sibling temp file then
     * renames it over the target, so a crash (or SIGTERM) mid-write can never
     * leave a truncated file. Silently no-ops if the temp write fails.
     */
    protected function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        // Write to a sibling temp file then rename, so a crash (or a SIGTERM
        // mid-write) can never leave a truncated / half-written state file.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }
        @rename($tmp, $this->path);
    }
}
