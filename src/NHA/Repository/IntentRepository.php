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

namespace NHA\Repository;

use Discord\Http\Exceptions\NotFoundException;
use Discord\Http\Exceptions\RequestFailedException;
use NHA\Http\Endpoint;
use NHA\Parts\IntentStatus;
use React\Promise\PromiseInterface;

/**
 * Repository for reading queued-intent outcomes.
 *
 * An intent is *submitted* through {@see \NHA\VerbsTrait::intent()} /
 * {@see \NHA\NHA::intentWithToken()} (`POST /intent`), which resolves with an
 * `IntentQueuedOut` body carrying `queued_intent`. Pass that id here — once the
 * world tick has advanced past the intent's `created` tick — to read whether it
 * `applied` or was `rejected`.
 *
 * @link https://nha.recluse.lol/docs#/agent/intent_status_intent__intent_id__get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/IntentStatusOut
 *
 * @since 3.0.0
 */
class IntentRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = IntentStatus::class;

    /**
     * Fetches the stored outcome of a queued intent
     * (`GET /intent/{intent_id}` → `IntentStatusOut`).
     *
     * @link https://nha.recluse.lol/docs#/agent/intent_status_intent__intent_id__get
     *
     * @param int|string $intent_id The `queued_intent` id from the `POST /intent` response.
     *
     * @return PromiseInterface<IntentStatus> Resolves with a synthetic `status: 'gone'`
     *                                        part when the id has aged out of the world's
     *                                        retention window (`404`/`410`); other transport
     *                                        failures still reject.
     */
    public function getIntentStatus(int|string $intent_id): PromiseInterface
    {
        $endpoint = Endpoint::bind(Endpoint::INTENT_STATUS)->bindAssoc(['intent_id' => (string) $intent_id]);

        return $this->nha_http->get($endpoint)->then(
            fn($data) => $this->factory->part(IntentStatus::class, (array) $data, true),
            function (\Throwable $e) use ($intent_id) {
                // A queued intent is only kept for a short retention window. Once
                // it applied or was rejected long ago the world returns 404/410
                // and will never resolve it again — surface that as a terminal
                // `gone` status so the caller stops polling it every turn (and
                // the HTTP layer stops logging the failure with a stack trace).
                if ($e instanceof NotFoundException
                    || ($e instanceof RequestFailedException && (int) $e->getCode() === 410)
                ) {
                    return $this->factory->part(IntentStatus::class, [
                        'id' => is_numeric($intent_id) ? (int) $intent_id : $intent_id,
                        'status' => 'gone',
                        'result' => 'intent expired from the retention window',
                    ], true);
                }

                throw $e;
            },
        );
    }
}
