<?php

declare(strict_types=1);

use Discord\Http\Endpoint as DiscordEndpoint;
use NHA\Http\Http;
use NHA\Http\Request;
use React\Promise\Deferred;

class RequestTest extends NHATestCase
{
    private function request(): Request
    {
        return new Request(new Deferred(), 'get', new DiscordEndpoint('world'), '', []);
    }

    /**
     * @covers \NHA\Http\Request
     */
    public function testDefaultsToTheLiveWorldBaseUrl(): void
    {
        $this->assertSame(Http::BASE_URL . '/world', $this->request()->getUrl());
    }

    /**
     * @covers \NHA\Http\Request
     */
    public function testSetBaseUrlOverridesAndTrimsATrailingSlash(): void
    {
        $req = $this->request();
        $req->setBaseUrl('http://localhost:8000/');

        $this->assertSame('http://localhost:8000/world', $req->getUrl());
    }

    /**
     * @covers \NHA\Http\Request
     */
    public function testAnEmptyBaseUrlFallsBackToTheConstant(): void
    {
        $req = $this->request();
        $req->setBaseUrl('');

        $this->assertSame(Http::BASE_URL . '/world', $req->getUrl());
    }
}
