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

namespace NHA\Http\Exceptions;

/**
 * Thrown when the NHA API responds with 422 Unprocessable Entity.
 *
 * This means the request we sent was malformed or did not match the schema
 * the API expects (e.g. a missing/invalid field) — it is a client-side bug,
 * not a transient failure, so it is never retried automatically. The message
 * carries the raw response body, whose shape is the `HTTPValidationError`
 * schema (a `detail` list of per-field errors).
 *
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/HTTPValidationError
 *
 * @see \NHA\Http\Http::handleError() Where a 422 response is turned into this exception
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class ValidationException extends \DomainException {}
