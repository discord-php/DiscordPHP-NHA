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
 * The nearest live (amount>0) deposits to (x,y), optionally of one resource — so an agent can find materials its local observe.nearby_deposits (a small local window) doesn't show, then move{x,y} straight to one.
 * Each row: {id, resource, amount, x, y, dist}.
 *
 * Read-only, cached per tick.
 */
class DepositsRepository extends AbstractRepository
{
    /** @inheritdoc */
    protected $class = Deposits::class;

    /**
     * Fetches nearby deposits.
     *
     * @param array        $options             An array of options.
     * @param int          $options['x']        The x coordinate.
     * @param int          $options['y']        The y coordinate.
     * @param ?string|null $options['resource'] Resource type.
     * @param ?int|null    $options['limit']    Max entries to return. Default 8. Max 50.
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
            ->setAllowedValues('resource', fn($value) => $value === '' || is_string($value));

        $options = $resolver->resolve($options);

        $endpoint = Endpoint::bind(Endpoint::DEPOSITS);

        $query = [
            'x' => (string) $options['x'],
            'y' => (string) $options['y'],
            'limit' => (string) $options['limit'],
        ];

        if (isset($options['resource'])) {
            $query['resource'] = $options['resource'];
        }

        return $this->nha_http->get((string) $endpoint, $query)->then(function ($response) {
            $deposits = [];
            foreach ($response->deposits as $deposit) {
                $deposits[] = $this->factory->part($this->class, (array) $deposit);
            }
            return $deposits;
        });
    }
}
