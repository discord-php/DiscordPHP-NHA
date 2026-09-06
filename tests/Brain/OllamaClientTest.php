<?php

declare(strict_types=1);

use NHA\Brain\OllamaClient;

use function React\Promise\reject;
use function React\Promise\resolve;

class OllamaClientTest extends NHAUnitTestCase
{
    /** @var array{method:string,url:string,headers:array,body:string}|null */
    private ?array $lastRequest = null;

    private function client(string $responseBody, string $model = 'gemma3:27b', string $baseUrl = 'http://gemma-host:11434/'): OllamaClient
    {
        return new OllamaClient(
            $baseUrl,
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

    public function testChatUsesOpenAiEndpointWhenBaseUrlEndsInV1(): void
    {
        $client = $this->client(
            json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"verb":"mine"}']]]]),
            baseUrl: 'http://192.168.0.91:11434/v1',
        );

        $result = null;
        $client->chat([['role' => 'user', 'content' => 'hi']])->then(function ($c) use (&$result) {
            $result = $c;
        });

        $this->assertSame('http://192.168.0.91:11434/v1/chat/completions', $this->lastRequest['url']);

        $sent = json_decode($this->lastRequest['body'], true);
        $this->assertSame('gemma3:27b', $sent['model']);
        $this->assertFalse($sent['stream']);
        $this->assertSame(['type' => 'json_object'], $sent['response_format']);
        $this->assertArrayNotHasKey('format', $sent);
        $this->assertArrayNotHasKey('options', $sent);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);

        $this->assertSame('{"verb":"mine"}', $result);
    }

    public function testChatMapsThinkFalseToOpenAiReasoningEffortNone(): void
    {
        $client = new OllamaClient(
            'http://192.168.0.91:11434/v1',
            'gemma4-agent-32k',
            function (string $method, string $url, array $headers, string $body) {
                $this->lastRequest = compact('method', 'url', 'headers', 'body');

                return resolve(json_encode(['choices' => [['message' => ['content' => '{}']]]]));
            },
            timeout: 5.0,
            think: false,
        );

        $client->chat([['role' => 'user', 'content' => 'hi']]);

        $sent = json_decode($this->lastRequest['body'], true);
        $this->assertSame('none', $sent['reasoning_effort']);
    }

    public function testChatSurfacesOpenAiErrorBody(): void
    {
        $client = $this->client(
            json_encode(['error' => ['message' => 'model "gemma9" not found', 'type' => 'invalid_request_error']]),
            baseUrl: 'http://192.168.0.91:11434/v1',
        );

        $err = null;
        $client->chat([['role' => 'user', 'content' => 'x']])->then(null, function (\Throwable $e) use (&$err) {
            $err = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $err);
        $this->assertStringContainsString('gemma9', $err->getMessage());
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
