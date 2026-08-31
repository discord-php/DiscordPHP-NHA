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
use NHA\NHA;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Repository for querying NHA discovery and intent related data.
 */
class DiscoveryRepository extends AbstractRepository
{
    /**
     * Fetches nearby deposits.
     *
     * @param array       $options             An array of options.
     * @param float|null  $options['x']        The x coordinate.
     * @param float|null  $options['y']        The y coordinate.
     * @param int|null    $options['radius']   Search radius.
     * @param string|null $options['resource'] Resource type.
     * @param int|null    $options['limit']    Max entries to return.
     *
     * @return PromiseInterface
     */
    public function getDeposits(array $options = []): PromiseInterface
    {
        $resolver = new OptionsResolver();
        $resolver
            ->setRequired(['x', 'y'])
            ->setDefined(['radius', 'resource', 'limit'])
            ->setAllowedTypes('x', 'float')
            ->setAllowedTypes('y', 'float')
            ->setAllowedTypes('radius', 'int')
            ->setAllowedTypes('resource', 'string')
            ->setAllowedTypes('limit', 'int')
            ->setDefault('radius', 100)
            ->setDefault('limit', 10)
            ->setAllowedValues('resource', fn($value) => $value === '' || is_string($value));

        $options = $resolver->resolve($options);

        $endpoint = Endpoint::bind(Endpoint::DEPOSITS);

        $query = [
            'x' => (string) $options['x'],
            'y' => (string) $options['y'],
            'radius' => (string) $options['radius'],
            'limit' => (string) $options['limit'],
        ];

        if (isset($options['resource'])) {
            $query['resource'] = $options['resource'];
        }

        return $this->nha_http->get((string) $endpoint, $query);
    }
}
