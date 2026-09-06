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

namespace NHA\Brain;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * Minimal async client for a running `ollama serve` instance (the native
 * `POST /api/chat` endpoint), driven by the bot's ReactPHP event loop.
 *
 * The transport is injectable so the class can be unit-tested without a
 * network; the default wraps {@see \React\Http\Browser}. `num_ctx` is sent
 * explicitly because `ollama serve` otherwise clamps the context window to its
 * own small default regardless of what the model supports.
 *
 * @link https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-chat-completion
 *
 * @since 0.1.0
 */
final class OllamaClient
{
    /** @var callable(string, string, array<string,string>, string): PromiseInterface<string> */
    private $transport;

    /**
     * @param string             $baseUrl   e.g. `http://gemma-host:11434` (no trailing `/api/...` or `/v1`).
     * @param string             $model     Ollama model tag, e.g. `gemma3:27b` or a custom `ollama create` name.
     * @param callable|null      $transport `fn(string $method, string $url, array $headers, string $body): PromiseInterface<string>`
     *                                      resolving with the raw response body. Defaults to a {@see Browser}.
     * @param float              $timeout   Per-request timeout in seconds (LLM replies are slow).
     * @param int                $numCtx    Context window to request (tokens).
     * @param LoopInterface|null $loop      Only used to build the default transport.
     * @param bool|null          $think     `false` disables a thinking model's reasoning pass (faster);
     *                                      `null` (default) leaves it unset so Ollama picks the model default.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        ?callable $transport = null,
        private readonly float $timeout = 120.0,
        private readonly int $numCtx = 32768,
        ?LoopInterface $loop = null,
        private readonly ?bool $think = null,
    ) {
        $this->transport = $transport ?? self::browserTransport($this->timeout, $loop);
    }

    /**
     * Sends a non-streaming chat completion and resolves with the assistant
     * message text (`message.content`).
     *
     * @param list<array{role: string, content: string}> $messages
     * @param string|array|null                          $format      `"json"`, a JSON schema, or null for free text.
     * @param float                                      $temperature Sampling temperature.
     *
     * @return PromiseInterface<string>
     */
    public function chat(array $messages, string|array|null $format = 'json', float $temperature = 0.4): PromiseInterface
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'format' => $format,
            'options' => ['num_ctx' => $this->numCtx, 'temperature' => $temperature],
        ];
        if ($this->think !== null) {
            $body['think'] = $this->think;
        }

        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $url = rtrim($this->baseUrl, '/') . '/api/chat';

        return ($this->transport)('POST', $url, ['Content-Type' => 'application/json'], $payload)
            ->then(static function (string $body): string {
                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    throw new \RuntimeException('Ollama returned a non-JSON response: ' . substr($body, 0, 300));
                }
                if (isset($decoded['error'])) {
                    throw new \RuntimeException('Ollama error: ' . (string) $decoded['error']);
                }

                $content = $decoded['message']['content'] ?? null;
                if (! is_string($content) || $content === '') {
                    throw new \RuntimeException('Ollama response had no message.content');
                }

                return $content;
            });
    }

    /**
     * @return callable(string, string, array<string,string>, string): PromiseInterface<string>
     */
    private static function browserTransport(float $timeout, ?LoopInterface $loop): callable
    {
        // Keep error responses so Ollama's `{"error": "..."}` body is readable.
        $browser = (new Browser($loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false);

        return static fn(string $method, string $url, array $headers, string $body): PromiseInterface
            => $browser->request($method, $url, $headers, $body)
                ->then(static fn(ResponseInterface $response): string => (string) $response->getBody());
    }
}
