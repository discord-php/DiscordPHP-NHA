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

use NHA\Http\Endpoint;
use NHA\Parts\Deposits;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The nearest live (amount > 0) deposits to `(x, y)`, optionally of one
 * `resource` — so an agent can navigate straight to materials its local
 * `observe.nearby_deposits` window does not show. Read-only, cached per tick.
 *
 * @link https://nha.recluse.lol/docs#/world/deposits_ep_deposits_get Endpoint reference
 * @link https://nha.recluse.lol/openapi.json #/components/schemas/DepositsOut
 *
 * @since 0.1.0
 */
class DepositsRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = Deposits::class;

    /**
     * Fetches the nearest deposits to a reference point, one {@see Deposits} row
     * per result (`GET /deposits` → `DepositsOut.deposits`).
     *
     * @link https://nha.recluse.lol/docs#/world/deposits_ep_deposits_get
     *
     * @param array  $options {
     * @var   int    $x        Reference x, 0-4095 (required — usually your position).
     * @var   int    $y        Reference y, 0-4095 (required).
     * @var   string $resource Filter to one resource, e.g. aluminum/silicon/titanium; omit for any.
     * @var   int    $limit    Max rows, 1-50. Default 8.
     *               }
     *
     * @return PromiseInterface<Deposits[]>
     */
    public function getDeposits(array $options = []): PromiseInterface
    {
        $resolver = new OptionsResolver();
        $resolver
            ->setRequired(['x', 'y'])
            ->setDefined(['resource', 'limit'])
            ->setAllowedTypes('x', 'int')
            ->setAllowedTypes('y', 'int')
            ->setAllowedTypes('resource', 'string')
            ->setAllowedTypes('limit', 'int')
            ->setDefault('limit', 8)
            ->setAllowedValues('x', fn($v) => $v >= 0 && $v <= 4095)
            ->setAllowedValues('y', fn($v) => $v >= 0 && $v <= 4095)
            ->setAllowedValues('limit', fn($v) => $v >= 1 && $v <= 50)
            ->setAllowedValues('resource', fn($value) => $value === '' || is_string($value));

        $options = $resolver->resolve($options);

        $endpoint = Endpoint::bind(Endpoint::DEPOSITS);
        $endpoint->addQuery('x', $options['x']);
        $endpoint->addQuery('y', $options['y']);
        $endpoint->addQuery('limit', $options['limit']);

        if (isset($options['resource'])) {
            $endpoint->addQuery('resource', $options['resource']);
        }

        return $this->nha_http->get($endpoint)->then(function ($response) {
            $deposits = [];
            foreach ($response->deposits as $deposit) {
                $deposits[] = $this->factory->part($this->class, (array) $deposit, true);
            }

            return $deposits;
        });
    }
}
