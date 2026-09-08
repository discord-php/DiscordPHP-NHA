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

namespace NHA\State;

/**
 * {@see \NHA\StateStore} slice: the single-driver lease for the autoplay loop.
 * `bot.php`'s in-process loop and the standalone `autoplay.php` runner both use
 * {@see \NHA\Brain\AutoPlayer}; the lease stops them submitting two intents per
 * interval from one token.
 *
 * Relies on the host's `array $data`, `save()` and `string $path`.
 *
 * @since 3.1.32
 */
trait AutoplayLeaseTrait
{
    /**
     * Takes (or renews) the autoplay driver lease for `$holder`.
     *
     * Only one process should drive an agent's autoplay loop at a time. Each
     * loop passes a stable per-process holder id; the first to acquire the
     * lease drives, the others skip their turn until it expires (a crashed
     * driver frees it within the TTL).
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

    /** The id of the process currently holding a live autoplay lease, or null. */
    public function autoplayLeaseHolder(): ?string
    {
        $lease = $this->data['autoplay_lease'] ?? null;

        return (is_array($lease) && (int) ($lease['expires'] ?? 0) > time())
            ? (string) $lease['holder']
            : null;
    }

    /**
     * Runs `$fn` while holding an exclusive OS lock on a sibling `.lease.lock`
     * file, with `$this->data` first re-read from disk so `$fn` sees the lease
     * exactly as other processes last left it — and, on {@see save()}, does not
     * clobber unrelated keys another process wrote in the meantime.
     *
     * The re-read is only adopted when it decodes to a non-empty array. An empty
     * or truncated state file (full disk, interrupted first write, a hand-edit)
     * would otherwise become `[]`, and the next {@see save()} would persist a
     * file holding nothing but the lease — dropping the agent token, which the
     * NHA server issues exactly once. A stale in-memory read is the safe failure.
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
                $fresh = json_decode((string) file_get_contents($this->path), true);
                if (is_array($fresh) && $fresh !== []) {
                    $this->data = $fresh;
                }
            }

            return $fn();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
