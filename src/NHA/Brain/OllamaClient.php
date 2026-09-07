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
 * Minimal async chat client for a local LLM server, driven by the bot's
 * ReactPHP event loop.
 *
 * Two wire protocols are supported, chosen from the base URL:
 *  - a bare origin (`http://host:11434`) uses Ollama's native `POST /api/chat`,
 *    which lets `num_ctx` / `think` be set explicitly;
 *  - a URL ending in `/v1` (`http://host:11434/v1`, exactly what OpenCode's
 *    `@ai-sdk/openai-compatible` provider uses) uses OpenAI-compatible
 *    `POST /v1/chat/completions`.
 *
 * The transport is injectable so the class can be unit-tested without a network;
 * the default wraps {@see \React\Http\Browser}.
 *
 * @link https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-chat-completion
 * @link https://github.com/ollama/ollama/blob/main/docs/openai.md
 *
 * @since 3.0.0
 */
final class OllamaClient
{
    /** @var callable(string, string, array<string,string>, string): PromiseInterface<string> */
    private $transport;

    /** Whether the base URL points at an OpenAI-compatible `/v1` endpoint. */
    private readonly bool $openai;

    /**
     * @param string             $baseUrl   `http://host:11434` for Ollama-native, or `http://host:11434/v1`
     *                                      for the OpenAI-compatible endpoint (as in OpenCode's config).
     * @param string             $model     Model tag, e.g. `gemma3:27b` or a custom `ollama create` name.
     * @param callable|null      $transport `fn(string $method, string $url, array $headers, string $body): PromiseInterface<string>`
     *                                      resolving with the raw response body. Defaults to a {@see Browser}.
     * @param float              $timeout   Per-request timeout in seconds (LLM replies are slow).
     * @param int                $numCtx    Context window to request, in tokens (Ollama-native mode only).
     * @param LoopInterface|null $loop      Only used to build the default transport.
     * @param bool|null          $think     `false` disables a thinking model's reasoning pass (faster);
     *                                      `null` (default) leaves it unset (Ollama-native mode only).
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
        $this->openai = (bool) preg_match('#/v\d+/?$#', $this->baseUrl);
        $this->transport = $transport ?? self::browserTransport($this->timeout, $loop);
    }

    /**
     * Sends a non-streaming chat completion and resolves with the assistant
     * message text.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param string|array|null                          $format      `"json"`, a JSON schema, or null for free text.
     * @param float                                      $temperature Sampling temperature.
     *
     * @return PromiseInterface<string>
     */
    public function chat(array $messages, string|array|null $format = 'json', float $temperature = 0.4): PromiseInterface
    {
        [$url, $body] = $this->openai
            ? $this->openaiRequest($messages, $format, $temperature)
            : $this->ollamaRequest($messages, $format, $temperature);

        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $openai = $this->openai;

        return ($this->transport)('POST', $url, ['Content-Type' => 'application/json'], $payload)
            ->then(static function (string $raw) use ($openai): string {
                $decoded = json_decode($raw, true);

                if (! is_array($decoded)) {
                    throw new \RuntimeException('LLM server returned a non-JSON response: ' . substr($raw, 0, 300));
                }
                if (isset($decoded['error'])) {
                    $err = $decoded['error'];
                    throw new \RuntimeException('LLM server error: ' . (is_array($err) ? ($err['message'] ?? json_encode($err)) : (string) $err));
                }

                $content = $openai
                    ? ($decoded['choices'][0]['message']['content'] ?? null)
                    : ($decoded['message']['content'] ?? null);

                if (! is_string($content) || $content === '') {
                    throw new \RuntimeException('LLM response carried no message content');
                }

                return $content;
            });
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function ollamaRequest(array $messages, string|array|null $format, float $temperature): array
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

        return [rtrim($this->baseUrl, '/') . '/api/chat', $body];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function openaiRequest(array $messages, string|array|null $format, float $temperature): array
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => $temperature,
        ];
        if ($this->think === false) {
            // Ollama's OpenAI-compatible endpoint honours reasoning_effort; "none"
            // is the OpenCode config's reasoningEffort for this model and skips the
            // (slow) reasoning pass, matching native mode's think=false.
            $body['reasoning_effort'] = 'none';
        }
        if ($format === 'json') {
            $body['response_format'] = ['type' => 'json_object'];
        } elseif (is_array($format)) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'schema' => $format]];
        }

        return [rtrim($this->baseUrl, '/') . '/chat/completions', $body];
    }

    /**
     * @return callable(string, string, array<string,string>, string): PromiseInterface<string>
     */
    private static function browserTransport(float $timeout, ?LoopInterface $loop): callable
    {
        // Keep error responses so the server's `{"error": ...}` body is readable.
        $browser = (new Browser($loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false);

        return static fn(string $method, string $url, array $headers, string $body): PromiseInterface
            => $browser->request($method, $url, $headers, $body)
                ->then(static fn(ResponseInterface $response): string => (string) $response->getBody());
    }
}
