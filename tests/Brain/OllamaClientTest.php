<?php

declare(strict_types=1);

use NHA\Brain\OllamaClient;

use function React\Promise\reject;
use function React\Promise\resolve;

class OllamaClientTest extends NHAUnitTestCase
{
    /** @var array{method:string,url:string,headers:array,body:string}|null */
    private ?array $lastRequest = null;

    private function client(string $responseBody, string $model = 'gemma3:27b'): OllamaClient
    {
        return new OllamaClient(
            'http://gemma-host:11434/',
            $model,
            function (string $method, string $url, array $headers, string $body) use ($responseBody) {
                $this->lastRequest = compact('method', 'url', 'headers', 'body');

                return resolve($responseBody);
            },
            timeout: 5.0,
            numCtx: 32768,
        );
    }

    public function testChatPostsToApiChatWithModelContextAndMessages(): void
    {
        $client = $this->client(json_encode(['message' => ['role' => 'assistant', 'content' => '{"verb":"wait"}'], 'done' => true]));

        $result = null;
        $client->chat([['role' => 'user', 'content' => 'hi']])->then(function ($c) use (&$result) {
            $result = $c;
        });

        $this->assertSame('POST', $this->lastRequest['method']);
        $this->assertSame('http://gemma-host:11434/api/chat', $this->lastRequest['url']);
        $this->assertSame('application/json', $this->lastRequest['headers']['Content-Type']);

        $sent = json_decode($this->lastRequest['body'], true);
        $this->assertSame('gemma3:27b', $sent['model']);
        $this->assertFalse($sent['stream']);
        $this->assertSame('json', $sent['format']);
        $this->assertSame(32768, $sent['options']['num_ctx']);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);

        $this->assertSame('{"verb":"wait"}', $result);
    }

    public function testChatSurfacesOllamaErrorBody(): void
    {
        $client = $this->client(json_encode(['error' => 'model "gemma9" not found']));

        $err = null;
        $client->chat([['role' => 'user', 'content' => 'x']])->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $err);
        $this->assertStringContainsString('gemma9', $err->getMessage());
    }

    public function testChatRejectsNonJsonResponse(): void
    {
        $client = $this->client('<html>502 Bad Gateway</html>');

        $err = null;
        $client->chat([['role' => 'user', 'content' => 'x']])->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $err);
        $this->assertStringContainsString('non-JSON', $err->getMessage());
    }

    public function testChatRejectsWhenTransportFails(): void
    {
        $client = new OllamaClient('http://x', 'm', fn() => reject(new \RuntimeException('connection refused')));

        $err = null;
        $client->chat([['role' => 'user', 'content' => 'x']])->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });

        $this->assertSame('connection refused', $err->getMessage());
    }
}
