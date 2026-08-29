<?php

declare(strict_types=1);

namespace Tests\NHA\Http;

use NHA\Http\Endpoint;
use PHPUnit\Framework\TestCase;

class EndpointTest extends TestCase
{
    public function testBindAssocReplacesPlaceholder(): void
    {
        $endpoint = Endpoint::bind(Endpoint::OBSERVE)->bindAssoc(['agent_id' => 5]);

        $this->assertSame('observe/5', (string) $endpoint);
    }

    public function testBindArgsReplacesPlaceholderPositionally(): void
    {
        $endpoint = Endpoint::bind(Endpoint::AGENT, 9);

        $this->assertSame('agent/9', (string) $endpoint);
    }

    public function testStaticEndpointsHaveNoPlaceholders(): void
    {
        $this->assertSame('world', (string) Endpoint::bind(Endpoint::WORLD));
        $this->assertSame('agents', (string) Endpoint::bind(Endpoint::AGENTS));
    }

    public function testAddQueryAppendsQueryString(): void
    {
        $endpoint = Endpoint::bind(Endpoint::WORLD);
        $endpoint->addQuery('foo', 'bar');

        $this->assertSame('world?foo=bar', (string) $endpoint);
    }
}
