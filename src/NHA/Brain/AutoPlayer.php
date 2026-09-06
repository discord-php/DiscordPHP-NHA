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

namespace NHA\Brain;

use NHA\NHA;
use NHA\Parts\AgentObservation;
use NHA\StateStore;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Runs one observe → decide → act cycle for an agent: fetch the world, ask the
 * {@see AgentBrain}, and queue the chosen intent via {@see NHA::intentWithToken()}.
 *
 * A queued intent is only that — queued. This class records the `queued_intent`
 * id (via {@see StateStore::recordDecision()}) so the outcome can be polled
 * later; it never claims the action succeeded.
 *
 * @since 0.1.0
 */
final class AutoPlayer
{
    public function __construct(
        private readonly NHA $nha,
        private readonly AgentBrain $brain,
        private readonly StateStore $state,
    ) {
        // Make the observe() → position write self-sufficient even when this
        // player is used without a Commands layer wiring the store.
        $this->nha->setStateStore($state);
    }

    /**
     * Executes a single turn.
     *
     * @param int    $agent_id
     * @param string $token    The agent's NHA action token. Empty falls back to
     *                         {@see NHA::getAgentToken()}.
     *
     * @return PromiseInterface<string> A human-readable status line.
     */
    public function step(int $agent_id, string $token = ''): PromiseInterface
    {
        if ($token === '') {
            $token = $this->nha->getAgentToken();
        }

        return $this->nha->observe($agent_id)->then(function (AgentObservation $observation) use ($agent_id, $token) {
            $tick = (int) ($observation->get('tick') ?? 0);
            $downedUntil = (int) ($observation->get('downed_until') ?? 0);

            if ($downedUntil > $tick) {
                return resolve("🩹 Agent #{$agent_id} is downed until tick {$downedUntil} — skipping.");
            }

            return $this->brain->decide($observation)->then(function (?array $decision) use ($agent_id, $token, $tick) {
                if ($decision === null) {
                    return "💤 Agent #{$agent_id}: brain chose to wait (tick {$tick}).";
                }

                return $this->nha->intentWithToken($agent_id, $token, $decision['verb'], $decision['args'])
                    ->then(function ($queued) use ($agent_id, $decision, $tick) {
                        $queued = (array) $queued;
                        $queuedId = $queued['queued_intent'] ?? null;

                        $this->state->recordDecision($agent_id, [
                            'verb' => $decision['verb'],
                            'args' => $decision['args'],
                            'reason' => $decision['reason'],
                            'queued_intent' => $queuedId,
                            'tick' => $tick,
                        ]);

                        $ref = $queuedId !== null ? " (queued #{$queuedId})" : '';
                        $args = $decision['args'] === [] ? '' : ' ' . json_encode($decision['args'], JSON_UNESCAPED_SLASHES);
                        $reason = $decision['reason'] !== '' ? "\n> {$decision['reason']}" : '';

                        return "🤖 Agent #{$agent_id} → **{$decision['verb']}**{$args}{$ref}{$reason}";
                    });
            });
        });
    }
}
