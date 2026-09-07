<?php

declare(strict_types=1);

use NHA\Http\Endpoint;

class EndpointTest extends NHATestCase
{
    /**
     * @covers \NHA\Http\Endpoint
     */
    public function testBindAssocReplacesPlaceholder(): void
    {
        $endpoint = Endpoint::bind(Endpoint::OBSERVE)->bindAssoc(['agent_id' => 5]);

        $this->assertSame('observe/5', (string) $endpoint);
    }

    /**
     * @covers \NHA\Http\Endpoint
     */
    public function testBindArgsReplacesPlaceholderPositionally(): void
    {
        $endpoint = Endpoint::bind(Endpoint::AGENT, 9);

        $this->assertSame('agent/9', (string) $endpoint);
    }

    /**
     * @covers \NHA\Http\Endpoint
     */
    public function testStaticEndpointsHaveNoPlaceholders(): void
    {
        $this->assertSame('world', (string) Endpoint::bind(Endpoint::WORLD));
        $this->assertSame('agents', (string) Endpoint::bind(Endpoint::AGENTS));
    }

    /**
     * @covers \NHA\Http\Endpoint
     */
    public function testAddQueryAppendsQueryString(): void
    {
        $endpoint = Endpoint::bind(Endpoint::WORLD);
        $endpoint->addQuery('foo', 'bar');

        $this->assertSame('world?foo=bar', (string) $endpoint);
    }
}
